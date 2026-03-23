# 🚫 Laravel Ban

Un package Laravel complet, performant et hautement configurable pour gérer les bans d'utilisateurs et d'adresses IP.

**Compatibilité :** PHP 8.2+ · Laravel 10 / 11 / 12 · PSR-4 · TALL Stack ready

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

Le package est auto-découvert via Laravel Package Auto-Discovery. Aucune inscription manuelle dans `config/app.php` n'est nécessaire.

### Publier la configuration

```bash
php artisan ban:config
```

### Publier la configuration **et** les migrations

```bash
php artisan ban:config --migrations
```

### Lancer les migrations

```bash
php artisan migrate
```

Deux tables sont créées :

| Table | Rôle |
|---|---|
| `bans` | Bans polymorphiques sur n'importe quel modèle |
| `banned_ips` | Bans d'adresses IP (IPv4 & IPv6) |

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

    // Conserver un historique des bans (soft delete)
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

`ban()` retourne l'instance `Ban` créée, ou `null` si la méthode a été appelée récursivement (le verrou statique était déjà actif pour cette instance) :

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

**Workflow conseillé :**
```php
if (! $user->isBanned()) {
    $user->ban(['reason' => 'Violation CGU']);
}

// Ou attraper l'exception pour afficher un message à l'admin
try {
    $user->ban(['reason' => 'Récidive']);
} catch (AlreadyBannedException $e) {
    return back()->with('error', "Cet utilisateur est déjà banni. Ban actif : #{$e->existingBan->id}");
}
```

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

`syncBan()` est une alternative à `ban()` qui **ne lève jamais `AlreadyBannedException`**. Elle met à jour le ban actif existant sur le même scope ou en crée un nouveau, ce qui la rend parfaite pour les tâches planifiées, les webhooks et les imports.

```php
// Crée un ban si aucun ban actif n'existe
$user->syncBan(['reason' => 'Violation CGU', 'expired_at' => now()->addDays(7)]);

// Met à jour le ban actif existant (même scope global)
$user->syncBan(['reason' => 'Récidive', 'expired_at' => now()->addDays(30)]);

// Feature-scoped — indépendant du scope global
$user->syncBan(['feature' => 'comments', 'reason' => 'Commentaires offensants']);
```

| Situation | Comportement |
|---|---|
| Aucun ban actif sur ce scope | Crée un nouveau ban + dispatche `ModelBanned` |
| Ban actif existant sur ce scope | Met à jour `reason`, `expired_at`, `created_by` |
| Ban expiré sur ce scope | Crée un nouveau ban |
| `allow_overlapping_bans` peu importe | Jamais d'`AlreadyBannedException` |

---

## Middleware

### CheckBanned

Redirige automatiquement les utilisateurs bannis vers l'URL configurée dans `ban.redirect_url`.

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

La redirection inclut un message flash `ban_error` :

```blade
@if (session('ban_error'))
    <div class="alert alert-danger">{{ session('ban_error') }}</div>
@endif
```

---

### BlockBannedIp

Bloque les requêtes provenant d'une adresse IP bannie avec une réponse **HTTP 403**.

Le résultat de la vérification est **mémoïsé** dans une propriété statique : une seule requête SQL est exécutée par IP et par cycle de requête, même si le middleware est appelé plusieurs fois dans la même pipeline.

#### Protection globale (toutes les routes)

```php
// routes/web.php
Route::middleware('ban.ip')->group(function () {
    // toutes ces routes refuseront les IPs bannies
});
```

Ou dans `app/Http/Kernel.php` pour l'appliquer globalement :

```php
protected $middleware = [
    \Godrade\LaravelBan\Middleware\BlockBannedIp::class,
    // ...
];
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

#### Reset du cache (Laravel Octane)

En environnement long-running (Octane, Swoole), réinitialisez le cache statique entre chaque requête :

```php
// AppServiceProvider::boot()
$this->app->make(\Illuminate\Contracts\Http\Kernel::class)
    ->pushMiddleware(function ($request, $next) {
        \Godrade\LaravelBan\Middleware\BlockBannedIp::flushCache();
        return $next($request);
    });
```

---

## Directives Blade

Toutes les directives acceptent un modèle optionnel. Si omis, l'utilisateur connecté (`auth()->user()`) est utilisé.

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

> **Performance :** le résultat de chaque combinaison `{ip}:{feature}` est mémoïsé pour la durée de la requête. Une seule requête SQL est exécutée par IP/feature, même si la directive est utilisée plusieurs fois dans la même vue.

#### Reset du cache IP (Laravel Octane)

```php
// AppServiceProvider::boot()
\Godrade\LaravelBan\Blade\BanDirectives::flushIpCache();
```

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

Le package fournit un système de **verrouillage déclaratif** pour les composants Livewire via un attribut PHP 8.2 et un trait d'interception.

### Attribut `#[LockedByBan]`

L'attribut `#[LockedByBan]` peut être placé sur une **méthode** ou sur une **classe** entière.

