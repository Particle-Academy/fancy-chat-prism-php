<?php

declare(strict_types=1);

namespace FancyChat\Prism\Tests\Support;

use FancyChat\FancyChatServiceProvider;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Broadcasting\BroadcastServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismServiceProvider;

/**
 * A hand-built Laravel 13 kernel, booted directly with no orchestra/testbench,
 * the same pattern fancy-chat-php's own suite uses.
 *
 * In-memory SQLite, FORCED: the database config is written here and nothing
 * from the environment replaces it, so a run under a Postgres env never touches
 * a real database. Every provider API key is blanked, so no test can reach a
 * paid model even by accident; the Harness integration test uses Prism's fake.
 * The queue is `sync`, so a turn runs inside the request that started it.
 */
final class TestApp
{
    public const array PROVIDER_KEYS = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'GROQ_API_KEY',
        'XAI_API_KEY', 'DEEPSEEK_API_KEY', 'OPENROUTER_API_KEY', 'VOYAGEAI_API_KEY', 'ELEVENLABS_API_KEY',
        'PERPLEXITY_API_KEY', 'Z_API_KEY',
    ];

    /** @param  array<string, mixed>  $config  dotted keys merged over the defaults */
    public static function boot(array $config = [], bool $harness = false): Application
    {
        foreach (self::PROVIDER_KEYS as $key) {
            putenv($key.'=');
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-prism-tests';
        foreach (['/config', '/storage', '/storage/framework/cache'] as $dir) {
            if (! is_dir($base.$dir)) {
                mkdir($base.$dir, 0777, true);
            }
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        $app['env'] = 'testing';
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]],
            'auth' => ['defaults' => ['guard' => 'web'], 'guards' => [], 'providers' => []],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'broadcasting' => ['default' => 'null', 'connections' => ['null' => ['driver' => 'null']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]],
            // Every provider key blank, whatever the environment says.
            'prism' => ['providers' => array_fill_keys(['anthropic', 'openai', 'gemini', 'mistral', 'groq', 'xai', 'deepseek', 'openrouter'], ['api_key' => ''])],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, DatabaseServiceProvider::class, CacheServiceProvider::class,
            BusServiceProvider::class, QueueServiceProvider::class, BroadcastServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app['router']->middlewareGroup('web', []);
        $app->register(FancyChatServiceProvider::class);
        $vendor = dirname(__DIR__, 2).'/vendor/particle-academy';
        self::migrate($vendor.'/fancy-chat-php/database/migrations');
        if ($harness) {
            $app->register(AuthServiceProvider::class);
            $app->register(PrismServiceProvider::class);
            $app->register(PrismHarnessServiceProvider::class);
            self::migrate($vendor.'/prism-harness/database/migrations');
        }
        $app->boot();

        return $app;
    }

    private static function migrate(string $directory): void
    {
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
