# 🚫 Laravel Ban

Un package Laravel complet, performant et hautement configurable pour gérer les bans d'utilisateurs et d'adresses IP.

**Compatibilité :** Laravel 12 / 13, avec la version PHP requise par Laravel (PHP 8.2 minimum pour le package). Intégration optionnelle avec Livewire 3 ou 4.

---

## Table des matières

- [Installation](#installation)
- [Configuration](#configuration)
- [Mise en place du modèle](#mise-en-place-du-modèle)
- [Utilisation](#utilisation)
  - [Bannir un utilisateur](#bannir-un-utilisateur)
  - [Débannir](#débannir)
  - [Protection contre les bans en doublon](#protection-contre-les-bans-en-doublon)
  - [Vérifier un ban](#vérifier-un-ban)
  - [Bans par feature (scope)](#bans-par-feature-scope)
  - [syncBan — upsert idempotent](#syncban--upsert-idempotent)
- [Middleware](#middleware)
  - [CheckBanned](#checkbanned)
  - [BlockBannedIp](#blockbannedip)
- [Directives Blade](#directives-blade)
  - [@banned / @notBanned](#banned--notbanned)
  - [@bannedFrom](#bannedfrom)
  - [@bannedIp](#bannedip)
  - [@anyBan](#anyban)
  - [@allBanned](#allbanned)
- [Intégration Livewire](#intégration-livewire)
  - [Attribut #\[LockedByBan\]](#attribut-lockedbyban)
  - [Trait InterceptsBans](#trait-interceptsbans)
- [Commandes Artisan](#commandes-artisan)
  - [ban:user](#banuser)
  - [ban:config](#banconfig)
  - [ban:list](#banlist)
  - [ban:remove](#banremove)
- [Événements](#événements)
  - [Tableau des événements](#tableau-des-événements)
  - [Écoute des événements](#écoute-des-événements)
  - [Anti-récursion](#anti-récursion)
- [Cache multi-driver](#cache-multi-driver)
- [Modèles Eloquent](#modèles-eloquent)
  - [Ban](#ban)
  - [BanStatus — enum de statut](#banstatus--enum-de-statut)
  - [Pruning automatique](#pruning-automatique)
  - [Relation cause (polymorphique)](#relation-cause-polymorphique)
  - [Relations dynamiques](#relations-dynamiques)
  - [BannedIp](#bannedip)
- [Tests](#tests)

---

## Installation

```bash
composer require godrade/laravel-ban
```

Le package est auto-découvert via Laravel Package Auto-Discovery. Aucune inscription manuelle du service provider n'est nécessaire. Livewire n'est pas requis pour les bans, middleware, directives Blade ou commandes.

### Publier la configuration

```bash
php artisan ban:config
```

### Publier la configuration **et** les migrations

```bash
php artisan ban:config --migrations
```

### Lancer les migrations

Choisissez les noms des tables et la valeur de `ban.soft_delete` avant la première migration. Les migrations du package sont chargées automatiquement ; leur publication est optionnelle.

```bash
php artisan migrate
```

Deux tables sont créées :

| Table | Rôle |
|---|---|
| `bans` | Bans polymorphiques sur n'importe quel modèle |
| `banned_ips` | Bans d'adresses IP (IPv4 & IPv6) |

### Mise à jour d'une installation existante

Après la mise à jour du package, exécutez `php artisan migrate`. La migration `2026_10_01_000003_allow_multiple_bans_per_ip.php` remplace l'unicité de `ip_address` par un index simple et normalise les adresses IPv6 existantes. Plusieurs fonctionnalités ou enregistrements historiques peuvent ainsi partager la même IP.

Le rollback de cette migration conserve volontairement l'index non unique et les IP normalisées : rétablir l'unicité pourrait échouer sur les nouveaux enregistrements. Il ne supprime aucune donnée pour rendre ce retour arrière possible.

Le cache utilisateur change de format : les anciennes clés ne sont plus consultées et expirent suivant leur TTL. L'intégration Livewire renvoie désormais HTTP 403 pour une action interdite ; `checkBanLock()` est un helper protégé à appeler depuis le composant. `syncBan()` conserve les champs omis lors d'une mise à jour et n'efface que les champs explicitement passés à `null`.

---

## Configuration

Fichier publié : `config/ban.php`

```php
return [
    // Driver de cache : null = driver par défaut de l'app, 'redis', 'database', etc.
    'cache_driver' => env('BAN_CACHE_DRIVER', null),

    // Préfixe des clés de cache
    'cache_prefix' => env('BAN_CACHE_PREFIX', 'laravel_ban_'),

    // Durée de vie du cache en secondes (0 = désactivé)
    'cache_ttl' => env('BAN_CACHE_TTL', 3600),

    // Route nommée ou URL de redirection pour les utilisateurs bannis
    'redirect_url' => env('BAN_REDIRECT_URL', 'login'),

    // Noms des tables (personnalisables avant la première migration)
    'table_names' => [
        'bans'       => 'bans',
        'banned_ips' => 'banned_ips',
    ],

    // Alias du middleware CheckBanned
    'middleware_alias' => 'banned',

    // Interdire de bannir un modèle déjà banni (false = protection contre les doublons)
    'allow_overlapping_bans' => env('BAN_ALLOW_OVERLAPPING', false),

    // Relations Eloquent dynamiques injectées sur le modèle Ban
    'relations' => [],

    // Noms de relations réservés (ne peuvent pas être écrasés)
    'reserved_relations' => ['bannable', 'createdBy', 'cause'],

    // Suppression logique (choisir avant les migrations)
    'soft_delete' => true,

    // Valeurs de statut utilisées sur la colonne `status` de la table bans
    'statuses' => [
        'default' => 'active',
    ],
];
```

Variables `.env` disponibles :

```dotenv
BAN_CACHE_DRIVER=redis
BAN_CACHE_PREFIX=laravel_ban_
BAN_CACHE_TTL=3600
BAN_REDIRECT_URL=login
BAN_ALLOW_OVERLAPPING=false
```

Avec `soft_delete=true`, `delete()` conserve les lignes via `deleted_at`. Avec `false`, les migrations omettent cette colonne, les requêtes ne la consultent pas et `delete()` supprime définitivement les lignes. `restore()` retourne alors `false`. Cette option concerne `Ban` et `BannedIp` ; `unban()` conserve toujours l'historique en passant le statut à `cancelled`.

Ne changez pas `soft_delete` sur une base existante sans migration adaptée au schéma et à l'historique déjà présent.

---

## Mise en place du modèle

Ajoutez le trait `HasBans` et implémentez le contrat `Bannable` sur votre modèle :

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;

class User extends Authenticatable implements Bannable
{
    use HasBans;
}
```

> Le trait fonctionne sur **n'importe quel modèle Eloquent** (Post, Shop, Organisation…), pas seulement les utilisateurs.

---

## Utilisation

### Bannir un utilisateur

```php
// Ban permanent, sans raison
$user->ban();

// Ban permanent avec une raison
$user->ban(['reason' => 'Violation des CGU']);

// Ban temporaire (expire dans 7 jours)
$user->ban([
    'reason'     => 'Comportement abusif',
    'expired_at' => now()->addDays(7),
]);

// Ban posé par un admin (lien polymorphique created_by)
$user->ban([
    'reason'     => 'Spam',
    'created_by' => $admin,
]);

// Ban lié à une cause polymorphique (signalement, ticket, règle…)
$user->ban([
    'reason' => 'Contenu offensant',
    'cause'  => $report,
]);
```

`ban()` retourne l'instance `Ban` créée avec son statut immédiatement disponible, ou `null` en cas de réentrée pour le même modèle. Le modèle doit déjà être enregistré en base :

```php
$ban = $user->ban(['reason' => 'Test']);

// $ban est null uniquement en cas d'appel récursif (ex: listener qui rappelle ban())
echo $ban?->id;         // 42
echo $ban?->reason;     // "Test"
echo $ban?->expired_at; // null (permanent)
```

---

### Débannir

`unban()` ne supprime **pas** les enregistrements — il les **annule** en passant leur `status` à `BanStatus::CANCELLED`. L'historique des bans est ainsi préservé intégralement.

```php
// Annule tous les bans globaux actifs
$user->unban();

// Annule uniquement le ban sur la feature "comments"
$user->unban('comments');
```

---

### Protection contre les bans en doublon

Par défaut (`allow_overlapping_bans = false`), appeler `ban()` sur un modèle déjà banni (sur le même scope) lance une `AlreadyBannedException`.

```php
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;

try {
    $user->ban(['reason' => 'Spam']);
} catch (AlreadyBannedException $e) {
    // $e->existingBan → instance Ban du ban actif
    echo $e->getMessage();
    // "This model is already banned globally (permanent).
    //  Call unban() first or wait for the existing ban to expire."
}
```

Les mutations passent par une transaction et un verrou sur la ligne du modèle banni, y compris lorsqu'aucun ban n'existe encore. Deux appels concurrents à l'API du package ne doivent donc pas créer deux bans sur le même scope lorsque les doublons sont désactivés. Sur SQLite, le package acquiert un verrou d'écriture.

Interceptez `AlreadyBannedException` pour afficher le ban existant à l'administrateur. Une vérification préalable avec `isBanned()` ne remplace pas cette gestion : un autre processus peut bannir le modèle entre la vérification et l'écriture. Les insertions SQL directes ne passent pas par ce verrou.

**Inspecter le ban existant via l'exception :**
```php
$e->existingBan->reason;     // raison du ban actif
$e->existingBan->expired_at; // date d'expiration (null = permanent)
$e->existingBan->feature;    // scope (null = global)
```

Pour autoriser plusieurs bans actifs simultanément :
```dotenv
BAN_ALLOW_OVERLAPPING=true
```

---

### Vérifier un ban

```php
if ($user->isBanned()) {
    // L'utilisateur est banni globalement
}
```

---

### Bans par feature (scope)

Un **ban de feature** restreint l'accès à une fonctionnalité précise. Un ban global rend l'utilisateur banni de *toutes* les features.

```php
// Bannir uniquement du forum
$user->ban(['feature' => 'forum']);

// Bannir uniquement des commentaires pendant 24h
$user->ban([
    'feature'    => 'comments',
    'reason'     => 'Commentaires offensants',
    'expired_at' => now()->addHours(24),
]);

// Vérifications
$user->isBannedFrom('forum');    // true
$user->isBannedFrom('comments'); // true
$user->isBanned();               // false (pas de ban global)

// Débannir uniquement du forum
$user->unban('forum');
```

---

### syncBan — upsert idempotent

`syncBan()` est une alternative à `ban()` qui **ne lève jamais `AlreadyBannedException`**. Elle met à jour le ban actif existant sur le même scope ou en crée un nouveau, ce qui convient aux tâches planifiées, webhooks et imports.

```php
// Crée un ban si aucun ban actif n'existe
$user->syncBan(['reason' => 'Violation CGU', 'expired_at' => now()->addDays(7)]);

// Met à jour le ban actif existant (même scope global)
$user->syncBan(['reason' => 'Récidive', 'expired_at' => now()->addDays(30)]);

// Conserve la durée et l'auteur existants ; modifie seulement la raison
$user->syncBan(['reason' => 'Motif précisé']);

// null explicite efface le champ : ici le ban devient permanent
$user->syncBan(['expired_at' => null]);

// Scope comments, indépendant du scope global
$user->syncBan(['feature' => 'comments', 'reason' => 'Commentaires offensants']);
```

| Situation | Comportement |
|---|---|
| Aucun ban actif sur ce scope | Crée un nouveau ban + dispatche `ModelBanned` |
| Ban actif existant sur ce scope | Met à jour uniquement les champs fournis parmi `reason`, `expired_at`, `created_by`, `cause` |
| Champ omis / champ passé à `null` | Conserve sa valeur / efface sa valeur |
| Ban expiré sur ce scope | Crée un nouveau ban |
| `allow_overlapping_bans` peu importe | Jamais d'`AlreadyBannedException` |

---

## Middleware

### CheckBanned

Pour un utilisateur authentifié implémentant `Bannable`, vérifie le ban global ou le scope demandé. Les invités et modèles sans ce contrat passent ce middleware ; utilisez également `auth` pour imposer l'authentification.

Une requête qui attend du JSON reçoit HTTP 403 avec `{"message":"Your account has been suspended."}` (message traduisible). Une requête web est redirigée vers `ban.redirect_url`, qui accepte un nom de route, une URL absolue ou un chemin commençant par `/`. Si la route n'existe pas ou si la destination est la page courante, le middleware répond HTTP 403 pour éviter une erreur ou une boucle de redirection.

#### Protection globale

```php
// routes/web.php
Route::middleware(['auth', 'banned'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

#### Protection par feature

```php
Route::middleware(['auth', 'banned:comments'])->group(function () {
    Route::post('/comments', StoreCommentController::class);
});
```

#### Message flash

Si la requête possède une session, la redirection inclut un message flash `ban_error` :

```blade
@if (session('ban_error'))
    <div class="alert alert-danger">{{ session('ban_error') }}</div>
@endif
```

---

### BlockBannedIp

Bloque les requêtes provenant d'une adresse IP bannie avec une réponse **HTTP 403**.

Le middleware et `@bannedIp` partagent une mémoïsation attachée à l'objet HTTP `Request`. Une combinaison IP/scope déjà vérifiée ne relance pas de requête SQL tant que le résultat reste valide. Une expiration ou une mutation Eloquent le rend à nouveau vérifiable ; dans une transaction, les vérifications consultent directement la base.

Sans scope, `ban.ip` bloque toute IP ayant au moins un ban actif, même associé à une fonctionnalité. Avec `ban.ip:api`, seuls les bans globaux et les bans `api` sont pris en compte.

#### Protection globale (toutes les routes)

```php
// routes/web.php
Route::middleware('ban.ip')->group(function () {
    // toutes ces routes refuseront les IPs bannies
});
```

Pour l'appliquer globalement, ajoutez-le au callback `withMiddleware` de `bootstrap/app.php` :

```php
// bootstrap/app.php
use Godrade\LaravelBan\Middleware\BlockBannedIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(BlockBannedIp::class);
    })
    ->create();
```

#### Protection par feature

```php
Route::middleware('ban.ip:api')->group(function () {
    Route::apiResource('posts', PostController::class);
});
```

#### Bannir une IP

```php
use Godrade\LaravelBan\Models\BannedIp;

// Ban permanent
BannedIp::create([
    'ip_address' => '1.2.3.4',
    'reason'     => 'Attaque brute-force',
]);

// Ban temporaire
BannedIp::create([
    'ip_address' => '5.6.7.8',
    'reason'     => 'Scraping',
    'expired_at' => now()->addDays(7),
]);

// Ban scopé à une feature
BannedIp::create([
    'ip_address' => '9.10.11.12',
    'feature'    => 'api',
]);
```

#### Cycle de vie du cache IP

Aucune réinitialisation manuelle entre requêtes n'est nécessaire sous Octane ou Swoole. Les résultats ne sont pas réutilisés d'un objet `Request` à l'autre.

Les créations, mises à jour, suppressions et restaurations d'instances `BannedIp` invalident la mémoïsation. Après une requête SQL en masse qui contourne les événements Eloquent, utilisez `BlockBannedIp::flushCache()` si vous revérifiez une IP dans la même requête.

---

## Directives Blade

Les directives de ban utilisateur acceptent un modèle `Bannable` optionnel. Si omis, l'utilisateur connecté (`auth()->user()`) est utilisé. `@bannedIp` prend une adresse IP et un scope.

### `@banned` / `@notBanned`

```
@banned($model = null)
@notBanned($model = null)
```

```blade
{{-- Utilisateur connecté --}}
@banned
    <p class="text-red-500">Votre compte est suspendu.</p>
@endbanned

@notBanned
    <a href="/post">Créer un article</a>
@endnotBanned

{{-- Modèle arbitraire --}}
@banned($shop)
    <p>Cette boutique est suspendue.</p>
@endbanned
```

### `@bannedFrom`

```
@bannedFrom($feature, $model = null)
```

```blade
{{-- Utilisateur connecté --}}
@bannedFrom('comments')
    <p>Vous ne pouvez pas commenter pour le moment.</p>
@else
    <form action="/comments" method="POST">
        {{-- formulaire de commentaire --}}
    </form>
@endbannedFrom

{{-- Modèle arbitraire --}}
@bannedFrom('api', $apiUser)
    <p>Cet utilisateur est banni de l'API.</p>
@endbannedFrom
```

### `@bannedIp`

Vérifie si l'adresse IP courante (ou une IP explicite) est bannie.

```
@bannedIp($ip = null, $feature = null)
```

```blade
{{-- IP de la requête courante --}}
@bannedIp
    <p class="text-red-500">Votre adresse IP est bloquée.</p>
@endbannedIp

{{-- Feature-scoped --}}
@bannedIp(null, 'api')
    <p>Votre IP est bloquée pour l'accès à l'API.</p>
@endbannedIp

{{-- IP explicite --}}
@bannedIp('1.2.3.4')
    <p>Cette adresse IP est bannie.</p>
@endbannedIp
```

Le résultat est partagé avec `BlockBannedIp` pendant la requête courante, par IP et scope. Les expirations sont prises en compte. Après une modification en masse, `Godrade\LaravelBan\Blade\BanDirectives::flushIpCache()` invalide cette mémoïsation commune ; aucun reset Octane n'est nécessaire entre les requêtes.

---

### `@anyBan`

Affiche le bloc si le modèle est banni d'**au moins une** des features passées (logique **OR**).

```
@anyBan('feature1', 'feature2', ..., $model = null)
```

```blade
{{-- Banni du forum OU des commentaires --}}
@anyBan('forum', 'comments')
    <p>Accès restreint à plusieurs sections.</p>
@endanyBan

{{-- Sans feature → vérifie le ban global --}}
@anyBan
    <p>Votre compte est suspendu.</p>
@endanyBan

{{-- Modèle arbitraire --}}
@anyBan('posts', 'comments', $shop)
    <p>Cette boutique est restreinte.</p>
@endanyBan
```

---

### `@allBanned`

Affiche le bloc uniquement si le modèle est banni de **toutes** les features passées (logique **AND**).

```
@allBanned('feature1', 'feature2', ..., $model = null)
```

```blade
{{-- Banni du forum ET des commentaires --}}
@allBanned('forum', 'comments')
    <p>Vous êtes banni de toutes les sections de discussion.</p>
@endallBanned

{{-- Sans feature → vérifie le ban global --}}
@allBanned
    <p>Votre compte est suspendu.</p>
@endallBanned
```

**Règles communes à `@anyBan` et `@allBanned` :**

| Situation | Comportement |
|---|---|
| Aucune feature passée | Vérifie `isBanned()` (ban global) |
| Dernier argument implémente `Bannable` | Utilisé comme modèle cible |
| Dernier argument absent ou non `Bannable` | Utilise `auth()->user()` |
| Utilisateur non authentifié | Retourne `false` |

---

## Intégration Livewire

L'intégration est **optionnelle et compatible uniquement avec Livewire 3 ou 4**. Si votre application utilise Livewire, installez une version supportée :

```bash
composer require 'livewire/livewire:^3.0|^4.0'
```

Le service provider enregistre automatiquement le contrôle lorsque Livewire est disponible. Aucune interception manuelle des actions n'est nécessaire.

### Attribut `#[LockedByBan]`

| Cible | Comportement |
|---|---|
| Méthode | Protège cette action et les événements qui l'appellent |
| Classe | Protège les actions distantes du composant |
| Sans `feature` | Vérifie le ban global |
| `feature: 'comments'` | Vérifie le ban global et celui de `comments` |

Un attribut de méthode prend la priorité sur l'attribut de classe pour le scope. Les méthodes héritées conservent leur attribut ; pour une classe sans attribut propre, le package utilise celui du parent le plus proche.

### Trait `InterceptsBans`

Ajoutez le trait et les attributs au composant :

```php
namespace App\Livewire;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Traits\InterceptsBans;
use Livewire\Attributes\On;
use Livewire\Component;

#[LockedByBan(feature: 'forum')]
class ForumComponent extends Component
{
    use InterceptsBans;

    public function postThread(): void
    {
        // Protégé par le scope forum de la classe.
    }

    #[On('comment:post')]
    #[LockedByBan(feature: 'comments')]
    public function postComment(): void
    {
        // Protégé par comments, y compris lors d'un événement comment:post.
    }

    public function render(): string
    {
        return '<div><button wire:click="postThread">Publier</button></div>';
    }
}
```

#### Comportement lors du blocage

Le package interrompt la requête avec **HTTP 403 avant l'exécution de l'action ou du listener** et flashe `ban_error` en session. La réponse d'erreur ne provoque pas un nouveau rendu du composant ; gérez-la avec le traitement des erreurs Livewire de votre application. Le message flash reste utilisable sur une page rendue ensuite.

Le trait n'ajoute aucun `callMethod()` public. Les méthodes privées et le helper protégé `checkBanLock()` ne deviennent pas des actions distantes.

#### Appels internes et hooks de cycle de vie

Les contrôles automatiques concernent les actions reçues de Livewire et les listeners, qu'ils soient déclarés avec `#[On]` ou `$listeners`. Les appels PHP internes et les hooks tels que `mount()`, `boot()` ou `updated()` ne passent pas par ce contrôle. Les changements de propriétés via `wire:model` ne constituent pas des actions protégées par cet attribut.

Pour un appel interne, vérifiez le lock avant l'effet à protéger et retournez si le helper indique un blocage :

```php
namespace App\Livewire;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Traits\InterceptsBans;
use Livewire\Component;

class CommentComponent extends Component
{
    use InterceptsBans;

    public string $draft = '';

    public function updatedDraft(): void
    {
        if ($this->checkBanLock('saveDraft')) {
            return;
        }

        $this->saveDraft();
    }

    #[LockedByBan(feature: 'comments')]
    protected function saveDraft(): void
    {
        // Enregistrer le brouillon après le contrôle.
    }

    public function render(): string
    {
        return '<div><input wire:model.live="draft"></div>';
    }
}
```

#### Règles d'interception

| Situation | Résultat |
|---|---|
| Composant sans `InterceptsBans` | Aucun contrôle automatique |
| Pas d'attribut méthode ni classe | Action autorisée par le package |
| Invité ou utilisateur sans contrat `Bannable` | Action autorisée par le package |
| Ban global et action verrouillée | HTTP 403 |
| Ban de feature X et lock sur X | HTTP 403 |
| Ban de feature X et lock sur Y | Action autorisée par le package |

Ces locks ne remplacent pas les règles d'authentification et d'autorisation de l'application.

---

## Commandes Artisan

### `ban:user`

Bannit un modèle depuis le terminal.

```bash
# Ban permanent
php artisan ban:user 42

# Ban temporaire (1440 minutes = 24h)
php artisan ban:user 42 --duration=1440 --reason="Violation CGU"

# Ban scopé à une feature
php artisan ban:user 42 --feature=comments --reason="Commentaires offensants"

# Sur un modèle autre que User
php artisan ban:user 5 --model="App\Models\Shop" --reason="Fraude"
```

**Options :**

| Option | Description |
|---|---|
| `id` | *(requis)* Clé primaire du modèle |
| `--model` | Classe du modèle (défaut : `App\Models\User`) |
| `--duration` | Nombre entier strictement positif de minutes (omis = permanent) |
| `--reason` | Raison lisible du ban |
| `--feature` | Scope la restriction à une feature |

Une durée telle que `1h`, `0` ou `-10` est refusée : la commande retourne un code d'échec sans créer de ban. Un doublon actif retourne également une erreur lisible. Le modèle doit implémenter `Bannable`.

---

### `ban:config`

Publie les fichiers du package.

```bash
# Publier uniquement la configuration
php artisan ban:config

# Publier la configuration et les migrations
php artisan ban:config --migrations
```

---

### `ban:list`

Affiche les bans actifs par défaut. `--status=cancelled` affiche les bans annulés, quelle que soit leur expiration, sans devoir ajouter `--expired`. Les enregistrements supprimés logiquement restent exclus.

```bash
# Tous les bans actifs
php artisan ban:list

# Filtrés par feature
php artisan ban:list --feature=comments

# Inclure tous les statuts et les bans expirés
php artisan ban:list --expired

# Uniquement le statut active, y compris les bans déjà expirés
php artisan ban:list --status=active --expired

# Filtrés par type de modèle
php artisan ban:list --model="App\Models\User"

# Filtrés par statut
php artisan ban:list --status=active
php artisan ban:list --status=cancelled
```

**Options :**

| Option | Description |
|---|---|
| `--feature=` | Filtre par feature (scope) |
| `--expired` | Inclut tous les statuts et expirations, sauf filtre `--status` explicite |
| `--model=` | Classe Eloquent bannable ou alias enregistré dans la morph map |
| `--status=active\|cancelled` | Filtre par statut du ban |

**Colonnes affichées :** `ID · Bannable Type · Bannable ID · Feature · Reason · Status · Expires at · Created at`

---

### `ban:remove`

Supprime un ban par son identifiant technique.

```bash
# Soft-delete avec confirmation interactive
php artisan ban:remove 42

# Suppression permanente (force)
php artisan ban:remove 42 --force

# Skip la confirmation (scripts CI/CD)
php artisan ban:remove 42 --no-confirm
```

**Options :**

| Option | Description |
|---|---|
| `id` | *(requis)* Identifiant du ban |
| `--force` | Suppression permanente (ignore le soft-delete) |
| `--no-confirm` | Ne demande pas de confirmation |

Le cache du modèle banni est automatiquement invalidé après la suppression, y compris lorsque `bannable_type` contient un alias de morph map. Avec `soft_delete=false`, la suppression est définitive même sans `--force`.

---

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

### Payload

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

---

## Cache multi-driver

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

---

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
| `active` | Ban actif, en cours d'application |
| `cancelled` | Ban annulé manuellement via `unban()` |

---

### Pruning automatique

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

`BannedIp` supporte le pruning avec sa classe explicitement passée à `model:prune`, comme dans la planification ci-dessus. Une même adresse peut porter plusieurs bans et conserver son historique après suppression logique.

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

---

## Tests

```bash
composer install
composer check
```

`composer check` lance le contrôle de formatage Pint, l'analyse statique Larastan/PHPStan puis les tests Pest. Les commandes peuvent être lancées séparément avec `composer lint`, `composer analyse` et `composer test`. `composer format` applique le formatage.

| Fichier | Couverture |
|---|---|
| `tests/Feature/CoreBanTest.php` | API des bans, statuts, événements et réentrées |
| `tests/Feature/BanCacheLifecycleTest.php` | Cache activé, expirations, scopes, mutations directes, transactions et invalidation |
| `tests/Feature/PackageMigrationsTest.php` | Migrations réelles et configuration de suppression logique |
| `tests/Feature/IpBanRegressionTest.php` | Mémoïsation par requête, IPv6, bans IP multiples et migration de mise à jour |
| `tests/Feature/BlockBannedIpTest.php` | Middleware IP, scopes et expiration |
| `tests/Feature/BladeDirectivesTest.php` | Directives Blade et contrats des modèles |
| `tests/Feature/DynamicRelationsTest.php` | Relations dynamiques, clés déduites et relation `cause` |
| `tests/Feature/InterceptsBansTest.php` | Vrais composants Livewire, actions, listeners, héritage et visibilité |
| `tests/Feature/HttpAndConsoleTest.php` | Réponses JSON/web, commandes, filtres et validation |
| `tests/Feature/MaintenanceTest.php` | Listing, suppression, synchronisation et pruning |
| `tests/Feature/RelationalDatabaseTest.php` | Migrations, transactions, cache et concurrence sur MySQL/PostgreSQL ; activation explicite |

### Tests MySQL et PostgreSQL

La suite utilise SQLite par défaut. Pour lancer les tests relationnels, préparez une **base dédiée et jetable** et installez l'extension PHP `pdo_mysql` ou `pdo_pgsql`. Ces tests suppriment et recréent les tables `integration_users`, `integration_bans` et `integration_banned_ips` au démarrage, puis les suppriment à la fin : ne les dirigez pas vers une base applicative contenant des données à conserver.

Exemple MySQL, avec une base `laravel_ban_test` déjà créée et les identifiants de votre serveur de test :

```bash
BAN_TEST_DB_DRIVER=mysql \
BAN_TEST_DB_HOST=127.0.0.1 \
BAN_TEST_DB_PORT=3306 \
BAN_TEST_DB_DATABASE=laravel_ban_test \
BAN_TEST_DB_USERNAME=root \
BAN_TEST_DB_PASSWORD=testing \
./vendor/bin/pest tests/Feature/RelationalDatabaseTest.php
```

Exemple PostgreSQL :

```bash
BAN_TEST_DB_DRIVER=pgsql \
BAN_TEST_DB_HOST=127.0.0.1 \
BAN_TEST_DB_PORT=5432 \
BAN_TEST_DB_DATABASE=laravel_ban_test \
BAN_TEST_DB_USERNAME=postgres \
BAN_TEST_DB_PASSWORD=testing \
./vendor/bin/pest tests/Feature/RelationalDatabaseTest.php
```

Sans `BAN_TEST_DB_DRIVER`, ces tests sont ignorés. Les valeurs supportées sont `mysql` et `pgsql`.

### Intégration continue

Le workflow configure une matrice Laravel 12 / 13 avec Livewire 3 / 4, un job du cœur du package sans Livewire installé, et des jobs de base de données utilisant les services MySQL 8 et PostgreSQL 16. Le job `quality` vérifie Composer, le formatage, l'analyse statique et les avis de sécurité. Le blocage de sécurité Composer reste actif dans tous les jobs.

---

## Licence

MIT — [Godrade](https://github.com/godrade)
