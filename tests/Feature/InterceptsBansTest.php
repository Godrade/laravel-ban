<?php

declare(strict_types=1);

namespace Godrade\LaravelBan\Tests\Feature;

use Godrade\LaravelBan\Attributes\LockedByBan;
use Godrade\LaravelBan\BanServiceProvider;
use Godrade\LaravelBan\Contracts\Bannable;
use Godrade\LaravelBan\Tests\Support\TestCase;
use Godrade\LaravelBan\Traits\HasBans;
use Godrade\LaravelBan\Traits\InterceptsBans;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Exceptions\EventHandlerDoesNotExist;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Mockery;

class InterceptsBansTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('livewire_ban_users', function (Blueprint $table): void {
            $table->id();
        });

        (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();
        LivewireBanActionLog::$executed = [];
    }

    public function test_an_allowed_action_executes_and_returns_its_result(): void
    {
        Livewire::actingAs($this->user())
            ->test(MethodLockedComponent::class)
            ->call('postComment', 'Hello')
            ->assertReturned('Hello');

        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_a_global_ban_stops_the_action_before_any_side_effect(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(MethodLockedComponent::class)
            ->call('postComment', 'Blocked')
            ->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
        $this->assertSame('Your account has been suspended.', session('ban_error'));
    }

    public function test_unlocked_actions_remain_available_to_banned_users(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(MethodLockedComponent::class)
            ->call('viewPosts')
            ->assertReturned('posts');

        $this->assertSame(['viewPosts'], LivewireBanActionLog::$executed);
    }

    public function test_guests_are_not_blocked_by_ban_locks(): void
    {
        Livewire::test(MethodLockedComponent::class)->call('postComment', 'Guest')->assertReturned('Guest');
        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_authenticated_users_without_the_bannable_contract_are_allowed(): void
    {
        $user = Mockery::mock(AuthenticatableContract::class);
        Auth::setUser($user);

        Livewire::test(MethodLockedComponent::class)->call('postComment', 'Plain')->assertReturned('Plain');
        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_the_bannable_contract_is_supported_without_the_has_bans_trait(): void
    {
        $user = Mockery::mock(AuthenticatableContract::class, Bannable::class);
        $user->shouldReceive('isBanned')->once()->andReturn(true);
        Auth::setUser($user);

        Livewire::test(MethodLockedComponent::class)->call('postComment', 'Blocked')->assertForbidden();
        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_rebooting_the_provider_does_not_duplicate_action_checks(): void
    {
        $this->app->getProvider(BanServiceProvider::class)->boot();
        $user = Mockery::mock(AuthenticatableContract::class, Bannable::class);
        $user->shouldReceive('isBanned')->once()->andReturn(false);
        Auth::setUser($user);

        Livewire::test(MethodLockedComponent::class)->call('postComment', 'Allowed')->assertReturned('Allowed');
    }

    public function test_a_matching_feature_ban_blocks_the_action(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'comments'))
            ->test(FeatureLockedComponent::class)->call('postComment')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_a_global_ban_also_blocks_a_feature_lock(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(FeatureLockedComponent::class)->call('postComment')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_a_different_feature_ban_does_not_block_the_action(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'forum'))
            ->test(FeatureLockedComponent::class)->call('postComment')->assertReturned('comment');

        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_a_feature_ban_does_not_trigger_a_global_only_lock(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'comments'))
            ->test(MethodLockedComponent::class)->call('postComment', 'Allowed')->assertReturned('Allowed');
    }

    public function test_a_class_lock_applies_to_all_actions(): void
    {
        Livewire::actingAs($this->user(banned: true));
        Livewire::test(ClassLockedComponent::class)->call('postComment', 'Blocked')->assertForbidden();
        Livewire::test(ClassLockedComponent::class)->call('viewPosts')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_a_method_lock_overrides_the_class_feature(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'forum'));
        Livewire::test(MixedLockComponent::class)->call('postComment')->assertReturned('comment');
        Livewire::test(MixedLockComponent::class)->call('postThread')->assertForbidden();

        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_a_method_lock_blocks_even_when_the_class_feature_is_allowed(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'comments'));
        Livewire::test(MixedLockComponent::class)->call('postThread')->assertReturned('thread');
        Livewire::test(MixedLockComponent::class)->call('postComment')->assertForbidden();

        $this->assertSame(['postThread'], LivewireBanActionLog::$executed);
    }

    public function test_inherited_method_locks_are_enforced(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(InheritedMethodComponent::class)->call('postComment', 'Blocked')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_inherited_class_locks_are_enforced(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(InheritedClassComponent::class)->call('viewPosts')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_the_nearest_class_lock_takes_precedence(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'forum'))
            ->test(OverriddenClassComponent::class)->call('postThread')->assertReturned('thread');
    }

    public function test_attributed_event_listeners_cannot_bypass_the_lock(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'comments'))
            ->test(FeatureLockedComponent::class)->dispatch('comment:post')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_allowed_event_listeners_still_execute(): void
    {
        Livewire::actingAs($this->user())
            ->test(FeatureLockedComponent::class)->dispatch('comment:post')->assertSuccessful();

        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_listeners_defined_in_the_listeners_property_are_checked(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'comments'))
            ->test(FeatureLockedComponent::class)->dispatch('legacy:comment')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_event_listeners_honor_the_method_override_of_a_class_lock(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'forum'))
            ->test(MixedLockComponent::class)->dispatch('comment:post')->assertSuccessful();

        $this->assertSame(['postComment'], LivewireBanActionLog::$executed);
    }

    public function test_a_class_lock_blocks_an_event_listener_without_a_method_attribute(): void
    {
        Livewire::actingAs($this->user(banned: true, feature: 'forum'))
            ->test(MixedLockComponent::class)->dispatch('thread:post')->assertForbidden();

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_unknown_events_are_still_rejected_by_livewire(): void
    {
        $this->expectException(EventHandlerDoesNotExist::class);
        Livewire::test(FeatureLockedComponent::class)->dispatch('unknown:event');
    }

    public function test_private_actions_are_not_exposed(): void
    {
        $component = Livewire::test(MethodLockedComponent::class);

        try {
            $component->call('privateAction');
            $this->fail('A private action must not be callable.');
        } catch (MethodNotFoundException) {
            $this->assertSame([], LivewireBanActionLog::$executed);
        }
    }

    public function test_there_is_no_public_call_method_trampoline(): void
    {
        $this->expectException(MethodNotFoundException::class);
        Livewire::test(MethodLockedComponent::class)->call('callMethod', 'privateAction');
    }

    public function test_the_manual_helper_is_not_a_public_livewire_action(): void
    {
        $this->expectException(MethodNotFoundException::class);
        Livewire::test(MethodLockedComponent::class)->call('checkBanLock', 'postComment');
    }

    public function test_the_protected_helper_can_guard_internal_calls(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(MethodLockedComponent::class)->call('manualAction')
            ->assertReturned(null)->assertSee('Your account has been suspended.');

        $this->assertSame([], LivewireBanActionLog::$executed);
    }

    public function test_components_must_opt_in_with_the_trait(): void
    {
        Livewire::actingAs($this->user(banned: true))
            ->test(ComponentWithoutBanTrait::class)->call('post')->assertReturned('posted');
    }

    private function user(bool $banned = false, ?string $feature = null): LivewireBanUser
    {
        $user = LivewireBanUser::create();

        if ($banned) {
            $user->ban(['feature' => $feature]);
        }

        return $user;
    }
}