```php
use Godrade\LaravelBan\Attributes\LockedByBan;
```

| Cible | Comportement |
|---|---|
| Méthode | Seule cette méthode est bloquée si l'utilisateur est banni |
| Classe | Toutes les méthodes du composant sont bloquées |
| `feature: 'xxx'` | Le blocage ne s'active que si l'utilisateur est banni de cette feature |

**Priorité :** un attribut méthode prend toujours le dessus sur un attribut classe (pour le scope feature).

---

### Trait `InterceptsBans`

Ajoutez ce trait à votre composant Livewire pour activer l'interception automatique :

```php
use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Traits\InterceptsBans;

class CommentComponent extends \Livewire\Component
{
    use InterceptsBans;

    // Bloqué si l'utilisateur est banni globalement
    #[LockedByBan]
    public function postComment(): void
    {
        // ...
    }

    // Bloqué uniquement si l'utilisateur est banni de la feature 'comments'
    #[LockedByBan(feature: 'comments')]
    public function editComment(int $id): void
    {
        // ...
    }

    // Jamais bloqué
    public function loadComments(): void
    {
        // ...
    }
}
```

#### Verrou sur toute la classe

```php
// Tous les appels de méthode sont bloqués si l'utilisateur est banni du forum
#[LockedByBan(feature: 'forum')]
class ForumComponent extends \Livewire\Component
{
    use InterceptsBans;

    public function postThread(): void { /* ... */ }
    public function deleteThread(): void { /* ... */ }
}
```

#### Comportement lors du blocage

Quand un appel est intercepté, le package :
1. **Retourne `null`** — Livewire n'exécute pas la méthode
2. **Flashe `ban_error`** en session — affichez-le dans votre vue

```blade
@if (session('ban_error'))
    <div class="text-red-500">{{ session('ban_error') }}</div>
@endif
```

#### Livewire v3 — hooks manuels

En Livewire v3, vous pouvez appeler `checkBanLock()` directement dans un hook :

```php
use Livewire\Attributes\On;

class CommentComponent extends \Livewire\Component
{
    use InterceptsBans;

    #[On('comment:post')]
    public function postComment(): void
    {
        if ($this->checkBanLock('postComment')) {
            return;
        }

        // logique métier...
    }
}
```

#### Règles d'interception

| Situation | Résultat |
|---|---|
| Pas d'attribut `#[LockedByBan]` sur la méthode ni la classe | ✅ Méthode exécutée |
| Utilisateur non authentifié | ✅ Méthode exécutée |
| Modèle sans trait `HasBans` | ✅ Méthode exécutée |
| Utilisateur banni globalement + lock sans feature | 🚫 Bloqué |
| Utilisateur banni globalement + lock avec feature | 🚫 Bloqué (global ⊇ toutes features) |
| Utilisateur banni de la feature X + lock sur feature X | 🚫 Bloqué |
| Utilisateur banni de la feature X + lock sur feature Y | ✅ Méthode exécutée |

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
| `--duration` | Durée en minutes (omis = permanent) |
| `--reason` | Raison lisible du ban |
| `--feature` | Scope la restriction à une feature |

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

Affiche un tableau de tous les bans enregistrés.

```bash
# Tous les bans actifs
php artisan ban:list

# Filtrés par feature
php artisan ban:list --feature=comments

# Inclure aussi les bans expirés
php artisan ban:list --expired

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
| `--expired` | Inclut les bans expirés dans le résultat |
| `--model=` | Filtre par classe Eloquent bannable |
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

Le cache du modèle banni est **automatiquement invalidé** après la suppression.

---

## Événements

### Tableau des événements

| Événement | Déclenché quand |
|---|---|
| `Godrade\LaravelBan\Events\ModelBanned` | `ban()` crée un ban · `syncBan()` crée un nouveau ban |
| `Godrade\LaravelBan\Events\ModelUnbanned` | `unban()` annule des bans actifs (status → CANCELLED) |
| `Godrade\LaravelBan\Events\ModelBanUpdated` | `syncBan()` met à jour un ban actif existant |

### Écoute des événements

```php
use Godrade\LaravelBan\Events\ModelBanned;
use Godrade\LaravelBan\Events\ModelBanUpdated;
use Godrade\LaravelBan\Events\ModelUnbanned;

protected $listen = [
    ModelBanned::class => [
        App\Listeners\NotifyAdminOnBan::class,
        App\Listeners\LogBanActivity::class,
    ],
    ModelBanUpdated::class => [
        App\Listeners\LogBanChange::class,
    ],
    ModelUnbanned::class => [
        App\Listeners\NotifyUserOnUnban::class,
    ],
];
```

### Payload

```php
// ModelBanned
$event->bannable; // modèle banni  (ex: App\Models\User)
$event->ban;      // instance Ban créée
$event->feature;  // feature ciblée (null = global) — raccourci vers $event->ban->feature

