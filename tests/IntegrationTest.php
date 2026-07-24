<?php

declare(strict_types=1);

namespace LogHQ\Laravel\Tests;

use LogHQ\Client;
use LogHQ\Laravel\Facades\LogHQ;
use Illuminate\Support\Facades\Log;

final class IntegrationTest extends TestCase
{
    public function testClientIsBoundAsASingletonWithLaravelIdentity(): void
    {
        $client = $this->app->make(Client::class);

        self::assertSame($client, $this->app->make(Client::class));
        self::assertSame($client, $this->app->make('loghq'));
        self::assertSame('demo', $client->config->project);
        self::assertSame('laravel', $client->config->framework);
        self::assertSame('loghq.laravel', $client->config->sdkName);
    }

    public function testLoghqChannelShipsLogRecords(): void
    {
        Log::channel('loghq')->info('cache warmed', ['keys' => 12]);

        self::assertNotEmpty($this->transport->batches);
        $entry = $this->transport->last();
        self::assertSame('info', $entry['level']);
        self::assertSame('cache warmed', $entry['message']);
        self::assertSame('loghq', $entry['channel']);
        self::assertSame(12, $entry['context']['keys']);
        self::assertSame('laravel', $entry['framework']);
        self::assertSame('loghq.laravel', $entry['sdk']['name']);
    }

    public function testAllLevelsAreShippedNotJustErrors(): void
    {
        // The defining difference from an error tracker: debug/info/notice are
        // first-class, not dropped.
        Log::channel('loghq')->debug('a debug line');
        Log::channel('loghq')->info('an info line');
        Log::channel('loghq')->notice('a notice line');
        Log::channel('loghq')->warning('a warning line');

        $levels = array_column($this->transport->entries(), 'level');
        self::assertSame(['debug', 'info', 'notice', 'warning'], $levels);
    }

    public function testExceptionInContextIsFlattenedToString(): void
    {
        Log::channel('loghq')->error('payment failed', ['exception' => new \RuntimeException('gateway down')]);

        $entry = $this->transport->last();
        self::assertSame('error', $entry['level']);
        self::assertIsString($entry['context']['exception']);
        self::assertStringContainsString('RuntimeException', $entry['context']['exception']);
        self::assertStringContainsString('gateway down', $entry['context']['exception']);
    }

    public function testExplicitChannelInContextWins(): void
    {
        Log::channel('loghq')->info('billing event', ['channel' => 'billing', 'amount' => 5]);

        $entry = $this->transport->last();
        self::assertSame('billing', $entry['channel']);
        self::assertSame(5, $entry['context']['amount']);
    }

    public function testLoghqChannelStillResolvesWhenDisabled(): void
    {
        $this->app['config']->set('loghq.enabled', false);
        $this->app['config']->set('logging.channels.loghq', ['driver' => 'loghq', 'level' => 'debug']);

        // Re-boot the provider with the disabled config: the driver must still
        // be registered so channels referencing it keep resolving.
        (new \LogHQ\Laravel\LogHQServiceProvider($this->app))->boot();

        $logger = Log::channel('loghq');
        $logger->info('dropped silently');

        self::assertNotNull($logger);
    }

    public function testTapStackAppendsLoghqToTheDefaultStack(): void
    {
        $this->app['config']->set('logging.default', 'stack');
        $this->app['config']->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single']]);
        $this->app['config']->set('loghq.tap_stack', true);

        (new \LogHQ\Laravel\LogHQServiceProvider($this->app))->boot();

        $channels = $this->app['config']->get('logging.channels.stack.channels');
        self::assertContains('loghq', $channels);
        self::assertContains('single', $channels, 'existing channels are preserved');
    }

    public function testTapStackLeavesANonStackDefaultAlone(): void
    {
        $this->app['config']->set('logging.default', 'single');
        $this->app['config']->set('logging.channels.single', ['driver' => 'single']);
        $this->app['config']->set('loghq.tap_stack', true);

        (new \LogHQ\Laravel\LogHQServiceProvider($this->app))->boot();

        // A non-stack default is not mutated - the app opts in explicitly.
        self::assertSame(['driver' => 'single'], $this->app['config']->get('logging.channels.single'));
    }

    public function testDsnOnlyConfigKeepsTheDsnHost(): void
    {
        $this->app['config']->set('loghq', array_merge($this->app['config']['loghq'], [
            'dsn' => 'https://loghq_abc@logs.selfhosted.dev/acme-api',
            'project' => null,
            'key' => null,
            'host' => null,
        ]));

        (new \LogHQ\Laravel\LogHQServiceProvider($this->app))->register();
        $this->app->forgetInstance(Client::class);
        $client = $this->app->make(Client::class);

        self::assertSame('https://logs.selfhosted.dev', $client->config->host);
        self::assertSame('acme-api', $client->config->project);
        self::assertSame('loghq_abc', $client->config->key);
        self::assertTrue($client->config->enabled);
    }

    public function testFacadeProxiesToTheClient(): void
    {
        LogHQ::warning('via facade', ['plan' => 'pro']);

        $entry = $this->transport->last();
        self::assertSame('via facade', $entry['message']);
        self::assertSame('warning', $entry['level']);
        self::assertSame('pro', $entry['context']['plan']);
    }

    public function testConfigIsPublishableWithExpectedDefaults(): void
    {
        self::assertSame('demo', $this->app['config']['loghq.project']);
        self::assertFalse((bool) $this->app['config']['loghq.tap_stack']);
        self::assertTrue((bool) $this->app['config']['loghq.context.request']);
    }
}