class LivewireBanUser extends Model implements AuthenticatableContract, Bannable
{
    use Authenticatable, HasBans;

    protected $table = 'livewire_ban_users';

    protected $guarded = [];

    public $timestamps = false;
}

class LivewireBanActionLog
{
    public static array $executed = [];
}

class MethodLockedComponent extends Component
{
    use InterceptsBans;

    #[LockedByBan]
    public function postComment(string $message): string
    {
        LivewireBanActionLog::$executed[] = 'postComment';

        return $message;
    }

    public function viewPosts(): string
    {
        LivewireBanActionLog::$executed[] = 'viewPosts';

        return 'posts';
    }

    public function manualAction(): ?string
    {
        if ($this->checkBanLock('postComment')) {
            return null;
        }

        return $this->postComment('Manual');
    }

    private function privateAction(): void
    {
        LivewireBanActionLog::$executed[] = 'privateAction';
    }

    public function render(): string
    {
        return '<div>Comments {{ session("ban_error") }}</div>';
    }
}

class FeatureLockedComponent extends Component
{
    use InterceptsBans;

    protected $listeners = ['legacy:comment' => 'postComment'];

    #[On('comment:post')]
    #[LockedByBan(feature: 'comments')]
    public function postComment(): string
    {
        LivewireBanActionLog::$executed[] = 'postComment';

        return 'comment';
    }

    public function render(): string
    {
        return '<div>Comments</div>';
    }
}

#[LockedByBan]
class ClassLockedComponent extends MethodLockedComponent {}

#[LockedByBan(feature: 'forum')]
class MixedLockComponent extends FeatureLockedComponent
{
    #[On('thread:post')]
    public function postThread(): string
    {
        LivewireBanActionLog::$executed[] = 'postThread';

        return 'thread';
    }
}

class InheritedMethodComponent extends MethodLockedComponent {}

class InheritedClassComponent extends ClassLockedComponent {}

#[LockedByBan(feature: 'comments')]
class OverriddenClassComponent extends MixedLockComponent {}

#[LockedByBan]
class ComponentWithoutBanTrait extends Component
{
    public function post(): string
    {
        return 'posted';
    }

    public function render(): string
    {
        return '<div>Posts</div>';
    }
}
