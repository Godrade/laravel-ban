<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('ban.table_names.bans', 'bans');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            // Polymorphic bannable subject (e.g. App\Models\User)
            $table->morphs('bannable');

            // Optional: who created the ban (polymorphic to support any admin model)
            $table->nullableMorphs('created_by');

            // Optional: the cause / trigger of the ban (report, ticket, rule…)
            $table->nullableMorphs('cause');

            // Scoped ban: null means global, a string limits the ban to one feature
            $table->string('feature')->nullable()->index();

            // Human-readable reason for the ban
            $table->text('reason')->nullable();

            // Status: 'active' (enforced) or 'cancelled' (manually lifted via unban())
            // Expiry is a calculated state, not a status value.
            $table->string('status', 50)->default(config('ban.statuses.default', 'active'))->index();

            // Expiration: null means permanent
            $table->timestamp('expired_at')->nullable()->index();

            $table->timestamps();

            if (config('ban.soft_delete', true)) {
                $table->softDeletes();
            }

            // Composite index for fast "is this model banned?" lookups
            $table->index(['bannable_type', 'bannable_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ban.table_names.bans', 'bans'));
    }
};
