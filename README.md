# PingPong Agent

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kobaltdigital/pingpong-agent.svg?style=flat-square)](https://packagist.org/packages/kobaltdigital/pingpong-agent)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/kobalt-digital/pingpong-agent/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/kobalt-digital/pingpong-agent/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/kobalt-digital/pingpong-agent/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/kobalt-digital/pingpong-agent/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/kobaltdigital/pingpong-agent.svg?style=flat-square)](https://packagist.org/packages/kobaltdigital/pingpong-agent)

The Agent reports a Laravel app to [PingPong](https://pingpong.kobaltdigital.nl), the uptime and incident platform of Kobalt Digital. Once installed it announces itself to PingPong and ticks every minute from the app's own scheduler, so PingPong raises an Incident when the scheduler stops.

This package is built for internal use at Kobalt Digital. It is public so client apps can install it without credentials, but it comes without support. Issues and pull requests from outside Kobalt Digital may go unanswered.

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- The app's scheduler in cron, once a minute:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The tick runs from that scheduler. Without the cron line PingPong receives the handshake but no ticks, and says so on the Monitor.

## Installation

Install the package via composer:

```bash
composer require kobaltdigital/pingpong-agent
```

Create an Agent key on the app's Monitor in PingPong and add it to the app's `.env`:

```
PINGPONG_KEY=pp_agent_...
```

That is all. Every environment of an app is its own Monitor with its own key, so acceptance and production each get their own `PINGPONG_KEY`. Without a key the Agent sends nothing and schedules nothing.

Optionally, publish the config file:

```bash
php artisan vendor:publish --tag="pingpong-agent-config"
```

This is the contents of the published config file:

```php
return [
    'endpoint' => env('PINGPONG_ENDPOINT', 'https://pingpong.kobaltdigital.nl'),
    'key' => env('PINGPONG_KEY'),
];
```

Set `PINGPONG_ENDPOINT` only to point the app at a local or staging PingPong.

## What it sends

**Handshake.** On the first boot with a key set, the Agent posts its version, the PHP version and the Laravel version to PingPong. It sends from `app()->terminating()`, after the response has gone out, so no visitor waits on it. A deploy boots the app anyway (`package:discover`, `migrate`), so the handshake usually lands during the deploy. It is sent once per Agent version; upgrading the package sends a new one. A failed handshake is tried again ten minutes later.

**Tick.** `pingpong:ping` is added to the app's schedule by the package itself. It runs every minute in the foreground with `withoutOverlapping`, and posts a tick. The tick proves the scheduler is alive; later releases add health signals to it.

Every request carries the Agent key as a bearer token, the payload `schema` and the hostname of the sending server. Requests time out after 5 seconds. When PingPong is down, slow or answers with an error, the Agent logs a warning and carries on; it never throws into the app. A `429` means PingPong asked the Agent to slow down, so that tick is skipped rather than retried.

The payloads are described in [docs/payloads.md](docs/payloads.md).

## Testing

```bash
composer test
composer analyse
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Kobalt Digital](https://github.com/kobalt-digital)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
