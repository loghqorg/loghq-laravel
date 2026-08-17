<?php

declare(strict_types=1);

namespace LogHQ\Laravel\Tests;

use LogHQ\Client;
use LogHQ\Laravel\LogHQServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected MockTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [LogHQServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('loghq.project', 'demo');
        $app['config']->set('loghq.key', 'loghq_test');
        // Explicit, and not the SDK default, so these tests keep asserting a
        // host the config actually carried rather than silently tracking
        // whatever Config::DEFAULT_HOST happens to be.
        $app['config']->set('loghq.host', 'http://127.0.0.1:3008');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Swap the transport so tests capture batches instead of sending, and
        // ship each record immediately (batchSize 1) so assertions don't have
        // to flush by hand.
        $this->transport = new MockTransport();
        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']['loghq'];

            return new Client([
                'project' => $config['project'],
                'key' => $config['key'],
                'host' => $config['host'],
                'environment' => 'testing',
                'framework' => 'laravel',
                'sdkName' => 'loghq.laravel',
                'batchSize' => 1,
            ], $this->transport);
        });
    }
}
