# Référence d’utilisation

[← Retour au README](../README.md) · [Comportements avancés](advanced.md) · [Livewire](livewire.md)

Commencez par le [démarrage rapide](../README.md#démarrage-rapide) pour installer le package et préparer votre modèle. Cette référence décrit le code courant ; consultez le [changelog](../CHANGELOG.md) pour distinguer les changements en préparation de la Bêta 1.

- [Configuration](#configuration)
- [Bannir, vérifier et débannir](#utilisation)
- [Gérer les doublons](#protection-contre-les-bans-en-doublon)
- [Restreindre une fonctionnalité](#bans-par-fonctionnalité)
- [Créer ou mettre à jour avec syncBan](#créer-ou-mettre-à-jour-avec-syncban)
- [Protéger les routes utilisateur](#checkbanned)
- [Bloquer les adresses IP](#blockbannedip)
- [Directives Blade](#directives-blade)
- [Commandes Artisan](#commandes-artisan)

Un **ban global** a `feature = null`. Un **ban de fonctionnalité** a un nom de `feature`, choisi par l’application. Le terme *scope* désigne ce périmètre dans les commentaires du code.

## Configuration

Les valeurs par défaut fonctionnent sans fichier de configuration publié. Pour les personnaliser, lancez `php artisan ban:config`, puis modifiez `config/ban.php`. Choisissez les noms des tables et `soft_delete` avant la première migration.

Les migrations sont chargées automatiquement. `php artisan ban:config --migrations` permet aussi de les publier si vous avez besoin de les adapter.

```php
return [
    // Driver de cache : null = driver par défaut de l'app, 'redis', 'database', etc.
    'cache_driver' => env('BAN_CACHE_DRIVER', null),

    // Préfixe des clés de cache
    'cache_prefix' => env('BAN_CACHE_PREFIX', 'laravel_ban_'),

    // Durée de vie du cache en secondes (0 = désactivé)
    'cache_ttl' => (int) env('BAN_CACHE_TTL', 3600),

    // Route nommée ou URL de redirection pour les utilisateurs bannis
    'redirect_url' => env('BAN_REDIRECT_URL', 'login'),

    // Noms des tables (personnalisables avant la première migration)
    'table_names' => [
        'bans'       => 'bans',
        'banned_ips' => 'banned_ips',
    ],

    // Alias du middleware CheckBanned
    'middleware_alias' => 'banned',

    // false = refuser un autre ban actif sur le même périmètre exact
    'allow_overlapping_bans' => (bool) env('BAN_ALLOW_OVERLAPPING', false),

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

## Utilisation

### Bannir un utilisateur

Les exemples supposent un modèle déjà enregistré :

```php
use App\Models\User;

$user = User::findOrFail(42); // Remplacez 42 par un identifiant existant.
```

Les variantes suivantes sont indépendantes : choisissez un seul appel à `ban()` pour un utilisateur qui n’est pas déjà banni. `$admin` et `$report` représentent des modèles Eloquent déjà enregistrés.

**Ban permanent, sans raison :**

```php
$ban = $user->ban();
```

**Ban permanent avec une raison :**

```php
$ban = $user->ban(['reason' => 'Violation des CGU']);
```

**Ban temporaire, qui expire dans sept jours :**

```php
$ban = $user->ban([
    'reason'     => 'Comportement abusif',
    'expired_at' => now()->addDays(7),
]);
```

**Enregistrer l’auteur du ban :**

```php
$ban = $user->ban([
    'reason'     => 'Spam',
    'created_by' => $admin,
]);
```

**Associer un signalement, un ticket ou une autre cause :**

```php
$ban = $user->ban([
    'reason' => 'Contenu offensant',
    'cause'  => $report,
]);
```

`ban()` retourne l'instance `Ban` créée avec son statut immédiatement disponible, ou `null` en cas d’appel récursif pour le même modèle. Vous pouvez consulter le résultat de l’appel choisi ci-dessus :

```php
$ban?->id;         // Identifiant du ban créé.
$ban?->reason;     // Raison fournie, ou null.
$ban?->expired_at; // Date d’expiration, ou null pour un ban permanent.
```

---

### Débannir

**Sans argument, `unban()` annule uniquement les bans globaux.** Pour une fonctionnalité, passez son nom. Les autres restrictions restent actives.

`unban()` ne supprime **pas** les enregistrements — il les **annule** en passant leur `status` à `BanStatus::CANCELLED`. L'historique des bans est ainsi préservé intégralement.

```php
// Annule tous les bans globaux actifs
$user->unban();

// Annule tous les bans actifs sur la fonctionnalité "comments"
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

Interceptez `AlreadyBannedException` pour afficher le ban existant à l'administrateur. Une vérification préalable de l’état du ban ne remplace pas cette gestion : un autre processus peut bannir le modèle entre la vérification et l'écriture. Les insertions SQL directes ne passent pas par ce verrou.

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

### Bans par fonctionnalité

Le champ **`feature`** contient le nom d’une fonctionnalité choisie par votre application, par exemple `comments` ou `forum` (50 caractères maximum). Un ban de fonctionnalité restreint uniquement ce périmètre. Un ban global rend l'utilisateur banni de *toutes* les features.

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

### Créer ou mettre à jour avec syncBan

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

// Périmètre comments, indépendant du ban global
$user->syncBan(['feature' => 'comments', 'reason' => 'Commentaires offensants']);
```

| Situation | Comportement |
|---|---|
| Aucun ban actif sur ce scope | Crée un nouveau ban + émet `ModelBanned` |
| Ban actif existant sur ce scope | Met à jour uniquement les champs fournis parmi `reason`, `expired_at`, `created_by`, `cause` |
| Champ omis / champ passé à `null` | Conserve sa valeur / efface sa valeur |
| Ban expiré sur ce scope | Crée un nouveau ban |
| `allow_overlapping_bans` peu importe | Jamais d'`AlreadyBannedException` |

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

#### Redirection

Pour une page dédiée, définissez `BAN_REDIRECT_URL=account.suspended` dans `.env` et déclarez cette route hors du groupe protégé par `banned` :

```php
use Illuminate\Support\Facades\Route;

Route::get('/compte-suspendu', function () {
    return response('Votre compte est suspendu.', 403);
})->name('account.suspended');
```

La destination doit rester accessible à l’utilisateur banni, sans le rediriger vers une route protégée. Si la configuration est mise en cache, reconstruisez-la après modification de `.env`.

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

#### Protéger un groupe de routes

```php
// routes/web.php
Route::middleware('ban.ip')->group(function () {
    // toutes ces routes refuseront les IPs bannies
});
```

Pour l’appliquer à toutes les routes, ajoutez `$middleware->append(BlockBannedIp::class);` au callback `withMiddleware` existant dans `bootstrap/app.php`. Voici un exemple minimal : conservez les autres réglages de votre application.

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

## Directives Blade

Ces directives adaptent l’affichage. Protégez aussi les routes avec un middleware ou les actions Livewire avec `LockedByBan` : masquer un formulaire ne bloque pas une requête envoyée directement.

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
        @csrf
        {{-- champs du formulaire de commentaire --}}
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

{{-- Pour une fonctionnalité --}}
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
| Aucun modèle `Bannable` en dernier argument | Utilise `auth()->user()` ; les arguments restent des noms de fonctionnalités |
| Utilisateur non authentifié | Retourne `false` |

## Commandes Artisan

### `ban:user`

Bannit un modèle depuis le terminal. Les identifiants ci-dessous sont des exemples à remplacer ; les commandes de création sont des alternatives.

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
| `--feature` | Limite le ban à une fonctionnalité |

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

Supprime un ban par son identifiant technique, **pas par l’identifiant de l’utilisateur**. Utilisez `ban:list` pour le retrouver. Pour annuler un ban en conservant son statut dans l’historique, préférez `$user->unban()` ou `$user->unban('comments')`. Ces commandes concernent les bans de modèles, pas la table `banned_ips`.

```bash
# Soft-delete avec confirmation interactive
php artisan ban:remove 42

# Suppression permanente (force)
php artisan ban:remove 42 --force

# Sans confirmation (scripts automatisés)
php artisan ban:remove 42 --no-confirm
```

**Options :**

| Option | Description |
|---|---|
| `id` | *(requis)* Identifiant du ban |
| `--force` | Suppression permanente (ignore le soft-delete) |
| `--no-confirm` | Ne demande pas de confirmation |

Le cache du modèle banni est automatiquement invalidé après la suppression, y compris lorsque `bannable_type` contient un alias de morph map. Avec `soft_delete=false`, la suppression est définitive même sans `--force`.
