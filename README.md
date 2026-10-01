# Laravel Ban

Bannissez un utilisateur ou une adresse IP, pour une durée limitée ou définitivement. Vous pouvez bloquer tout l’accès protégé ou seulement une fonctionnalité, comme les commentaires.

Le package fournit les méthodes de bannissement, les middleware pour protéger vos routes, les directives Blade et une intégration Livewire optionnelle. Il fonctionne aussi avec d’autres modèles Eloquent, comme une boutique ou une organisation.

> **Le package est en bêta.** `1.0.0-beta.1` est la **Bêta 1**, pas une version stable 1.0.0. Ce README décrit le code en préparation : les changements [« Non publié »](CHANGELOG.md#non-publié) ne sont pas encore dans la Bêta 1. Pour cette dernière, consultez la [documentation du tag](https://github.com/Godrade/laravel-ban/tree/v1.0.0-beta.1).

## Prérequis

| Dépendance | Versions prises en charge par le code courant |
|---|---|
| Laravel | 12 ou 13 |
| PHP | 8.2 minimum avec Laravel 12 ; 8.3 minimum avec Laravel 13 |
| Livewire | Optionnel, version 3 ou 4 uniquement |

Livewire n’est pas nécessaire pour utiliser les bans, les middleware, Blade ou les commandes Artisan.

**Accès rapide :** [Démarrage](#démarrage-rapide) · [Fonctionnalités](#bannir-une-fonctionnalité) · [Blade](#adapter-laffichage-avec-blade) · [Adresses IP](#bannir-une-adresse-ip) · [Livewire](#protéger-une-action-livewire) · [Documentation complète](#aller-plus-loin)

## Démarrage rapide

Ces étapes supposent une application Laravel existante, avec un modèle `App\Models\User` et une authentification déjà configurée. Exécutez les commandes dans le dossier de cette application.

### 1. Installer le package

Pour installer la préversion disponible sur GitHub, déclarez le dépôt et autorisez les versions bêta :

```bash
composer config repositories.laravel-ban vcs https://github.com/Godrade/laravel-ban
composer require 'godrade/laravel-ban:^1.0@beta'
php artisan migrate
```

Le package s’enregistre automatiquement et les migrations créent les tables `bans` et `banned_ips`. Il n’y a pas de service provider à ajouter à la main.

Les valeurs par défaut suffisent pour commencer. Si vous souhaitez changer les noms des tables ou la suppression logique, [publiez la configuration](docs/reference.md#configuration) **avant** `php artisan migrate`.

Pour essayer les changements non publiés de ce dépôt, suivez plutôt l’[installation depuis une copie locale](CONTRIBUTING.md#tester-le-code-local-dans-une-application).

### 2. Préparer le modèle utilisateur

Dans `app/Models/User.php`, ajoutez le contrat `Bannable` et le trait `HasBans` à votre classe existante. Le contrat déclare les méthodes attendues ; le trait fournit leur implémentation.

Voici un exemple minimal : **conservez les autres traits, propriétés et méthodes de votre modèle**.

```php
<?php

namespace App\Models;

use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Traits\HasBans;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements Bannable
{
    use HasBans;

    // Conservez ici le reste de votre modèle.
}
```

### 3. Protéger une route

Dans `routes/web.php`, ajoutez `banned` aux routes que vous voulez protéger :

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'banned'])->group(function () {
    Route::get('/espace-membre', function () {
        return 'Bienvenue dans votre espace membre.';
    });
});
```

**Enregistrer un ban ne bloque pas les routes à lui seul.** Le middleware `banned` vérifie le ban ; `auth` impose la connexion de l’utilisateur. Les alias du package sont enregistrés automatiquement.

Un utilisateur banni reçoit une réponse **403** pour une requête JSON. Pour une page web, il est redirigé vers la route `login` par défaut. Vous pouvez [configurer une page de suspension](docs/reference.md#redirection).

### 4. Bannir, vérifier et débannir

Ouvrez le terminal interactif de Laravel :

```bash
php artisan tinker
```

Puis bannissez un utilisateur existant pendant sept jours. Remplacez `42` par son identifiant :

```php
use App\Models\User;

$user = User::findOrFail(42);

$user->ban([
    'reason' => 'Messages indésirables répétés',
    'expired_at' => now()->addDays(7),
]);

$user->isBanned(); // true
```

Connecté avec ce compte, vous ne pouvez plus accéder à `/espace-membre`. Sans `expired_at`, le ban est permanent. Un ban temporaire cesse de bloquer à son expiration, sans commande à planifier.

Pour lever le ban avant son expiration, dans la même session Tinker :

```php
$user->unban();
$user->isBanned(); // false
```

`unban()` annule les bans globaux actifs en conservant leur historique. Un second appel à `ban()` sur un périmètre déjà banni provoque une `AlreadyBannedException` par défaut ; utilisez [`syncBan()`](#créer-ou-modifier-un-ban) si vous voulez mettre à jour la restriction existante.

## Bannir une fonctionnalité

Un **ban global** concerne toutes les fonctionnalités protégées. Un **ban de fonctionnalité** ne concerne qu’un nom choisi par votre application, stocké dans `feature` : par exemple `comments` ou `forum`.

Sur un utilisateur sans autre ban actif :

```php
$user->ban([
    'feature' => 'comments',
    'reason' => 'Commentaires abusifs',
    'expired_at' => now()->addDay(),
]);

$user->isBanned();               // false : aucun ban global
$user->isBannedFrom('comments'); // true
$user->isBannedFrom('forum');    // false
```

Utilisez le même nom sur les routes concernées :

```php
use App\Http\Controllers\StoreCommentController;
use Illuminate\Support\Facades\Route;

Route::post('/comments', StoreCommentController::class)
    ->middleware(['auth', 'banned:comments']);
```

`StoreCommentController` représente le contrôleur de votre application qui enregistre les commentaires.

| Opération | Périmètre concerné |
|---|---|
| `isBanned()` / middleware `banned` | Ban global uniquement |
| `isBannedFrom('comments')` / `banned:comments` | Ban global **ou** ban de `comments` |
| `unban()` | Annule uniquement les bans globaux actifs |
| `unban('comments')` | Annule uniquement les bans actifs de `comments` |

Un ban global reste donc prioritaire lors des vérifications : débannir `comments` ne lève pas un ban global.

## Créer ou modifier un ban

`syncBan()` crée un ban s’il n’existe pas, ou modifie le ban actif du même périmètre. C’est utile pour un formulaire de modération, un import ou une tâche planifiée.

```php
$user->syncBan([
    'feature' => 'comments',
    'reason' => 'Restriction prolongée',
    'expired_at' => now()->addDays(30),
]);
```

Dans le code courant, les champs omis gardent leur valeur. Passez explicitement `null` pour effacer un champ, par exemple `'expired_at' => null` pour rendre le ban permanent. [Détails de `syncBan()`](docs/reference.md#créer-ou-mettre-à-jour-avec-syncban).

## Adapter l’affichage avec Blade

Les directives utilisent l’utilisateur connecté par défaut :

```blade
@bannedFrom('comments')
    <p>Vous ne pouvez pas commenter pour le moment.</p>
@else
    <a href="/comments/create">Écrire un commentaire</a>
@endbannedFrom
```

Cet exemple suppose que votre application possède une page `/comments/create`. **Blade adapte l’affichage ; le middleware protège l’accès.** Masquer un lien ou un formulaire ne suffit pas à bloquer l’action.

Voir aussi [`@banned`, `@notBanned`, `@anyBan`, `@allBanned` et `@bannedIp`](docs/reference.md#directives-blade).

## Bannir une adresse IP

Créez un ban IP depuis votre code de modération ou Tinker :

```php
use Godrade\LaravelBan\Models\BannedIp;

$ipBan = BannedIp::create([
    'ip_address' => '192.0.2.10', // Remplacez par l’adresse à bannir.
    'reason' => 'Tentatives de connexion abusives',
    'expired_at' => now()->addDay(),
]);
```

Puis protégez les routes souhaitées avec `ban.ip` :

```php
use Illuminate\Support\Facades\Route;

Route::get('/service', fn () => 'Service disponible')
    ->middleware('ban.ip');
```

Une IP bannie reçoit **HTTP 403**, même sans utilisateur connecté. Pour lever ce ban précis, appelez `$ipBan->delete()`.

**Pour les IP, `ban.ip` bloque tout ban actif**, y compris un ban limité à une fonctionnalité. Utilisez `ban.ip:api` pour ne prendre en compte que les bans globaux et ceux de `api`. [Référence des bans IP](docs/reference.md#blockbannedip).

## Protéger une action Livewire

Avec **Livewire 3 ou 4**, ajoutez `InterceptsBans` au composant et `LockedByBan` à l’action. Le modèle utilisateur doit être préparé comme dans le démarrage rapide.

```php
namespace App\Livewire;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\Traits\InterceptsBans;
use Livewire\Component;

class Comments extends Component
{
    use InterceptsBans;

    #[LockedByBan(feature: 'comments')]
    public function publish(): void
    {
        // Enregistrer le commentaire.
    }

    public function render(): string
    {
        return '<div><button wire:click="publish">Publier</button></div>';
    }
}
```

Dans le code courant, un ban global ou de `comments` interrompt l’action avec **HTTP 403 avant son exécution**. Ces contrôles ne remplacent pas l’authentification. Les hooks comme `mount()` et les mises à jour `wire:model` nécessitent un contrôle explicite s’ils déclenchent une écriture à protéger.

[Guide Livewire : attributs de classe, événements, héritage et appels internes](docs/livewire.md).

## Utiliser le terminal

Depuis votre application, pour un utilisateur d’identifiant `42` :

```bash
php artisan ban:user 42 --duration=1440 --reason="Spam"
php artisan ban:list
```

`--duration` est exprimée en **minutes** : `1440` correspond à 24 heures. Sans cette option, le ban est permanent.

Pour annuler un ban et garder son historique, utilisez `unban()` en PHP. `ban:remove` supprime un enregistrement à partir de l’**identifiant du ban**, pas de l’utilisateur. [Toutes les commandes et options](docs/reference.md#commandes-artisan).

## Aller plus loin

| Vous cherchez… | Documentation |
|---|---|
| La configuration, toutes les méthodes, les middleware et Blade | [Référence d’utilisation](docs/reference.md) |
| Les événements, transactions et règles du cache | [Comportements avancés](docs/advanced.md) |
| Les modèles, relations et nettoyage des bans expirés | [Référence Eloquent](docs/advanced.md#modèles-eloquent) |
| L’intégration Livewire 3 / 4 | [Guide Livewire](docs/livewire.md) |
| Les changements à appliquer sur une installation existante | [Mise à jour](docs/advanced.md#mise-à-jour) |
| Les nouveautés et changements de comportement | [Changelog en français](CHANGELOG.md) |
| Les tests et les consignes de contribution | [Contribuer](CONTRIBUTING.md) |

## Licence

[MIT](LICENSE) — [Godrade](https://github.com/godrade)
