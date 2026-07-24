<?php

declare(strict_types=1);

namespace LogHQ\Laravel;

use Illuminate\Support\ServiceProvider;
use LogHQ\Client;
use Monolog\Logger;

/**
 * Wires loghq into Laravel as a `loghq` log channel that streams records to the
 * loghq ingest - configured from `config/loghq.php` / `.env`.
 *
 * Two ways to feed it:
 *   1. Add 'loghq' to a stack in config/logging.php, or set LOG_CHANNEL=loghq.
 *   2. Set LOGHQ_TAP_STACK=true and loghq appends itself to your default stack,
 *      shipping every existing Log::* call with no code changes.
 */
class LogHQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/loghq.php', 'loghq');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']['loghq'] ?? [];

            $client = new Client(array_filter([
                'dsn' => $config['dsn'] ?? null,
                'project' => $config['project'] ?? null,
                'key' => $config['key'] ?? null,
                'host' => $config['host'] ?? null,
                'release' => $config['release'] ?? null,
                'environment' => $config['environment'] ?? $app->environment(),
                'enabled' => (bool) ($config['enabled'] ?? true),
                'minLevel' => $config['level'] ?? 'debug',
                'sampleRate' => (float) ($config['sample_rate'] ?? 1.0),
                'batchSize' => (int) ($config['batch_size'] ?? 25),
                'flushLevel' => $config['flush_level'] ?? 'error',
                'framework' => 'laravel',
                'sdkName' => 'loghq.laravel',
            ], static fn ($v) => $v !== null));

            // Share the container's client with the SDK's plain static API so
            // \LogHQ\LogHQ::info() works in Laravel too, instead of silently
            // no-oping (the static class is never init()ed here).
            return \LogHQ\LogHQ::useClient($client);
        });

        $this->app->alias(Client::class, 'loghq');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/loghq.php' => $this->app->configPath('loghq.php'),
        ], 'loghq-config');

        // Make `Log::channel('loghq')` resolve out of the box - no need to add a
        // channel to config/logging.php by hand.
        $repo = $this->app['config'];
        if (!$repo->has('logging.channels.loghq')) {
            $repo->set('logging.channels.loghq', [
                'driver' => 'loghq',
                'level' => $repo->get('loghq.level', 'debug'),
            ]);
        }

        // The `loghq` channel driver is registered UNCONDITIONALLY - a logging
        // config referencing it must keep resolving when loghq is disabled (the
        // disabled Client just drops everything), otherwise Laravel substitutes
        // the emergency logger for the whole channel.
        //
        // extend() rebinds the callback's $this to the LogManager, so we capture
        // a provider-bound closure for the ambient context rather than using
        // $this inside the callback.
        $ambient = fn (): array => $this->ambientContext();
        $this->app->make('log')->extend('loghq', function ($app, array $channelConfig) use ($ambient): Logger {
            $handler = new LogHandler(
                $app->make(Client::class),
                $ambient,
                $channelConfig['level'] ?? 'debug',
            );

            return new Logger('loghq', [$handler]);
        });

        $config = $this->app['config']['loghq'] ?? [];
        if (!($config['enabled'] ?? true)) {
            return;
        }

        if ($config['tap_stack'] ?? false) {
            $this->tapDefaultStack();
        }

        // Long-running hosts reuse one Client - flush per unit of work so a
        // request/job's buffered lines ship even when the process lives on, and
        // never carry into the next unit.
        $this->flushPerUnitOfWork();
    }

    /**
     * Append the loghq channel to the app's default logging stack so existing
     * Log::* calls are also shipped. Idempotent, and only touches a `stack`
     * driver - never a single/daily channel the app set as its default.
     */
    private function tapDefaultStack(): void
    {
        $config = $this->app['config'];

        // The `loghq` channel is already defined in boot(), so it resolves.
        $default = $config->get('logging.default');
        $channelKey = "logging.channels.$default";
        $channel = $config->get($channelKey);

        if (!\is_array($channel) || ($channel['driver'] ?? null) !== 'stack') {
            // Default isn't a stack (e.g. 'single'): wrap nothing - the app must
            // add 'loghq' explicitly. Tapping a non-stack safely no-ops.
            return;
        }

        $channels = $channel['channels'] ?? [];
        if (!\in_array('loghq', $channels, true)) {
            $channels[] = 'loghq';
            $config->set("$channelKey.channels", $channels);
        }
    }

    private function flushPerUnitOfWork(): void
    {
        $flush = function (): void {
            $this->app->make(Client::class)->flush();
        };

        // Ship a request's buffered lines when the response is done.
        $this->app['events']->listen('Illuminate\Foundation\Http\Events\RequestHandled', $flush);
        // Queue workers: flush after each job (processed or failed).
        $this->app['events']->listen('Illuminate\Queue\Events\JobProcessed', $flush);
        $this->app['events']->listen('Illuminate\Queue\Events\JobFailed', $flush);
        // Octane: flush at the end of each request the worker serves.
        $this->app['events']->listen('Laravel\Octane\Events\RequestTerminated', $flush);
    }

    /**
     * Ambient request + authenticated-user context, evaluated fresh per record.
     *
     * @return array<string, mixed>
     */
    private function ambientContext(): array
    {
        $config = $this->app['config']['loghq']['context'] ?? [];
        $out = [];

        if (($config['request'] ?? true) && $this->hasRequest()) {
            try {
                $request = $this->app->make('request');
                $route = $request->route();
                $out['request'] = array_filter([
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'route' => $route?->getName() ?? $route?->uri(),
                    'ip' => $request->ip(),
                    'userAgent' => $request->userAgent(),
                ], static fn ($v) => $v !== null);
            } catch (\Throwable) {
                // logging must never break the app
            }
        }

        if ($config['user'] ?? true) {
            try {
                $guard = $this->app->make('auth')->guard();
                $user = $guard->check() ? $guard->user() : null;
                if ($user !== null) {
                    $out['user'] = array_filter([
                        'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
                        'email' => $user->email ?? null,
                    ], static fn ($v) => $v !== null);
                }
            } catch (\Throwable) {
                // no auth configured - skip
            }
        }

        return $out;
    }

    private function hasRequest(): bool
    {
        // Octane workers run with a CLI SAPI, so runningInConsole() is true
        // there even though a real HTTP request is bound - only treat plain
        // artisan/queue processes as console.
        $isOctane = isset($_SERVER['LARAVEL_OCTANE']);

        return $this->app->bound('request') && !($this->app->runningInConsole() && !$isOctane);
    }
}
