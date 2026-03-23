<?php

declare(strict_types=1);

use Godrade\LaravelBan\BanServiceProvider;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

/**
 * Minimal stub representing a "Preset" model (e.g. a ban template).
 */
class Preset extends Model
{
    protected $table = 'presets';
    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot the ServiceProvider with a custom config so we can test dynamic
 * relation injection without touching the real application config.
 */
function bootProviderWithRelations(array $relations): void
{
    config()->set('ban.relations', $relations);
    config()->set('ban.reserved_relations', ['bannable', 'createdBy', 'cause']);

    // Re-boot the provider so bootDynamicRelations() picks up the new config.
    (new BanServiceProvider(app()))->boot();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Dynamic Relations', function () {

    beforeEach(function () {
        // Create the bans table (minimal schema, no soft deletes needed here)
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->morphs('bannable');
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('cause');
            $table->string('feature')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('active');
            $table->unsignedBigInteger('preset_id')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        // Create the presets table so Eloquent can resolve the relation
        Schema::create('presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    });

    afterEach(function () {
        Schema::dropIfExists('bans');
        Schema::dropIfExists('presets');
    });

    it('resolves a configured belongsTo relation on the Ban model', function () {
        bootProviderWithRelations([
            'preset' => [
                'type'        => 'belongsTo',
                'related'     => Preset::class,
                'foreign_key' => 'preset_id',
            ],
        ]);

        $ban = new Ban();

        expect($ban->preset())->toBeInstanceOf(BelongsTo::class)
            ->and($ban->preset()->getRelated())->toBeInstanceOf(Preset::class);
    });

    it('does not register a relation whose name is reserved', function () {
        bootProviderWithRelations([
            'bannable' => [
                'type'    => 'belongsTo',
                'related' => Preset::class,
            ],
        ]);

        // The original bannable() is a morphTo, not a belongsTo — if the
        // reserved guard works, it must remain a MorphTo.
        $ban = new Ban();

        expect($ban->bannable())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
    });

    it('skips and logs an error when the related class does not exist', function () {
        // Should not throw; just log an error and skip.
        expect(fn () => bootProviderWithRelations([
            'ghost' => [
                'type'    => 'belongsTo',
                'related' => 'App\\Models\\NonExistentModel',
            ],
        ]))->not->toThrow(\Throwable::class);

        // The relation must not have been registered
        $ban = new Ban();
        expect(fn () => $ban->ghost())->toThrow(\BadMethodCallException::class);
    });

    it('resolves multiple dynamic relations simultaneously', function () {
        // Add a second stub
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
        });

        eval('class Report extends \Illuminate\Database\Eloquent\Model { protected $table = "reports"; public $timestamps = false; }');

        bootProviderWithRelations([
            'preset' => [
                'type'        => 'belongsTo',
                'related'     => Preset::class,
                'foreign_key' => 'preset_id',
            ],
            'report' => [
                'type'    => 'belongsTo',
                'related' => \Report::class,
            ],
        ]);

        $ban = new Ban();

        expect($ban->preset())->toBeInstanceOf(BelongsTo::class)
            ->and($ban->report())->toBeInstanceOf(BelongsTo::class);

        Schema::dropIfExists('reports');
    });

});

describe('Cause polymorphic relation', function () {

    beforeEach(function () {
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->morphs('bannable');
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('cause');
            $table->string('feature')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    });

    afterEach(fn () => Schema::dropIfExists('bans'));

    it('exposes a cause() morphTo relation on the Ban model', function () {
        $ban = new Ban();

        expect($ban->cause())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
    });

    it('stores and retrieves the cause polymorphic columns', function () {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('body');
        });

        eval('
            if (!class_exists("ReportModel")) {
                class ReportModel extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "reports";
                    public $timestamps = false;
                    protected $guarded = [];
                }
            }
        ');

        $report = \ReportModel::create(['body' => 'Abusive content']);

        $ban = Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id'   => 1,
            'cause_type'    => \ReportModel::class,
            'cause_id'      => $report->id,
        ]);

        expect($ban->cause_type)->toBe(\ReportModel::class)
            ->and($ban->cause_id)->toBe($report->id);

        Schema::dropIfExists('reports');
    });

});
