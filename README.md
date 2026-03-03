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
- [Directives Blade](#directives-blade)
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

    // Alias du middleware
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

// Ban posé par un admin (crée un lien polymorphique created_by)
$user->ban([
    'reason'     => 'Spam',
    'created_by' => $admin,
]);

// Ban lié à une cause polymorphique (signalement, ticket, règle…)
$user->ban([
    'reason' => 'Contenu offensant',
    'cause'  => $report,   // n'importe quel modèle Eloquent
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

Par défaut (`allow_overlapping_bans = false`), appeler `ban()` sur un modèle déjà banni (sur le même scope) lance une exception `AlreadyBannedException` au lieu de créer un enregistrement en doublon.

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
// Vérifier avant de bannir
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

Pour lever cette restriction et autoriser plusieurs bans actifs simultanément :
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

Le middleware `CheckBanned` redirige automatiquement les utilisateurs bannis vers l'URL configurée dans `ban.redirect_url`.

### Protection globale

```php
// routes/web.php
Route::middleware(['auth', 'banned'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

### Protection par feature

```php
Route::middleware(['auth', 'banned:comments'])->group(function () {
    Route::post('/comments', StoreCommentController::class);
});
```

### Personnaliser la réponse de redirection

La redirection inclut un message flash `ban_error` que vous pouvez afficher dans vos vues :

```blade
@if (session('ban_error'))
    <div class="alert alert-danger">
        {{ session('ban_error') }}
    </div>
@endif
```

---

## Directives Blade

### `@banned` / `@notBanned`

Affiche un bloc si l'utilisateur **connecté** est banni (ou non) globalement.

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

Le package dispatche des événements Laravel standards que vous pouvez écouter dans `EventServiceProvider` ou via des Listeners.

| Événement | Déclenché quand |
|---|---|
| `Godrade\LaravelBan\Events\UserBanned` | Un ban est créé |
| `Godrade\LaravelBan\Events\UserUnbanned` | Un ban est supprimé |

### Exemple d'écoute

```php
// app/Providers/EventServiceProvider.php
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

### Payload des événements

```php
// UserBanned
$event->bannable; // Le modèle banni (ex: App\Models\User)
$event->ban;      // L'instance Ban créée

// UserUnbanned
$event->bannable; // Le modèle débanni
$event->feature;  // La feature ciblée (null = global)
```

---

## Cache multi-driver

Le package met en cache le résultat de `isBanned()` et `isBannedFrom()` pour éviter des requêtes SQL répétées.

Le cache est **automatiquement invalidé** (forget) dès qu'un ban est créé ou supprimé.

### Choisir un driver

```dotenv
# Utiliser Redis
BAN_CACHE_DRIVER=redis

# Utiliser le driver par défaut de l'application
BAN_CACHE_DRIVER=
```

### Désactiver le cache

```dotenv
BAN_CACHE_TTL=0
```

### Format des clés de cache

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

// Tous les bans actifs
Ban::active()->get();

// Bans actifs sur une feature
Ban::active()->forFeature('comments')->get();

// Bans globaux actifs
Ban::active()->global()->get();

// Relation vers le modèle banni
$ban->bannable; // ex: App\Models\User

// Relation vers l'auteur du ban
$ban->createdBy; // ex: App\Models\Admin

// Vérifier si un ban est encore actif
$ban->isActive(); // bool
```

---

### Relation `cause` (polymorphique)

La colonne `cause` permet de lier un ban à **n'importe quel modèle déclencheur** : un signalement, un ticket de support, une règle de modération, etc.

#### Schéma

La migration ajoute automatiquement deux colonnes à la table `bans` :

| Colonne | Type | Rôle |
|---|---|---|
| `cause_type` | `string\|null` | Classe Eloquent de la cause |
| `cause_id` | `unsignedBigInteger\|null` | Clé primaire de la cause |

#### Utilisation

```php
// Créer un ban lié à un signalement
$ban = $user->ban([
    'reason' => 'Contenu offensant',
    'cause'  => $report,
]);

// Accéder à la cause depuis l'instance Ban
$ban->cause;       // instance de App\Models\Report (ou tout autre modèle)
$ban->cause_type;  // "App\Models\Report"
$ban->cause_id;    // 17

// Charger la cause en eager loading
Ban::with('cause')->active()->get();
```

> `cause` est un `morphTo` natif — il est **réservé** et ne peut pas être écrasé par les relations dynamiques.

---

### Relations dynamiques

Le package permet d'**injecter des relations Eloquent supplémentaires** sur le modèle `Ban` sans avoir à le modifier, directement depuis `config/ban.php`.

Le `BanServiceProvider` appelle `Ban::resolveRelationUsing()` au démarrage pour chaque entrée valide.

#### Format de configuration

```php
// config/ban.php
'relations' => [
    'nom_relation' => [
        'type'        => 'belongsTo',            // méthode Eloquent (belongsTo, hasMany…)
        'related'     => App\Models\Preset::class, // classe cible (doit exister)
        'foreign_key' => 'preset_id',            // optionnel
        'owner_key'   => 'id',                   // optionnel (belongsTo uniquement)
    ],
],
```

#### Exemple complet

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

Une fois configurées, les relations sont accessibles comme n'importe quelle relation Eloquent :

```php
$ban->preset;          // instance de BanPreset
$ban->preset()->first();

Ban::with('preset')->active()->get();
Ban::with(['preset', 'ticket'])->get();
```

#### Règles de validation au démarrage

| Situation | Comportement |
|---|---|
| Nom réservé (`bannable`, `createdBy`, `cause`) | `Log::warning` + relation ignorée |
| Classe `related` inexistante | `Log::error` + relation ignorée |
| Configuration valide | Relation injectée via `resolveRelationUsing` |

> Les erreurs sont loguées silencieusement — l'application ne plante pas en cas de mauvaise configuration.

#### Noms réservés

Les noms suivants sont protégés et ne peuvent pas être utilisés comme nom de relation dynamique :

```php
'reserved_relations' => ['bannable', 'createdBy', 'cause'],
```

Vous pouvez étendre cette liste dans votre `config/ban.php` publié.

---

### `BannedIp`

```php
use Godrade\LaravelBan\Models\BannedIp;

// Bannir une IP
BannedIp::create([
    'ip_address' => '192.168.1.100',
    'reason'     => 'Attaque brute-force',
    'expired_at' => now()->addDays(30),
]);

// Vérifier si une IP est bannie
BannedIp::active()->forIp($request->ip())->exists();

// Vérifier pour une feature précise
BannedIp::active()->forIp($request->ip())->forFeature('api')->exists();
```

---

## Tests

Le package est testé avec [Pest](https://pestphp.com/) et [Orchestra Testbench](https://github.com/orchestral/testbench).

```bash
composer test
# ou directement
./vendor/bin/pest
```

### Suite de tests incluse

| Fichier | Couverture |
|---|---|
| `tests/Feature/DynamicRelationsTest.php` | Injection de relations dynamiques, guard des noms réservés, classe manquante, relation `cause` |

---

## Licence

MIT — [Godrade](https://github.com/godrade)


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

    // Alias du middleware
    'middleware_alias' => 'banned',

    // Interdire de bannir un modèle déjà banni (false = protection contre les doublons)
    'allow_overlapping_bans' => env('BAN_ALLOW_OVERLAPPING', false),

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

// Ban posé par un admin (crée un lien polymorphique created_by)
$user->ban([
    'reason'     => 'Spam',
    'created_by' => $admin,
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

Par défaut (`allow_overlapping_bans = false`), appeler `ban()` sur un modèle déjà banni (sur le même scope) lance une exception `AlreadyBannedException` au lieu de créer un enregistrement en doublon.

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
// Vérifier avant de bannir
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

Pour lever cette restriction et autoriser plusieurs bans actifs simultanément, définissez dans `.env` :
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

Le middleware `CheckBanned` redirige automatiquement les utilisateurs bannis vers l'URL configurée dans `ban.redirect_url`.

### Protection globale

```php
// routes/web.php
Route::middleware(['auth', 'banned'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

### Protection par feature

```php
Route::middleware(['auth', 'banned:comments'])->group(function () {
    Route::post('/comments', StoreCommentController::class);
});
```

### Personnaliser la réponse de redirection

La redirection inclut un message flash `ban_error` que vous pouvez afficher dans vos vues :

```blade
@if (session('ban_error'))
    <div class="alert alert-danger">
        {{ session('ban_error') }}
    </div>
@endif
```

---

## Directives Blade

### `@banned` / `@notBanned`

Affiche un bloc si l'utilisateur **connecté** est banni (ou non) globalement.

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

Le package dispatche des événements Laravel standards que vous pouvez écouter dans `EventServiceProvider` ou via des Listeners.

| Événement | Déclenché quand |
|---|---|
| `Godrade\LaravelBan\Events\UserBanned` | Un ban est créé |
| `Godrade\LaravelBan\Events\UserUnbanned` | Un ban est supprimé |

### Exemple d'écoute

```php
// app/Providers/EventServiceProvider.php
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

### Payload des événements

```php
// UserBanned
$event->bannable; // Le modèle banni (ex: App\Models\User)
$event->ban;      // L'instance Ban créée

// UserUnbanned
$event->bannable; // Le modèle débanni
$event->feature;  // La feature ciblée (null = global)
```

---

## Cache multi-driver

Le package met en cache le résultat de `isBanned()` et `isBannedFrom()` pour éviter des requêtes SQL répétées.

Le cache est **automatiquement invalidé** (forget) dès qu'un ban est créé ou supprimé.

### Choisir un driver

```dotenv
# Utiliser Redis
BAN_CACHE_DRIVER=redis

# Utiliser le driver par défaut de l'application
BAN_CACHE_DRIVER=
```

### Désactiver le cache

```dotenv
BAN_CACHE_TTL=0
```

### Format des clés de cache

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

// Tous les bans actifs
Ban::active()->get();

// Bans actifs sur une feature
Ban::active()->forFeature('comments')->get();

// Bans globaux actifs
Ban::active()->global()->get();

// Relation vers le modèle banni
$ban->bannable; // ex: App\Models\User

// Relation vers l'auteur du ban
$ban->createdBy; // ex: App\Models\Admin

// Vérifier si un ban est encore actif
$ban->isActive(); // bool
```

### `BannedIp`

```php
use Godrade\LaravelBan\Models\BannedIp;

// Bannir une IP
BannedIp::create([
    'ip_address' => '192.168.1.100',
    'reason'     => 'Attaque brute-force',
    'expired_at' => now()->addDays(30),
]);

// Vérifier si une IP est bannie
BannedIp::active()->forIp($request->ip())->exists();

// Vérifier pour une feature précise
BannedIp::active()->forIp($request->ip())->forFeature('api')->exists();
```

---

## Licence

MIT — [Godrade](https://github.com/godrade)
