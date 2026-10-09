# Historique des versions

Ce fichier répertorie les changements notables du package.

Son format s'inspire de [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Les numéros de version suivent le [versionnement sémantique](https://semver.org/spec/v2.0.0.html).

La dernière préversion publiée est **1.0.0-beta.2 (Bêta 2)**. La version stable 1.0.0 n'a pas encore été publiée.

## [Non publié]

## [1.0.0-beta.2] — 2026-10-09

Deuxième préversion. Elle contient plusieurs ruptures de compatibilité par rapport à la Bêta 1 : consultez le [guide de mise à jour](docs/advanced.md#mise-à-jour) dans `docs/advanced.md` avant de migrer.

### Suppressions

- **Rupture de compatibilité :** suppression du support de Laravel 11. Le package nécessite désormais Laravel 12 ou 13. Testbench 9, les jobs CI Laravel 11 et leur exception au blocage de sécurité Composer sont supprimés.

### Modifications

- Réorganisation de la documentation : démarrage rapide dans le README, exemples par besoin et guides séparés pour la référence, les comportements avancés, Livewire et la contribution. Distinction explicite entre la Bêta 1 et les changements en préparation.
- **Rupture de compatibilité :** l'intégration optionnelle Livewire prend uniquement en charge les versions 3 et 4. Les actions et listeners interdits renvoient HTTP 403 avant leur exécution, au lieu de retourner silencieusement `null`.
- **Rupture de compatibilité :** suppression du répartiteur public `callMethod()`. La méthode `checkBanLock()` devient protégée et reste disponible pour les contrôles explicites à l'intérieur d'un composant.
- **Rupture de compatibilité :** `syncBan()` conserve les champs omis lors d'une mise à jour. Passez explicitement `null` pour effacer une raison, une expiration, un auteur ou une cause.
- Les événements de bannissement sont émis après la validation de la transaction englobante et l'invalidation du cache. Une transaction annulée ne déclenche plus ces événements.
- Le cache des bannissements utilise des clés hachées et versionnées `v2`. Les anciennes entrées ne sont plus consultées et expirent naturellement.
- Les relations dynamiques acceptent les modèles Eloquent et les types `belongsTo`, `hasOne` et `hasMany`. Les définitions invalides sont journalisées et ignorées.

### Corrections

- Correction de l'invalidation du cache pour les bans globaux et par fonctionnalité, la fonctionnalité nommée `global`, les expirations, les sauvegardes, suppressions et restaurations Eloquent directes, les changements de modèle cible ou de périmètre, et les alias polymorphiques.
- Les vérifications effectuées dans une transaction ignorent le cache partagé. Les écritures l'invalident à nouveau après validation de la transaction et empêchent une lecture déjà en cours de réintroduire un résultat périmé dans la génération courante.
- Les vérifications de bans de modèles et d'IP utilisent la connexion d'écriture afin qu'une réplique de lecture en retard ne remette pas un ancien état en cache.
- La création et la synchronisation d'un ban verrouillent le modèle parent enregistré dans une transaction, même lorsqu'aucun ban n'existe encore. La protection contre la récursion repose désormais sur l'identité en base, y compris entre plusieurs instances du même modèle.
- Les bans créés exposent immédiatement leur statut configuré. L'attribut `cause` est enregistré par `ban()` et `syncBan()`.
- Les deux modèles de ban respectent `soft_delete=false` sans interroger une colonne `deleted_at` absente.
- La mémoïsation des bans IP est partagée entre le middleware et Blade pendant chaque requête HTTP. Elle tient compte des expirations et des transactions, et les mutations Eloquent l'invalident sans réinitialisation manuelle sous Octane.
- Une même IP peut avoir plusieurs bans par fonctionnalité ou dans son historique. Les adresses IPv6 valides sont normalisées à l'enregistrement et à la recherche. `BannedIp::create()` accepte un modèle dans `created_by`.
- Les contrôles Livewire couvrent les attributs de méthode et de classe, les restrictions héritées, la priorité des fonctionnalités et les listeners déclarés par attribut ou configuration, sans exposer les méthodes privées.
- `ban:list --status=cancelled` affiche l'historique des annulations. Les filtres de modèle résolvent les alias polymorphiques et la suppression invalide le cache sans traiter ces alias comme des noms de classe.
- Les durées invalides et les bans en doublon saisis en ligne de commande provoquent un échec explicite, au lieu de créer un ban permanent involontaire ou de laisser remonter une exception non gérée.
- Le middleware utilisateur renvoie une réponse JSON 403 aux requêtes API, accepte les chemins de redirection relatifs et renvoie HTTP 403 lorsqu'une route manque ou qu'une redirection créerait une boucle.
- Les relations dynamiques `belongsTo` déduisent leur clé étrangère du nom de relation configuré.
- La documentation du nettoyage automatique planifie explicitement les deux modèles du package sous Laravel 12 et 13.

### Ajouts

- Migration de mise à jour `2026_10_01_000003_allow_multiple_bans_per_ip.php` : suppression de l'unicité par IP et normalisation des adresses IPv6 existantes. Son annulation conserve volontairement l'index non unique et les valeurs normalisées afin de préserver les données ajoutées depuis.
- Tests de non-régression utilisant les migrations réelles du package et de vrais composants Livewire : cycle de vie du cache, transactions, requêtes IP, configuration du schéma, réponses HTTP et validation des commandes.
- Commandes Composer pour vérifier le formatage avec Pint, analyser le code avec Larastan/PHPStan et exécuter les tests, regroupées sous `composer check`.
- Tests d'intégration MySQL/PostgreSQL activés explicitement avec les variables `BAN_TEST_DB_*`, incluant les modifications concurrentes. Ils nécessitent une base jetable et recréent leurs tables `integration_*`.
- Jobs CI couvrant Laravel 12/13 avec Livewire 3/4, le fonctionnement sans Livewire et les services MySQL 8/PostgreSQL 16. Le blocage de sécurité Composer reste actif dans tous les jobs.

## [1.0.0-beta.1] — 2026-03-23

Première préversion du package, avant la sortie d'une version stable 1.0.0.

### Ajouts

- API `ban()`, `unban()`, `isBanned()` et `isBannedFrom()`, avec prise en charge de plusieurs pilotes de cache via le trait `HasBans`.
- Bans limités à une fonctionnalité, par exemple `comments` ou `forum`.
- Exception `AlreadyBannedException` et protection contre les bans actifs en doublon, configurable avec `allow_overlapping_bans`.
- Méthode `syncBan()` pour créer ou mettre à jour un ban.
- Enum `BanStatus` avec les états `ACTIVE` et `CANCELLED` : `unban()` annule les enregistrements au lieu de les supprimer.
- Verrou statique anti-récursion dans `HasBans`, fondé sur `spl_object_hash`.
- Modèles Eloquent `Ban` et `BannedIp`, avec prise en charge de `MassPrunable`.
- Relations Eloquent dynamiques sur `Ban`, configurées avec `config('ban.relations')`.
- Relation polymorphique `cause()` sur le modèle `Ban`.
- Événements `ModelBanned`, `ModelUnbanned` et `ModelBanUpdated`.
- Middleware `CheckBanned`, avec restriction par fonctionnalité et redirection configurable.
- Middleware `BlockBannedIp`, avec mémoïsation des vérifications.
- Attribut PHP `#[LockedByBan]`, applicable aux méthodes et aux classes.
- Première intégration Livewire avec le trait `InterceptsBans`.
- Directives Blade : `@banned`, `@notBanned`, `@bannedFrom`, `@bannedIp`, `@anyBan` et `@allBanned`.
- Commandes Artisan : `ban:user`, `ban:config`, `ban:list` et `ban:remove`.
- Suite de tests Pest.

[Non publié]: https://github.com/godrade/laravel-ban/compare/v1.0.0-beta.2...HEAD
[1.0.0-beta.2]: https://github.com/godrade/laravel-ban/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/godrade/laravel-ban/releases/tag/v1.0.0-beta.1
