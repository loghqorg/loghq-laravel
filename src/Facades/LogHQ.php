<?php

declare(strict_types=1);

namespace LogHQ\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use LogHQ\Client;

/**
 * @method static bool log(string $level, string $message, array $context = [])
 * @method static bool debug(string $message, array $context = [])
 * @method static bool info(string $message, array $context = [])
 * @method static bool notice(string $message, array $context = [])
 * @method static bool warning(string $message, array $context = [])
 * @method static bool error(string $message, array $context = [])
 * @method static bool critical(string $message, array $context = [])
 * @method static bool alert(string $message, array $context = [])
 * @method static bool emergency(string $message, array $context = [])
 * @method static void withContext(array $context)
 * @method static bool flush()
 *
 * @see Client
 */
final class LogHQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
