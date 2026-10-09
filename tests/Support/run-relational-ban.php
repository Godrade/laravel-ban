<?php

declare(strict_types=1);

use Godrade\LaravelBan\BanServiceProvider;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Tests\Support\RelationalBanUser;
use Godrade\LaravelBan\Tests\Support\RelationalDatabase;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create();
$app->register(BanServiceProvider::class);
config(RelationalDatabase::settings());
config(['ban.cache_ttl' => 0]);

$user = RelationalBanUser::findOrFail($argv[2]);
Ban::creating(static function (): void {
    // Keep the parent row locked while the other process attempts its mutation.
    usleep(200000);
});

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $user->{$argv[1]}(['reason' => 'concurrent change']);
    fwrite(STDOUT, "saved\n");
} catch (AlreadyBannedException) {
    fwrite(STDOUT, "already banned\n");
}
