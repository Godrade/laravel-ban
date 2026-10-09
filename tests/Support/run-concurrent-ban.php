<?php

declare(strict_types=1);

use Godrade\LaravelBan\BanServiceProvider;
use Godrade\LaravelBan\Exceptions\AlreadyBannedException;
use Godrade\LaravelBan\Models\Ban;
use Godrade\LaravelBan\Tests\Support\ConcurrentBanUser;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = Application::create();
$app->register(BanServiceProvider::class);
config([
    'database.default' => 'concurrency',
    'database.connections.concurrency' => [
        'driver' => 'sqlite',
        'database' => $argv[1],
        'prefix' => '',
        'options' => [PDO::ATTR_TIMEOUT => 5],
    ],
    'ban.cache_ttl' => 0,
]);

$user = ConcurrentBanUser::findOrFail(1);
Ban::creating(static function (): void {
    // Hold the write transaction briefly so the other process contends for it.
    usleep(100000);
});

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $user->{$argv[2]}();
    fwrite(STDOUT, "saved\n");
} catch (AlreadyBannedException) {
    fwrite(STDOUT, "already banned\n");
}
