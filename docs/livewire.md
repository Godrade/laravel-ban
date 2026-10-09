# Intégration Livewire

[← Retour au README](../README.md) · [Référence d’utilisation](reference.md) · [Comportements avancés](advanced.md)

Le modèle utilisateur doit déjà implémenter `Bannable` et utiliser `HasBans`, comme dans le [démarrage rapide](../README.md#2-préparer-le-modèle-utilisateur).

- [Attribut LockedByBan](#attribut-lockedbyban)
- [Composant avec InterceptsBans](#trait-interceptsbans)
- [Réponse HTTP 403](#comportement-lors-du-blocage)
- [Appels internes et cycle de vie](#appels-internes-et-hooks-de-cycle-de-vie)
- [Règles d’interception](#règles-dinterception)

L'intégration est **optionnelle et compatible uniquement avec Livewire 3 ou 4**. Si votre application utilise Livewire, installez une version supportée :

```bash
composer require 'livewire/livewire:^3.0|^4.0'
```

Le service provider enregistre automatiquement le contrôle lorsque Livewire est disponible. Aucune interception manuelle des actions n'est nécessaire.

## Attribut LockedByBan

| Cible | Comportement |
|---|---|
| Méthode | Protège cette action et les événements qui l'appellent |
| Classe | Protège les actions distantes du composant |
| Sans `feature` | Vérifie le ban global |
| `feature: 'comments'` | Vérifie le ban global et celui de `comments` |

Un attribut de méthode prend la priorité sur l'attribut de classe pour le périmètre. Les méthodes héritées conservent leur attribut ; pour une classe sans attribut propre, le package utilise celui du parent le plus proche.

## Trait InterceptsBans

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
        // Protégé par le périmètre forum de la classe.
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

### Comportement lors du blocage

Le package interrompt la requête avec **HTTP 403 avant l'exécution de l'action ou du listener** et flashe `ban_error` en session. La réponse d'erreur ne provoque pas un nouveau rendu du composant ; gérez-la avec le traitement des erreurs Livewire de votre application. Le message flash reste utilisable sur une page rendue ensuite.

Le trait n'ajoute aucun `callMethod()` public. Les méthodes privées et le helper protégé `checkBanLock()` ne deviennent pas des actions distantes.

### Appels internes et hooks de cycle de vie

Les contrôles automatiques concernent les actions reçues de Livewire et les listeners, qu'ils soient déclarés avec `#[On]` ou `$listeners`. Les appels PHP internes et les hooks tels que `mount()`, `boot()` ou `updated()` ne passent pas par ce contrôle. Les changements de propriétés via `wire:model` ne constituent pas des actions protégées par cet attribut.

Pour un appel interne, vérifiez la restriction avant l'effet à protéger et retournez si le helper indique un blocage :

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

### Règles d'interception

| Situation | Résultat |
|---|---|
| Composant sans `InterceptsBans` | Aucun contrôle automatique |
| Pas d'attribut méthode ni classe | Action autorisée par le package |
| Invité ou utilisateur sans contrat `Bannable` | Action autorisée par le package |
| Ban global et action verrouillée | HTTP 403 |
| Ban de fonctionnalité X et lock sur X | HTTP 403 |
| Ban de fonctionnalité X et lock sur Y | Action autorisée par le package |

Ces contrôles ne remplacent pas les règles d'authentification et d'autorisation de l'application.
