<?php

declare(strict_types=1);

use Godrade\LaravelBan\BanServiceProvider;
use Godrade\LaravelBan\Models\Ban;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
        (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();
        Schema::table('bans', function (Blueprint $table) {
            $table->unsignedBigInteger('preset_id')->nullable();
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
                'type' => 'belongsTo',
                'related' => Preset::class,
                'foreign_key' => 'preset_id',
            ],
        ]);

        $ban = new Ban;

        expect($ban->preset())->toBeInstanceOf(BelongsTo::class)
            ->and($ban->preset()->getRelated())->toBeInstanceOf(Preset::class);
    });

    it('loads an inferred foreign key through a dynamic relation', function () {
        bootProviderWithRelations(['preset' => ['related' => Preset::class]]);
        $preset = Preset::forceCreate(['name' => 'Moderation']);
        $ban = Ban::create(['bannable_type' => 'user', 'bannable_id' => 1, 'preset_id' => $preset->id]);

        expect($ban->fresh()->preset?->id)->toBe($preset->id);
        expect(Ban::with('preset')->find($ban->id)->preset?->id)->toBe($preset->id);
    });

    it('preserves owner_key position when foreign_key is omitted', function () {
        bootProviderWithRelations(['preset' => ['related' => Preset::class, 'owner_key' => 'name']]);
        $relation = (new Ban)->preset();

        expect($relation->getForeignKeyName())->toBe('preset_id')
            ->and($relation->getOwnerKeyName())->toBe('name');
    });

    it('rejects invalid types and non-model related classes', function () {
        bootProviderWithRelations([
            'invalidType' => ['related' => Preset::class, 'type' => 'delete'],
            'invalidModel' => ['related' => stdClass::class],
        ]);

        expect(fn () => (new Ban)->invalidType())->toThrow(BadMethodCallException::class)
            ->and(fn () => (new Ban)->invalidModel())->toThrow(BadMethodCallException::class);
    });

    it('does not register a relation whose name is reserved', function () {
        bootProviderWithRelations([
            'bannable' => [
                'type' => 'belongsTo',
                'related' => Preset::class,
            ],
        ]);

        // The original bannable() is a morphTo, not a belongsTo — if the
        // reserved guard works, it must remain a MorphTo.
        $ban = new Ban;

        expect($ban->bannable())->toBeInstanceOf(MorphTo::class);
    });

    it('skips and logs an error when the related class does not exist', function () {
        // Should not throw; just log an error and skip.
        expect(fn () => bootProviderWithRelations([
            'ghost' => [
                'type' => 'belongsTo',
                'related' => 'App\\Models\\NonExistentModel',
            ],
        ]))->not->toThrow(Throwable::class);

        // The relation must not have been registered
        $ban = new Ban;
        expect(fn () => $ban->ghost())->toThrow(BadMethodCallException::class);
    });

    it('resolves multiple dynamic relations simultaneously', function () {
        // Add a second stub
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
        });

        eval('class Report extends \Illuminate\Database\Eloquent\Model { protected $table = "reports"; public $timestamps = false; }');

        bootProviderWithRelations([
            'preset' => [
                'type' => 'belongsTo',
                'related' => Preset::class,
                'foreign_key' => 'preset_id',
            ],
            'report' => [
                'type' => 'belongsTo',
                'related' => Report::class,
            ],
        ]);

        $ban = new Ban;

        expect($ban->preset())->toBeInstanceOf(BelongsTo::class)
            ->and($ban->report())->toBeInstanceOf(BelongsTo::class);

        Schema::dropIfExists('reports');
    });

});

describe('Cause polymorphic relation', function () {

    beforeEach(function () {
        (require __DIR__.'/../../database/migrations/2024_01_01_000001_create_bans_table.php')->up();
    });

    afterEach(fn () => Schema::dropIfExists('bans'));

    it('exposes a cause() morphTo relation on the Ban model', function () {
        $ban = new Ban;

        expect($ban->cause())->toBeInstanceOf(MorphTo::class);
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

        $report = ReportModel::create(['body' => 'Abusive content']);

        $ban = Ban::create([
            'bannable_type' => 'App\\Models\\User',
            'bannable_id' => 1,
            'cause_type' => ReportModel::class,
            'cause_id' => $report->id,
        ]);

        expect($ban->cause_type)->toBe(ReportModel::class)
            ->and($ban->cause_id)->toBe($report->id);

        Schema::dropIfExists('reports');
    });

});
