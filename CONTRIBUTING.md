# Contribuer au package

[← Retour au README](README.md)

Le code courant cible Laravel 12 et 13, avec Livewire 3 ou 4 lorsqu’il est installé. Proposez les changements de comportement avec un test de non-régression et une entrée en français dans la section « Non publié » du [changelog](CHANGELOG.md).

## Vérifications

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

## Tester le code local dans une application

Pour essayer les changements non publiés, utilisez un dépôt Composer de type `path` dans votre application Laravel. Depuis la racine de cette application, en adaptant le chemin vers votre copie du package :

```bash
composer config repositories.laravel-ban path ../laravel-ban
composer require 'godrade/laravel-ban:@dev'
```

Cette installation utilise votre copie locale au lieu de la Bêta 1 du dépôt GitHub. Le chemin doit exister sur la machine qui installe les dépendances ; cette configuration sert au développement local.
