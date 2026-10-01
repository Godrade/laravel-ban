# Comportements avancés

[← Retour au README](../README.md) · [Référence d’utilisation](reference.md) · [Livewire](livewire.md)

- [Mise à jour](#mise-à-jour)
- [Événements et transactions](#événements)
- [Cache et invalidation](#cache)
- [Modèles Eloquent et statuts](#modèles-eloquent)
- [Nettoyage des bans expirés](#nettoyage-des-bans-expirés)
- [Associer une cause au ban](#relation-cause-polymorphique)
- [Relations dynamiques](#relations-dynamiques)
- [Modèle BannedIp](#bannedip)

## Mise à jour

Ces changements appartiennent à la version en préparation, après **1.0.0-beta.1 (Bêta 1)**. Voir le [changelog](../CHANGELOG.md).

Après la mise à jour du package, exécutez `php artisan migrate`. La migration `2026_10_01_000003_allow_multiple_bans_per_ip.php` remplace l'unicité de `ip_address` par un index simple et normalise les adresses IPv6 existantes. Plusieurs fonctionnalités ou enregistrements historiques peuvent ainsi partager la même IP.

Le rollback de cette migration conserve volontairement l'index non unique et les IP normalisées : rétablir l'unicité pourrait échouer sur les nouveaux enregistrements. Il ne supprime aucune donnée pour rendre ce retour arrière possible.

Le cache utilisateur change de format : les anciennes clés ne sont plus consultées et expirent suivant leur TTL. L'intégration Livewire renvoie désormais HTTP 403 pour une action interdite ; `checkBanLock()` est un helper protégé à appeler depuis le composant. `syncBan()` conserve les champs omis lors d'une mise à jour et n'efface que les champs explicitement passés à `null`.

## Événements

### Tableau des événements

| Événement | Déclenché quand |
|---|---|
| `Godrade\LaravelBan\Events\ModelBanned` | `ban()` crée un ban · `syncBan()` crée un nouveau ban |
| `Godrade\LaravelBan\Events\ModelUnbanned` | `unban()` annule des bans actifs (status → CANCELLED) |
| `Godrade\LaravelBan\Events\ModelBanUpdated` | `syncBan()` met à jour un ban actif existant |

Les événements du package sont émis **après le commit de la transaction**, avec le cache invalidé avant leur émission. Dans une transaction englobante, ils attendent son commit ; un rollback ne les émet pas. Les listeners voient le statut du ban immédiatement disponible. Une modification directe d'une instance `Ban` invalide le cache via les événements Eloquent, sans émettre ces événements métier.

### Écoute des événements

Exemple dans `app/Providers/AppServiceProvider.php` :

```php
namespace App\Providers;

use Godrade\LaravelBan\Events\ModelBanned;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ModelBanned::class, function (ModelBanned $event): void {
            Log::info('Ban créé', [
                'ban_id' => $event->ban->id,
                'feature' => $event->feature,
                'status' => $event->ban->status->value,
            ]);
        });
    }
}
```

Enregistrez `ModelBanUpdated` et `ModelUnbanned` de la même manière pour écouter les mises à jour et annulations.

### Contenu des événements

```php
// ModelBanned
$event->bannable; // modèle banni  (ex: App\Models\User)
$event->ban;      // instance Ban créée
$event->feature;  // feature ciblée (null = global) — raccourci vers $event->ban->feature

// ModelBanUpdated
$event->bannable;            // modèle dont le ban a été mis à jour
$event->ban;                 // instance Ban après la mise à jour
$event->originalAttributes;  // attributs Eloquent avant la mise à jour

// ModelUnbanned
$event->bannable; // modèle débanni
$event->feature;  // feature ciblée (null = global)
```

### Anti-récursion

`HasBans` protège les réentrées selon l'identité en base du modèle (connexion, table, clé primaire), y compris lorsqu'un listener recharge une autre instance du même modèle. Un appel récursif à `ban()` ou `syncBan()` retourne `null` ; `unban()` ne lance pas de nouvelle mutation. Le verrou est libéré dans un bloc `finally`, même en cas d'exception.

Cette protection des listeners complète le verrou transactionnel utilisé entre processus. Elle ne remplace pas une politique d'autorisation de vos écritures.

## Cache

Le cache conserve une réponse par **scope exact**. `isBanned()` consulte le scope global (`null`) ; `isBannedFrom('comments')` combine le résultat global avec celui de `comments`. Un changement de ban global prend donc effet pour chaque fonctionnalité sans conserver de résultat combiné périmé. La feature littérale `global` possède son propre scope distinct.

La durée de validité est limitée par `ban.cache_ttl` **et par la prochaine expiration des bans concernés**. Une expiration ne nécessite pas une tâche planifiée pour être prise en compte.

Les vérifications de bans utilisateur et IP lisent la connexion d'écriture (`useWritePdo()`). Avec une configuration de lecture sur réplique, un retard de réplication ne remplit donc pas le cache avec l'état antérieur à une mutation validée sur la base primaire.

### Choisir un driver

```dotenv
BAN_CACHE_DRIVER=redis
BAN_CACHE_TTL=3600
```

Omettez `BAN_CACHE_DRIVER` pour utiliser le store par défaut de l'application. Mettez `BAN_CACHE_TTL=0` pour désactiver ce cache.

### Invalidation et transactions

`ban()`, `syncBan()` et `unban()`, ainsi que les sauvegardes, suppressions et restaurations d'instances `Ban`, invalident les scopes concernés. Un changement de propriétaire ou de feature invalide l'ancien et le nouveau scope. Le package change la génération de cache pour qu'une lecture commencée avant l'invalidation ne puisse pas remettre en service un ancien résultat.

À l'intérieur d'une transaction, les vérifications consultent la base sans lire ni alimenter le cache partagé. Les mutations invalident immédiatement le cache, puis à nouveau après commit pour écarter les résultats rechargés par une autre connexion entre-temps. Un rollback n'alimente pas le cache avec des données annulées.

Les mises à jour en masse et le SQL brut contournent les événements Eloquent. Invalidez explicitement chaque modèle/scope affecté :

```php
use App\Models\User;
use Godrade\LaravelBan\Enums\BanStatus;

$user = User::findOrFail(42);

$user->getConnection()->transaction(function () use ($user): void {
    $user->bans()->where('feature', 'comments')->update([
        'status' => BanStatus::CANCELLED->value,
    ]);

    $user->flushBanCache('comments');
});

// Sans argument, invalide uniquement le scope global.
$user->flushBanCache();
```

### Format des clés

```text
<prefix>v2_<sha256>:version
<prefix>v2_<sha256>:<generation>
```

Le préfixe par défaut est `laravel_ban_`. Le hash SHA-256 porte sur la sérialisation PHP de la connexion, du nom de base, du préfixe de table, de la table des bans, du type polymorphique, de l'identifiant converti en chaîne et du scope (`null` ou nom de feature). La première clé contient une génération aléatoire ; la seconde contient le résultat et sa date limite de validité. Utilisez `flushBanCache()` pour invalider un résultat au lieu de reconstruire ces clés.

## Modèles Eloquent

### `Ban`

```php
use Godrade\LaravelBan\Enums\BanStatus;
use Godrade\LaravelBan\Models\Ban;

Ban::active()->get();                         // status=ACTIVE et non expiré
Ban::active()->forFeature('comments')->get(); // actifs sur une feature
Ban::active()->global()->get();               // bans globaux actifs
Ban::cancelled()->get();                      // annulés via unban()

// Filtrer par statut (enum ou string)
Ban::withStatus(BanStatus::CANCELLED)->get();
Ban::withStatus('cancelled')->get();

$ban->status;     // BanStatus::ACTIVE | BanStatus::CANCELLED
$ban->bannable;   // modèle banni  (ex: App\Models\User)
$ban->createdBy;  // auteur du ban (ex: App\Models\Admin)
$ban->cause;      // cause liée    (ex: App\Models\Report)
$ban->isActive(); // true si status=ACTIVE et non expiré
```

---

### `BanStatus` — enum de statut

```php
use Godrade\LaravelBan\Enums\BanStatus;

BanStatus::ACTIVE->value;    // 'active'    — ban en vigueur
BanStatus::CANCELLED->value; // 'cancelled' — annulé via unban()

// Note : l'état "expiré" est calculé via expired_at, pas stocké en base.
```

| Valeur | Description |
|---|---|
| `active` | Ban non annulé ; il ne bloque que si son expiration n’est pas dépassée |
| `cancelled` | Ban annulé manuellement via `unban()` |

---

### Nettoyage des bans expirés

Les modèles `Ban` et `BannedIp` utilisent `MassPrunable` pour supprimer définitivement les bans expirés depuis plus de **30 jours**. Indiquez les deux classes du package explicitement :

```bash
php artisan model:prune \
  --model='Godrade\LaravelBan\Models\Ban' \
  --model='Godrade\LaravelBan\Models\BannedIp'
```

Planifiez cette commande dans `routes/console.php` :

```php
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Models\BannedIp;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', [
    '--model' => [Ban::class, BannedIp::class],
])->daily();
```

L'application doit exécuter le scheduler Laravel. Les bans permanents (`expired_at = null`) et ceux expirés depuis moins de 30 jours sont préservés. Le pruning en masse n'émet pas d'événement Eloquent par enregistrement.

---

### Relation cause (polymorphique)

La relation `cause` lie un ban à **n'importe quel modèle déclencheur** (signalement, ticket de support, règle de modération…).

| Colonne | Type | Rôle |
|---|---|---|
| `cause_type` | `string\|null` | Classe Eloquent de la cause |
| `cause_id` | `unsignedBigInteger\|null` | Clé primaire de la cause |

```php
use App\Models\Report;
use App\Models\User;
use Godrade\LaravelBan\Models\Ban;

$user = User::findOrFail(42);
$report = Report::findOrFail(17);

// Créer un ban lié à un signalement
$ban = $user->ban([
    'reason' => 'Contenu offensant',
    'cause'  => $report,
]);

$ban->cause;      // instance App\Models\Report
$ban->cause_type; // "App\Models\Report"
$ban->cause_id;   // 17

Ban::with('cause')->active()->get();
```

> `cause` est réservé et ne peut pas être écrasé par les relations dynamiques.

---

### Relations dynamiques

Injectez des relations Eloquent supplémentaires sur `Ban` depuis `config/ban.php` sans modifier le modèle.

```php
// Bloc à ajouter à la configuration config/ban.php
return ['relations' => [
    'preset' => [
        'type'        => 'belongsTo',
        'related'     => \App\Models\BanPreset::class,
        'foreign_key' => 'preset_id',
    ],
    'ticket' => [
        'type'    => 'belongsTo',
        'related' => \App\Models\SupportTicket::class,
    ],
]];
```

```php
$ban->preset;                          // instance BanPreset
Ban::with(['preset', 'ticket'])->get();
```

Les types supportés sont `belongsTo`, `hasOne` et `hasMany`. Pour `belongsTo`, une clé étrangère omise est déduite du nom de relation (`ticket_id` dans cet exemple), et `owner_key` peut personnaliser la clé cible. Pour `hasOne` et `hasMany`, utilisez `local_key` pour personnaliser la clé locale. Ajoutez les colonnes nécessaires dans les migrations de votre application : cette configuration ne modifie pas le schéma.

**Règles de validation au démarrage :**

| Situation | Comportement |
|---|---|
| Nom réservé (`bannable`, `createdBy`, `cause`) | `Log::warning` + relation ignorée |
| Définition invalide, classe non Eloquent ou type non supporté | `Log::error` + relation ignorée |
| Configuration valide | Relation injectée via `resolveRelationUsing` |

---

### `BannedIp`

`BannedIp` supporte le pruning avec sa classe explicitement passée à `model:prune`, comme dans la section sur le nettoyage des bans expirés. Une même adresse peut porter plusieurs bans et conserver son historique après suppression logique.

La colonne `feature` est limitée à **50 caractères**, comme dans la table `bans`. Les adresses IPv6 valides sont normalisées à l’écriture et lors de `forIp()` ; les écritures SQL brutes doivent respecter cette normalisation.

`BannedIp` expose également une relation polymorphique `createdBy()` (`created_by_type` / `created_by_id`) pour enregistrer l'auteur du ban IP.

```php
use App\Models\User;
use Godrade\LaravelBan\Models\BannedIp;

$admin = User::findOrFail(1);

$ban = BannedIp::create([
    'ip_address' => '192.168.1.100',
    'reason'     => 'Attaque brute-force',
    'expired_at' => now()->addDays(30),
    'created_by' => $admin,   // relation polymorphique optionnelle
]);

$ban->createdBy; // L'administrateur associé

BannedIp::active()->forIp('192.168.1.100')->exists();
BannedIp::active()->forIp('192.168.1.100')->forFeature('api')->exists();
```

Pour lever ce ban IP, supprimez l’instance concernée :

```php
$ban->delete();
```

Avec `soft_delete=true`, l’enregistrement reste dans l’historique. D’autres bans actifs de la même IP peuvent continuer à la bloquer. `BannedIp` n’utilise pas le statut `cancelled` des bans de modèles.
