<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('ban.table_names.banned_ips', 'banned_ips');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === ['ip_address']) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }

        if (! Schema::hasIndex($tableName, ['ip_address'])) {
            Schema::table($tableName, fn (Blueprint $table) => $table->index('ip_address'));
        }

        // Match existing IPv6 bans regardless of the address's textual spelling.
        DB::table($tableName)->where('ip_address', 'like', '%:%')->orderBy('id')->chunkById(500, function ($bans) use ($tableName): void {
            foreach ($bans as $ban) {
                $packed = @inet_pton($ban->ip_address);

                if ($packed !== false) {
                    DB::table($tableName)->where('id', $ban->id)->update(['ip_address' => inet_ntop($packed)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately keep the non-unique index: subsequent scoped bans or ban
        // history may share an IP. Restoring uniqueness would fail or lose data.
    }
};
