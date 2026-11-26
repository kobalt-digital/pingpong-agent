# Changelog

All notable changes to `pingpong-agent` will be documented in this file.

## Unreleased

### Added

- **Update history from the machine agent (1.11.0).** Every tick sends the Package changes dpkg logged since the last tick PingPong accepted (`host.package_changes`: install, upgrade, downgrade, remove and purge, with both versions, the machine's time with its UTC offset and who ran it: `unattended-upgrade`, the user apt names, or unknown) and the kernel's `boot_id`, so PingPong records what was installed and when the machine rebooted. A tick PingPong refuses is sent again, a rotated `dpkg.log` loses no line, and a new install starts at the end of the log. Needs PingPong with Update history; older PingPong ignores both keys. ([#39](https://github.com/kobalt-digital/pingpong-agent/issues/39))

### Fixed

- **The machine agent names its release.** `pingpong-machine version` and the `version` of every machine tick now say the release (1.11.0) instead of 1.1.0, and the release workflow attaches no phar whose version differs from the tag.

## v1.2.0 - 2026-11-05

This release adds visibility into Flare integration health and the environment each application runs in. It also strengthens static analysis and adapts the release workflows to a protected `main` branch.

### Added

- **Flare integration health checks.** A new optional `flare` signal reports the installed client, whether an API key is configured, whether the exception handler or log stack forwards exceptions to Flare, and whether reporting is enabled where the client supports it. Both `spatie/laravel-flare` and `spatie/laravel-ignition` are supported. When the Flare daemon sender is configured, the signal also reports whether the daemon is reachable. ([#11](https://github.com/kobalt-digital/pingpong-agent/pull/11))
- **Flare API key validation.** The agent checks whether Flare accepts the key once an hour and immediately after a key change. It sends an empty report that does not create an error in Flare, distinguishes rejected keys from connectivity failures, and sends PingPong only a short hash of the key. ([#11](https://github.com/kobalt-digital/pingpong-agent/pull/11))
- **Application environment in health ticks.** The `app` signal now includes `env` (`APP_ENV`), allowing PingPong to distinguish production from acceptance and other environments, including when they share a Flare key. ([#11](https://github.com/kobalt-digital/pingpong-agent/pull/11))

### Improved

- **PHPStan level 10.** Strengthened type declarations and handling of collected data to pass the highest numbered PHPStan level. ([#13](https://github.com/kobalt-digital/pingpong-agent/pull/13), [#14](https://github.com/kobalt-digital/pingpong-agent/pull/14), [#15](https://github.com/kobalt-digital/pingpong-agent/pull/15), [#16](https://github.com/kobalt-digital/pingpong-agent/pull/16), [#17](https://github.com/kobalt-digital/pingpong-agent/pull/17))

### Maintenance

- **Workflows compatible with branch protection.** Release changelog updates now open a pull request instead of pushing directly to `main`. Pint continues to fix feature branches automatically and checks formatting on `main` without committing changes. ([#19](https://github.com/kobalt-digital/pingpong-agent/pull/19))

### Compatibility

- PHP `^8.2` and Laravel 12 and 13 remain supported.
- The Flare signal is omitted when neither supported Flare client is installed; no Flare dependency is added to your application.

**Full Changelog**: https://github.com/kobalt-digital/pingpong-agent/compare/v1.1.0...v1.2.0

## v1.1.0 - 2026-10-15

### What's Changed

* Tick interval per environment by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/10

**Full Changelog**: https://github.com/kobalt-digital/pingpong-agent/compare/v1.0.0...v1.1.0

## v1.0.0 - 2026-10-15

### What's Changed

* feat: app health signals in the tick by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/1
* feat: queue canary by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/2
* feat: scheduled task check-ins by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/3
* feat: inventory in the tick by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/4
* feat: resend task list when PingPong asks by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/5
* Collector registry with load, memory, app state and queue backlog signals by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/7
* fix: explain a redirected endpoint instead of a 405 by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/6
* PM2 processes in the tick by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/8
* fix: host tests pass on windows by @kylwes in https://github.com/kobalt-digital/pingpong-agent/pull/9

### New Contributors

* @kylwes made their first contribution in https://github.com/kobalt-digital/pingpong-agent/pull/1

**Full Changelog**: https://github.com/kobalt-digital/pingpong-agent/commits/v1.0.0
