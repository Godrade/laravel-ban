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
- [Middleware](#middleware)
  - [CheckBanned](#checkbanned)
  - [BlockBannedIp](#blockbannedip)
- [Directives Blade](#directives-blade)
- [Intégration Livewire](#intégration-livewire)
  - [Attribut #\[LockedByBan\]](#attribut-lockedbyban)
  - [Trait InterceptsBans](#trait-interceptsbans)
- [Commandes Artisan](#commandes-artisan)
- [Événements](#événements)
- [Cache multi-driver](#cache-multi-driver)
- [Modèles Eloquent](#modèles-eloquent)
  - [Ban](#ban)
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

`ban()` retourne l'instance `Ban` créée :

```php
$ban = $user->ban(['reason' => 'Test']);

echo $ban->id;         // 42
echo $ban->reason;     // "Test"
echo $ban->expired_at; // null (permanent)
```

---

### Débannir

```php
// Supprime tous les bans globaux actifs
$user->unban();

// Supprime uniquement le ban sur la feature "comments"
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

### `@banned` / `@notBanned`

Affiche un bloc selon que l'utilisateur connecté est banni globalement ou non.

```blade
@banned
    <p class="text-red-500">Votre compte est suspendu.</p>
@endbanned

@notBanned
    <a href="/post">Créer un article</a>
@endnotBanned
```

### `@bannedFrom`

Affiche un bloc si l'utilisateur est banni d'une feature spécifique.

```blade
@bannedFrom('comments')
    <p>Vous ne pouvez pas commenter pour le moment.</p>
@else
    <form action="/comments" method="POST">
        {{-- formulaire de commentaire --}}
    </form>
@endbannedFrom
```

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

## Événements

| Événement | Déclenché quand |
|---|---|
| `Godrade\LaravelBan\Events\UserBanned` | Un ban est créé |
| `Godrade\LaravelBan\Events\UserUnbanned` | Un ban est supprimé |

### Exemple d'écoute

```php
use Godrade\LaravelBan\Events\UserBanned;
use Godrade\LaravelBan\Events\UserUnbanned;

protected $listen = [
    UserBanned::class => [
        App\Listeners\NotifyAdminOnBan::class,
        App\Listeners\LogBanActivity::class,
    ],
    UserUnbanned::class => [
        App\Listeners\NotifyUserOnUnban::class,
    ],
];
```

### Payload

```php
// UserBanned
$event->bannable; // modèle banni  (ex: App\Models\User)
$event->ban;      // instance Ban créée

// UserUnbanned
$event->bannable; // modèle débanni
$event->feature;  // feature ciblée (null = global)
```

---

## Cache multi-driver

Le package met en cache le résultat de `isBanned()` et `isBannedFrom()` pour éviter des requêtes SQL répétées. Le cache est **automatiquement invalidé** dès qu'un ban est créé ou supprimé.

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
use Godrade\LaravelBan\Models\Ban;

Ban::active()->get();                        // tous les bans actifs
Ban::active()->forFeature('comments')->get(); // actifs sur une feature
Ban::active()->global()->get();              // bans globaux actifs

$ban->bannable;   // modèle banni  (ex: App\Models\User)
$ban->createdBy;  // auteur du ban (ex: App\Models\Admin)
$ban->cause;      // cause liée    (ex: App\Models\Report)
$ban->isActive(); // bool
```

---

### Relation `cause` (polymorphique)

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

```php
use Godrade\LaravelBan\Models\BannedIp;

BannedIp::create([
    'ip_address' => '192.168.1.100',
    'reason'     => 'Attaque brute-force',
    'expired_at' => now()->addDays(30),
]);

BannedIp::active()->forIp($request->ip())->exists();
BannedIp::active()->forIp($request->ip())->forFeature('api')->exists();
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

---

## Licence

MIT — [Godrade](https://github.com/godrade)

