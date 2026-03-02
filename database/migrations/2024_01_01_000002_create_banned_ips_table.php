<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('ban.table_names.banned_ips', 'banned_ips');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            // The IP address being banned (supports both IPv4 and IPv6)
            $table->string('ip_address', 45)->unique()->index();

            // Scoped ban: null means all features, a string limits to one feature
            $table->string('feature')->nullable()->index();

            $table->text('reason')->nullable();

            // Optional: who created the ban
            $table->nullableMorphs('created_by');

            // Expiration: null means permanent
            $table->timestamp('expired_at')->nullable()->index();

            $table->timestamps();

            if (config('ban.soft_delete', true)) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('ban.table_names.banned_ips', 'banned_ips'));
    }
};
