<?php

declare(strict_types=1);

namespace LogHQ\Laravel;

use LogHQ\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler backing the `loghq` log channel: every record at or above the
 * channel level is shipped to loghq as a log entry, carrying its Monolog
 * channel name as the loghq `channel`, plus the record's context/extra and any
 * ambient request/user context.
 *
 * Unlike an error tracker, this handler does NOT special-case exceptions or
 * severity - a log is a log. A Throwable in `context['exception']` is flattened
 * to a readable string by the core client so the stream stays readable.
 */
final class LogHandler extends AbstractProcessingHandler
{
    /** @var callable(): array<string, mixed> */
    private $ambientContext;

    /**
     * @param callable(): array<string, mixed> $ambientContext evaluated fresh
     *        per record so a long-lived worker never leaks one request's
     *        identity into the next.
     */
    public function __construct(private readonly Client $client, callable $ambientContext, int|string|Level $level = Level::Debug)
    {
        parent::__construct($level, true);
        $this->ambientContext = $ambientContext;
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        // Union (+=) preserves caller-supplied keys: an explicit
        // context['channel'] wins over the Monolog logger name, and ambient
        // request/user never clobber the app's own context.
        $context += ['channel' => $record->channel];
        if ($record->extra !== []) {
            $context += ['extra' => $record->extra];
        }
        $context += ($this->ambientContext)();

        $this->client->log($record->level->toPsrLogLevel(), $record->message, $context);
    }
}
