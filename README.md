# loghq for Laravel

[![CI](https://github.com/loghqorg/loghq-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/loghqorg/loghq-laravel/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-111827.svg)](./LICENSE)

Streams a Laravel application's logs into [loghq](https://loghq.org) as a
`loghq` log channel. Every `Log::info(...)` and `Log::error(...)` you already
have becomes a searchable, correlated entry, without touching a call site.

This is a log shipper, not an error tracker. All eight RFC 5424 severities are
first class: `debug` and `info` are shipped exactly like `emergency` is, because
the line before the failure is usually the one you need.

## Install

```sh
composer require loghq/loghq-laravel
```

> [!IMPORTANT]
> The transport client `loghq/loghq` is not on Packagist yet, and composer only
> reads `repositories` from the root package, so the command above fails with
> `loghq/loghq ... could not be found` until you add this to your application's
> `composer.json`:
>
> ```json
> "repositories": [
>     {
>         "type": "vcs",
>         "url": "https://github.com/loghqorg/loghq-php"
>     }
> ]
> ```
>
> Drop that block once `loghq/loghq` is submitted to Packagist.

The service provider and the `LogHQ` facade are auto-discovered. Publishing the
config is optional, since every setting reads from `.env`:

```sh
php artisan vendor:publish --tag=loghq-config
```

## Quick start

Set a key. That is the whole configuration: the key identifies the project, and
the SDK owns where entries go, so there is no endpoint to set. It is a public,
revocable identifier rather than a secret, so it is safe in `.env` and in app
config:

```dotenv
LOGHQ_KEY=loghq_your_project_key
```

Then send records to the channel, in whichever of these three suits you.

**Add it to your stack.** Most common, and explicit about what ships:

```php
// config/logging.php
'stack' => [
    'driver' => 'stack',
    'channels' => ['single', 'loghq'],
],
```

**Make it the default channel:**

```dotenv
LOG_CHANNEL=loghq
```

**Or let the package append itself** to whatever your default stack already is:

```dotenv
LOGHQ_TAP_STACK=true
```

`tap_stack` is off by default, and deliberately: installing a package should
never silently start streaming your logs off-box. It also only ever touches a
`stack` driver. If your default channel is `single` or `daily`, it does nothing
rather than quietly wrapping it.

You do not have to define the channel yourself. The package registers a `loghq`
channel when the application has not defined one. If you do define one, yours
wins, which is the hook for pinning its level:

```php
// config/logging.php
'loghq' => [
    'driver' => 'loghq',
    'level' => env('LOG_LEVEL', 'debug'),
],
```

## Configuration

Everything is env-driven. These are the defaults.

| Variable | Default | What it does |
| --- | --- | --- |
| `LOGHQ_KEY` | none | Project ingest key. **Without it the client disables itself silently.** |
| `LOGHQ_DSN` | none | `https://<key>@<host>/<project>` instead of the three parts separately. |
| `LOGHQ_HOST` | SDK default | Leave unset. Only for a self-hosted loghq. |
| `LOGHQ_PROJECT` | none | Optional. The key alone already identifies the project. |
| `LOGHQ_ENABLED` | `true` | Master switch. `false` keeps the channel resolving and drops every record. |
| `LOGHQ_LEVEL` | `debug` | SDK-side severity floor, applied after the channel's own `level`. |
| `LOGHQ_SAMPLE_RATE` | `1.0` | Keep this fraction of records. For thinning a very high volume stream. |
| `LOGHQ_BATCH_SIZE` | `25` | Buffer this many records before shipping. |
| `LOGHQ_FLUSH_LEVEL` | `error` | Ship immediately at this severity and above, whatever the buffer holds. |
| `LOGHQ_TAP_STACK` | `false` | Append `loghq` to the default stack automatically. |
| `LOGHQ_ENVIRONMENT` | app env | Reported on every entry. |
| `LOGHQ_RELEASE` | none | Version or commit, reported on every entry. |

Explicit options beat DSN parts, so a `LOGHQ_HOST` alongside a `LOGHQ_DSN`
overrides the host embedded in the DSN.

## What gets sent

`Log::channel('loghq')->info('cache warmed', ['keys' => 12])` arrives as:

```json
{
  "level": "info",
  "message": "cache warmed",
  "channel": "loghq",
  "context": { "keys": 12 },
  "environment": "production",
  "framework": "laravel",
  "host": "web-01",
  "timestamp": "2026-08-17T12:56:51.066Z",
  "sdk": { "name": "loghq.laravel", "version": "0.1.0" }
}
```

`channel` defaults to the Monolog channel name. An explicit `channel` in your
context wins, which is how you label a source properly:

```php
Log::info('charged', ['channel' => 'billing', 'amount' => 4200]);
```

A `Throwable` under `context['exception']` is flattened to a readable string
rather than being dropped or serialized into noise. Monolog's `extra` array, if
any processor filled it, arrives under `context['extra']`.

### Redaction

Context keys that look like credentials are replaced with `[redacted]` before
anything leaves the process, recursively through nested arrays:

`password`, `passwd`, `secret`, `token`, `api_key`, `apikey`, `authorization`,
`auth`, `cookie`, `credential`, `private_key`, `access_key`, `session_id`

This matches on the key, not the value, so a secret logged as part of a message
string is not caught. Redaction is a safety net for context you forgot about,
not a licence to log credentials.

### Ambient context

Each entry also carries the current request and authenticated user:

```json
"request": { "method": "POST", "url": "https://api.example.com/orders",
             "route": "orders.store", "ip": "203.0.113.4", "userAgent": "..." },
"user": { "id": 8821, "email": "jane@acme.com" }
```

Both are on by default and independently switchable:

```php
// config/loghq.php
'context' => ['request' => true, 'user' => false],
```

This is evaluated fresh for every record, not once per process, so a long-lived
queue worker never attributes one job's lines to the previous job's user. Your
own context keys are never overwritten by it.

## Flushing

Records are buffered and shipped in batches, so a request that logs six lines
makes one HTTP call rather than six. The buffer is flushed:

- when it reaches `LOGHQ_BATCH_SIZE`
- immediately on any record at `LOGHQ_FLUSH_LEVEL` or above
- at the end of each HTTP request (`RequestHandled`)
- after each queued job, processed or failed (`JobProcessed`, `JobFailed`)
- at the end of each Octane request (`RequestTerminated`)

The last three matter on long-lived processes. A Horizon worker would otherwise
hold a partial batch until it happened to fill, which could be minutes, or carry
one job's lines into the next.

To flush by hand:

```php
LogHQ::flush();
```

## Logging directly

The channel covers the common case. The facade is there for when you want to
reach loghq without going through Laravel's logging stack, including the four
severities PSR-3 has and most apps rarely use:

```php
use LogHQ\Laravel\Facades\LogHQ;

LogHQ::info('import finished', ['rows' => 12_400]);
LogHQ::critical('replica lag over threshold', ['lagMs' => 9400]);
LogHQ::withContext(['tenant' => 'acme']);   // added to everything after it
LogHQ::flush();
```

Every level method returns `bool`: `true` when the record was accepted, `false`
when it was dropped because the client is disabled, the severity is below the
floor, or sampling discarded it. That return value is the cheapest way to check
your wiring on a server:

```sh
php artisan tinker --execute="var_dump(app(\LogHQ\Client::class)->error('probe'));"
```

The same client is available from the container as `LogHQ\Client` or `loghq`,
and it is the same instance the log channel uses.

## Failure behaviour

Logging must never be the reason a request fails, so:

- A missing key disables the client. Nothing throws, and nothing is sent.
- `LOGHQ_ENABLED=false` still leaves the `loghq` channel resolving. A logging
  config that references a channel which no longer exists would otherwise make
  Laravel substitute the emergency logger for the whole stack.
- Transport errors are contained. Your other channels are unaffected.

The tradeoff is that a misconfiguration is quiet. If loghq shows nothing, check
the return value of a direct call as shown above before digging further.

## Testing

```sh
composer install
composer test
```

The suite runs against real Laravel via `orchestra/testbench`, with the
transport swapped for a capture double, so assertions are made on the exact
payload that would have gone over the wire.

`loghq/loghq` resolves from its GitHub repository, the same way CI and any
consuming application get it. To work on both packages at once, point composer
at a local checkout without committing that:

```sh
composer config repositories.local path ../loghq-php
composer update loghq/loghq
# and to go back
composer config --unset repositories.local
```

Do not commit a `path` repository here. It is a hard composer error when the
directory is absent, which breaks CI and every clean clone, and being canonical
and higher priority it also shadows the tagged release, so a version constraint
that the vcs repository satisfies perfectly well becomes unresolvable.

## License

MIT. See [LICENSE](./LICENSE).