// ModelBanUpdated
$event->bannable;            // modèle dont le ban a été mis à jour
$event->ban;                 // instance Ban après la mise à jour
$event->originalAttributes;  // attributs avant la mise à jour (tableau brut)

// ModelUnbanned
$event->bannable; // modèle débanni
$event->feature;  // feature ciblée (null = global)
```

### Anti-récursion

`HasBans` maintient un verrou statique par objet (`spl_object_hash`) pour empêcher toute récursion infinie si un listener d'événement rappelle `ban()`, `syncBan()` ou `unban()` sur la même instance :

```php
// Listener qui rappelle ban() → pas de boucle infinie
class NotifyAdminOnBan
{
    public function handle(ModelBanned $event): void
    {
        // Si ceci appelle $event->bannable->ban(...), le verrou l'ignore proprement
        // et retourne un Ban vide au lieu de boucler.
        AuditLog::record($event->ban);
    }
}
```

> Le verrou est libéré dans un bloc `finally`, garantissant qu'il est toujours nettoyé même en cas d'exception.

---

## Cache multi-driver

Le package met en cache le résultat de `isBanned()` et `isBannedFrom()` pour éviter des requêtes SQL répétées. Le cache est **automatiquement invalidé** dès qu'un ban est créé, annulé ou mis à jour.

### Choisir un driver

```dotenv
BAN_CACHE_DRIVER=redis      # Redis
BAN_CACHE_DRIVER=           # driver par défaut de l'application
```

### Désactiver le cache

```dotenv
BAN_CACHE_TTL=0
```

### Format des clés

```
laravel_ban_{MorphClass}_{id}_{scope}

# Exemples
laravel_ban_App_Models_User_42_global
laravel_ban_App_Models_User_42_comments
```

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

Le modèle `Ban` implémente `MassPrunable` : les bans expirés depuis plus de **30 jours** peuvent être supprimés en une seule commande SQL.

```bash
php artisan model:prune --model="Godrade\LaravelBan\Models\Ban"
```

**Planifier le nettoyage** dans `app/Console/Kernel.php` :

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('model:prune')->daily();
}
```

> Seuls les bans avec `expired_at < now()->subDays(30)` sont ciblés. Les bans permanents (`expired_at = null`) et les bans récemment expirés sont préservés.

---

La relation `cause` lie un ban à **n'importe quel modèle déclencheur** (signalement, ticket de support, règle de modération…).

| Colonne | Type | Rôle |
|---|---|---|
| `cause_type` | `string\|null` | Classe Eloquent de la cause |
| `cause_id` | `unsignedBigInteger\|null` | Clé primaire de la cause |

```php
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
// config/ban.php
'relations' => [
    'preset' => [
        'type'        => 'belongsTo',
        'related'     => \App\Models\BanPreset::class,
        'foreign_key' => 'preset_id',
    ],
    'ticket' => [
        'type'    => 'belongsTo',
        'related' => \App\Models\SupportTicket::class,
    ],
],
```

```php
$ban->preset;                          // instance BanPreset
Ban::with(['preset', 'ticket'])->get();
```

**Règles de validation au démarrage :**

| Situation | Comportement |
|---|---|
| Nom réservé (`bannable`, `createdBy`, `cause`) | `Log::warning` + relation ignorée |
| Classe `related` inexistante | `Log::error` + relation ignorée |
| Configuration valide | Relation injectée via `resolveRelationUsing` |

---

### `BannedIp`

`BannedIp` supporte le **pruning** via `MassPrunable` : les enregistrements dont `expired_at` est antérieur à 30 jours sont automatiquement supprimés par `php artisan model:prune`.

La colonne `feature` est limitée à **50 caractères** (cohérence avec la table `bans`).

`BannedIp` expose également une relation polymorphique `createdBy()` (`created_by_type` / `created_by_id`) pour enregistrer l'auteur du ban IP.

```php
use Godrade\LaravelBan\Models\BannedIp;

BannedIp::create([
    'ip_address' => '192.168.1.100',
    'reason'     => 'Attaque brute-force',
    'expired_at' => now()->addDays(30),
    'created_by' => $admin,   // relation polymorphique optionnelle
]);

BannedIp::active()->forIp($request->ip())->exists();
BannedIp::active()->forIp($request->ip())->forFeature('api')->exists();

// Pruning automatique (à ajouter au scheduler)
// $schedule->command('model:prune', ['--model' => \Godrade\LaravelBan\Models\BannedIp::class])->daily();
```

---

## Tests

```bash
./vendor/bin/pest
```

| Fichier | Couverture |
|---|---|
| `tests/Feature/DynamicRelationsTest.php` | Relations dynamiques, noms réservés, relation `cause` |
| `tests/Feature/InterceptsBansTest.php` | `#[LockedByBan]` méthode / classe / feature, `BlockBannedIp`, mémoïsation |
| `tests/Feature/MaintenanceTest.php` | `ban:list`, `ban:remove`, `syncBan()`, `MassPrunable` |

---

## Licence

MIT — [Godrade](https://github.com/godrade)

