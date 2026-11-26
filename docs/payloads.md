# Payloads

Every request is a JSON `POST` to the configured endpoint with `Authorization: Bearer <PINGPONG_KEY>`. The canonical examples live in `tests/Fixtures/schema-1`, and the test suite asserts that what the Agent sends has the same keys and value types. PingPong copies these fixtures into its own tests.

## Schema versions

Every payload carries `schema`, an integer. PingPong accepts the current and the previous schema, so an app on an older Agent keeps working after a PingPong deploy. Adding an optional key does not bump the schema. Removing a key, renaming one or changing its type does.

## Common keys

| Key | Type | Meaning |
|---|---|---|
| `schema` | integer | Payload schema version, currently `1` |
| `server` | string | Hostname of the sending server. All servers of a Monitor share one key, so this tells them apart |
| `machine_id` | string or null | `/etc/machine-id`, else `/var/lib/dbus/machine-id`: 32 lowercase hex characters that identify the operating system installation, so apps on one machine can be grouped. `null` when neither holds one, for example on macOS or in a container without it |
| `os_name` | string or null | `PRETTY_NAME` from `/etc/os-release`, else `/usr/lib/os-release`, unquoted and cut to 100 characters, such as `Ubuntu 24.04.1 LTS`. `null` when neither file can be read or names it, for example on macOS |
| `kernel` | string or null | Release of the running kernel from `php_uname('r')`, cut to 100 characters, such as `6.8.0-45-generic`. `null` when it is empty or `php_uname` is disabled |
| `fleet` | string or null | The stable name of short-lived instances that stand for one server, so PingPong keeps them as one. On ECS (Fargate or EC2, detected by `ECS_CONTAINER_METADATA_URI_V4`) `ecs:{cluster}/{service}`: the cluster's name (the last part of its ARN) and the `ServiceName` of the task metadata endpoint (`{ECS_CONTAINER_METADATA_URI_V4}/task`, 1 second timeout, asked once per process), else the task definition `Family`; cut to 255 characters, such as `ecs:prod/web`. Every task of a service gets a new hostname and machine id; the fleet stays. `null` off ECS and when the endpoint does not answer |

## Handshake

`POST /api/agent/handshake`, once per Agent version.

```json
{
    "schema": 1,
    "server": "web-01",
    "machine_id": "4f9c2b7e1a8d4e6f9b3c5a7d2e1f0c8b",
    "os_name": "Ubuntu 24.04.1 LTS",
    "kernel": "6.8.0-45-generic",
    "fleet": null,
    "agent_version": "0.1.0",
    "php_version": "8.4.12",
    "laravel_version": "13.2.0",
    "os": "Linux",
    "cores": 4
}
```

| Key | Type | Meaning |
|---|---|---|
| `agent_version` | string | Installed version of this package, as Composer reports it |
| `php_version` | string | `PHP_VERSION` of the process that sent it |
| `laravel_version` | string | `app()->version()` |
| `os` | string | `PHP_OS_FAMILY`: `Linux`, `Darwin`, `Windows`, `BSD`, `Solaris` or `Unknown` |
| `cores` | integer, optional | Processors listed in `/proc/cpuinfo`. Left out when that file cannot be read, for example on macOS |

## Tick

`POST /api/agent/tick`, from `pingpong:ping`. Every minute unless the app sets `PINGPONG_TICK_MINUTES`.

```json
{
    "schema": 1,
    "server": "web-01",
    "machine_id": "4f9c2b7e1a8d4e6f9b3c5a7d2e1f0c8b",
    "os_name": "Ubuntu 24.04.1 LTS",
    "kernel": "6.8.0-45-generic",
    "fleet": null,
    "sent_at": "2026-11-16T21:30:00+00:00",
    "tick_minutes": 1,
    "schedule_hash": "3f1c9a0e5b7d2c4f8a6e1b0d9c7f5a3e2d4b6c8a0f1e3d5c7b9a2e4f6d8c0b1a",
    "inventory_hash": "8e2b4d6f0a1c3e5b7d9f2a4c6e8b0d1f3a5c7e9b2d4f6a8c0e1b3d5f7a9c2e4b",
    "signals": {
        "database": {
            "reachable": true,
            "latency_ms": 1.84,
            "error": null
        },
        "cache": {
            "reachable": true,
            "latency_ms": 0.42,
            "error": null,
            "driver": "database"
        },
        "disk": {
            "free_bytes": 52613349376,
            "total_bytes": 105226698752,
            "inodes_total": 6553600,
            "inodes_free": 6141255,
            "error": null
        },
        "failed_jobs": {
            "new": 0,
            "error": null
        },
        "queue": {
            "connection": "database",
            "driver": "database",
            "canary_id": "9b1f5e0c-3f4e-4a53-9a57-7f1f2c7e8d10",
            "error": null,
            "pending": {
                "default": 12,
                "mail": 3
            },
            "oldest_pending_seconds": 40
        },
        "load": {
            "1m": 0.81,
            "5m": 0.62,
            "15m": 0.55
        },
        "memory": {
            "total_bytes": 8244207616,
            "available_bytes": 1310720000,
            "swap_used_bytes": 0,
            "oom_kills": 2,
            "swap_in_pages": 1843,
            "swap_out_pages": 5120
        },
        "cpu": {
            "user": 15595036,
            "nice": 4132,
            "system": 8155061,
            "idle": 341748387,
            "iowait": 347222,
            "irq": 0,
            "softirq": 1950356,
            "steal": 1013901
        },
        "pressure": {
            "cpu_some": 4.11,
            "memory_some": 0.0,
            "memory_full": 0.0,
            "io_some": 1.27,
            "io_full": 0.84
        },
        "host": {
            "uptime_seconds": 3542791,
            "reboot_required": true,
            "reboot_packages": [
                "linux-image-6.8.0-49-generic",
                "libc6"
            ],
            "updates_pending": 14,
            "updates_security": 6,
            "update_packages": [
                { "name": "openssl", "version": "3.0.13-0ubuntu3.5", "security": true },
                { "name": "nginx", "version": "1.24.0-2ubuntu7.2", "security": false }
            ]
        },
        "app": {
            "env": "production",
            "maintenance": false,
            "debug": false
        },
        "pm2": {
            "processes": [
                {
                    "id": 0,
                    "name": "queue-worker",
                    "status": "online",
                    "restarts": 3,
                    "uptime_seconds": 86400,
                    "memory_bytes": 52428800
                }
            ],
            "error": null
        },
        "flare": {
            "client": "spatie/laravel-flare",
            "client_version": "3.2.1",
            "key_set": true,
            "key_hash": "3f1c9a0e",
            "report": true,
            "handler_wired": true,
            "sender": "daemon",
            "daemon_reachable": true,
            "key_accepted": true,
            "key_status": 201,
            "key_checked_at": "2026-10-26T13:00:00+00:00",
            "error": null
        }
    },
    "outbox_pending": 0,
    "tasks": [
        {
            "slug": "backup-run-only-db",
            "command": "backup:run --only-db",
            "description": "Nightly database backup",
            "cron": "0 3 * * *",
            "timezone": "Europe/Amsterdam",
            "overrides": {
                "max_runtime": 240,
                "grace": 10
            }
        }
    ],
    "inventory": {
        "php_version": "8.4.12",
        "laravel_version": "13.2.0",
        "packages": [
            {
                "name": "laravel/framework",
                "version": "v13.2.0",
                "dev": false
            }
        ],
        "error": null
    },
    "connectivity": {
        "unreachable_since": "2026-11-12T09:42:07Z",
        "unreachable_until": "2026-11-12T09:59:21Z",
        "failed_attempts": 31,
        "last_error": "dns",
        "last_error_message": "cURL error 28: Resolving timed out after 5000 milliseconds"
    }
}
```

| Key | Type | Meaning |
|---|---|---|
| `sent_at` | string | When the Agent built this tick, ISO 8601 with the offset of the app's timezone, by the clock of `server`. PingPong compares it with the time the tick arrived to measure how far that clock is off. Only in the tick |
| `tick_minutes` | integer | Minutes until the next tick is due: 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30. Tells PingPong when the next tick is late |
| `schedule_hash` | string | SHA-256 of the task list below, whether or not the list is sent |
| `inventory_hash` | string | SHA-256 over the PHP version, the Laravel version and the bytes of `composer.lock`, whether or not the inventory is sent |
| `signals` | object | Health signals keyed by name, always a JSON object |
| `outbox_pending` | integer | Check-ins waiting in this `server`'s [outbox](#outbox) when the tick was built, so before this tick replays any. Always present, `0` when the outbox is empty or cannot be read. While it is above `0`, more replays follow in the next ticks |
| `tasks` | array, optional | The app's scheduled tasks. Only present when `schedule_hash` differs from the last tick PingPong accepted from this `server` |
| `inventory` | object, optional | What the app runs on. Only present when `inventory_hash` differs from the last tick PingPong accepted from this `server` |
| `connectivity` | object, optional | How long this `server` could not reach PingPong, and why. Only present in the first tick delivered after a request failed |

Every signal is a raw fact measured on the sending server: bytes, seconds and counts, never percentages. The Agent never decides whether a value is bad; PingPong owns the thresholds. Signals follow one of three conventions:

- **Checks** (`database`, `cache`, `disk`, `failed_jobs`, `queue`) are always present. A check that fails reports its own failure in the signal, with `error` holding the message (at most 255 characters). An `error` of `null` means the check worked.
- **Platform facts** (`load`, `memory`, `cpu`, `pressure`, `host`, and the backlog keys of `queue`) are left out when the platform does not provide them. A missing one means unknown, never a problem. They carry no `error`.
- **Optional checks** (`pm2`, `flare`) are left out when the server lacks the tool they read, which means unknown. When it is there, they behave like a check.

`app` is always present. An app can also remove a signal from its `signals` config, and then that signal is missing too. A count that stops at a cap means that number or more. Inside a container, `load`, `memory`, `cpu`, `pressure`, `host` and the handshake's `cores` describe the host machine, not the container's limits.

### `tasks`

The tasks that send [Check-ins](#check-in), so without sub minute tasks, unnamed closures and `pingpong:ping`. One entry per slug, sorted by slug. An app without tasks of its own sends an empty list. A task that is missing from a list was removed from the code, and PingPong can archive it.

The Agent keeps the hash of the last delivered list in the app's cache, per server. A tick that is not delivered leaves the hash alone, so the next tick sends the list again, and so does a tick PingPong answers with `send_tasks` (see [Responses](#responses)). When the cache cannot be read the list is sent anyway.

| Key | Type | Meaning |
|---|---|---|
| `slug` | string | Same as `slug` in the task's Check-ins |
| `command` | string or null | The command without the PHP and artisan binaries. `null` for a closure or job |
| `description` | string or null | `->description()` or `->name()`, the job class for `$schedule->job()` |
| `cron` | string | The task's cron expression |
| `timezone` | string | Same as in the task's Check-ins |
| `overrides` | object | Same as in the task's Check-ins |

### `inventory`

Read from the `composer.lock` in the app's base path, so it lists what the lock pins, dev packages included. Like the task list, the hash of the last delivered inventory is kept in the app's cache per server, and a tick that is not delivered leaves it alone. A deploy that changes the lock, or an upgrade of PHP or Laravel, sends the inventory with the next tick.

| Key | Type | Meaning |
|---|---|---|
| `php_version` | string | `PHP_VERSION` of the process that sent the tick |
| `laravel_version` | string | `app()->version()` |
| `packages` | array or null | Every package in the lock, sorted by name. `null` when the lock is missing or unreadable |
| `packages[].name` | string | Package name, e.g. `laravel/framework` |
| `packages[].version` | string | Version as the lock has it, e.g. `v13.2.0` or `dev-main` |
| `packages[].dev` | boolean | Whether the package is in `packages-dev` |
| `error` | string or null | Why the packages could not be read |

### `connectivity`

Every request that does not reach PingPong, to any endpoint and from any process (scheduler, tick, queue worker, web), counts: a network error, a timeout, a `5xx` and a `429`. A `4xx` PingPong answered does not, since PingPong was reachable. The Agent keeps the count in `storage/framework/pingpong-agent/<server>/connectivity.json`, one file per server, and the first tick that gets through carries it. It is forgotten once PingPong accepted that tick; when that tick fails as well, the count goes on and the next tick carries it. A request that fails while that tick is underway starts the next gap.

| Key | Type | Meaning |
|---|---|---|
| `unreachable_since` | string | First failed request of the gap, ISO 8601 in UTC, by the clock of `server` |
| `unreachable_until` | string | Last failed request of the gap, same format. Never before `unreachable_since`, even when the clock was set back |
| `failed_attempts` | integer | Requests that did not reach PingPong during the gap |
| `last_error` | string | Why the last one failed: `dns`, `connect`, `timeout`, `tls`, `http_5xx`, `rate_limited` or `other` |
| `last_error_message` | string | The error of the last one, at most 255 characters |

`last_error` comes from the cURL error: number 6, or a resolve that timed out, is `dns`; 7 is `connect`; any other 28 is `timeout`; 35, 51, 58 and 60 are `tls`. A `5xx` answer is `http_5xx` and a `429` is `rate_limited`.

### `signals.database`

Connects to the app's default database connection with a 2 second connect timeout, then runs `select 1` on it. Laravel retries a connect that timed out once, so an unreachable database costs about 4 seconds at worst. SQLite has no network connect to bound. The Agent connects once per tick and the other database signals share that connection, so an unreachable database is waited on once. A `select 1` that hangs after connecting is not bounded.

| Key | Type | Meaning |
|---|---|---|
| `reachable` | boolean | Whether the connect and the query succeeded |
| `latency_ms` | float or null | Time the query took on the already open connection, in milliseconds. Connecting is not included. `null` when unreachable |
| `error` | string or null | Why the database was unreachable |

### `signals.cache`

Writes a random key to the app's default cache store, reads it back and removes it. The probe uses a key of its own, so it does not count the overlap lock `pingpong:ping` holds in the same store as proof that the cache works.

| Key | Type | Meaning |
|---|---|---|
| `reachable` | boolean | Whether the write, read and removal succeeded and the read returned what was written |
| `latency_ms` | float or null | Time the three operations took together, in milliseconds. `null` when unreachable |
| `error` | string or null | Why the cache was unreachable |
| `driver` | string or null | The driver of the default cache store (`cache.stores.{cache.default}.driver`), such as `redis`, `database` or `file`, cut to 32 characters. `null` when the configuration names none |
| `redis` | object, optional | Only on the `redis` driver while the cache is reachable and `INFO` can be read: what Redis says about itself. Left out otherwise. Read from either client (phpredis answers one flat list, Predis one per section) |
| `redis.version` | string or null | `redis_version`, cut to 32 characters |
| `redis.used_memory_bytes` | integer or null | `used_memory` |
| `redis.maxmemory_bytes` | integer or null | `maxmemory`; `0` means no limit |
| `redis.connected_clients` | integer or null | `connected_clients` |
| `redis.evicted_keys` | integer or null | `evicted_keys`, since Redis started |

### `signals.disk`

The disk that holds the app's base path.

| Key | Type | Meaning |
|---|---|---|
| `free_bytes` | integer or null | Free space in bytes. `null` when it could not be read |
| `total_bytes` | integer or null | Size of the disk in bytes. `null` when it could not be read |
| `inodes_total` | integer or null | Inodes of the filesystem, from `df -iP`. `null` when `df` cannot run or the filesystem keeps no inode count (btrfs) |
| `inodes_free` | integer or null | Free inodes of the filesystem, read like `inodes_total` |
| `error` | string or null | Why the disk space could not be read |

### `signals.failed_jobs`

Jobs that landed in the failed jobs table (`queue.failed.table`, usually `failed_jobs`) since the previous tick of this server. The Agent remembers the highest id it saw in the app's cache, per server. The first tick after install only records that id and reports `0`, so an old backlog is not reported as new. When the table was emptied since the previous tick, every row in it counts as new.

Every server counts on its own, so an app on two servers reports each failed job twice, once per `server`.

The count reads through the connection `signals.database` opened when `queue.failed.database` is empty or names the default connection. When that connection could not be opened, `new` is `null` and `error` repeats why, without connecting again. Another connection is used as configured, without the short connect timeout.

| Key | Type | Meaning |
|---|---|---|
| `new` | integer or null | Number of jobs that failed since the previous tick. `null` when it could not be counted |
| `error` | string or null | Why it could not be counted, for example a missing table, failed jobs stored outside the database (`file`, `dynamodb`, `null` driver) or an unreachable database or cache |

### `signals.queue`

The app's default queue connection (`queue.default`), in two halves: a canary that proves workers run, and the backlog waiting for them.

**Canary.** When the driver uses workers, every tick dispatches a canary job to it and names the canary here. A worker that runs the canary reports it back with a [Canary](#canary). A canary that never comes back means no worker picks up jobs from this queue. No canary is dispatched when the driver runs jobs without a worker: `sync`, `deferred` and `background` run them in the dispatching process, `null` drops them. Nor is one dispatched to a `database` queue on the default connection when the connection of `signals.database` could not be opened, since dispatching would wait for that database again; `error` then repeats why. These keys are always present.

**Backlog.** Only the `database` driver keeps its backlog where the Agent can count it cheaply, so `pending` and `oldest_pending_seconds` are left out for every other driver (Redis, SQS, Horizon, `sync`). They are also left out when the queue lives on the default connection and the connection of `signals.database` could not be opened, or when the query fails, for example because the jobs table does not exist; the Agent logs a warning for a failed query. No index answers "unreserved jobs per queue", so the Agent counts at most the 10000 oldest unreserved jobs. A total of `10000` across all queues means 10000 or more. Reserved jobs are being worked on and are not counted. The backlog is read before the canary is dispatched, so this tick's canary is not in it.

| Key | Type | Meaning |
|---|---|---|
| `connection` | string or null | Name of the default queue connection |
| `driver` | string or null | Driver of that connection |
| `canary_id` | string or null | UUID of the canary this tick dispatched. `null` when none was dispatched |
| `error` | string or null | Why the canary could not be dispatched, for example an unreachable Redis |
| `pending` | object, optional | Unreserved jobs per queue name, for example `{"default": 12}`. A queue without pending jobs is absent. An empty object when nothing is pending |
| `oldest_pending_seconds` | integer, optional | Seconds since the oldest pending job became due. Jobs delayed into the future count in `pending` but not here. Left out when no pending job is due |

### `signals.load`

The system load average, from `sys_getloadavg()` or else `/proc/loadavg`. Left out when neither is available. Divide by the handshake's `cores` for load per core.

| Key | Type | Meaning |
|---|---|---|
| `1m` | float | Load average over the last minute |
| `5m` | float | Load average over the last 5 minutes |
| `15m` | float | Load average over the last 15 minutes |

### `signals.memory`

Read from `/proc/meminfo`. Left out when that file cannot be read, for example on macOS or a shared host with `open_basedir`.

| Key | Type | Meaning |
|---|---|---|
| `total_bytes` | integer | `MemTotal` |
| `available_bytes` | integer | `MemAvailable`, what new processes can use without swapping |
| `swap_used_bytes` | integer | `SwapTotal` minus `SwapFree` |
| `oom_kills` | integer or null | `oom_kill` from `/proc/vmstat`: processes the kernel killed for want of memory since boot. `null` when it cannot be read |
| `swap_in_pages` | integer or null | `pswpin` from `/proc/vmstat`: pages swapped in since boot |
| `swap_out_pages` | integer or null | `pswpout` from `/proc/vmstat`: pages swapped out since boot |

### `signals.cpu`

CPU time since boot in jiffies, from the `cpu` line of `/proc/stat`, which adds up every processor. PingPong takes the difference between two ticks of the same server. Left out when that file cannot be read.

| Key | Type | Meaning |
|---|---|---|
| `user`, `nice`, `system`, `idle`, `iowait`, `irq`, `softirq`, `steal` | integer | Time spent in that state |

### `signals.pressure`

Pressure stall information: the `avg60` of `/proc/pressure/cpu`, `memory` and `io`, the percentage (0 to 100) of the last 60 seconds in which tasks waited on that resource. `some` means at least one task waited, `full` that all of them did. Left out when `/proc/pressure` does not exist (a kernel before 4.20, or PSI switched off). A whole number such as `0` arrives without a fraction.

| Key | Type | Meaning |
|---|---|---|
| `cpu_some` | float or null | Some tasks waited on CPU |
| `memory_some` | float or null | Some tasks waited on memory |
| `memory_full` | float or null | All tasks waited on memory |
| `io_some` | float or null | Some tasks waited on IO |
| `io_full` | float or null | All tasks waited on IO |

### `signals.host`

How long the machine has been up, whether it waits for a reboot and how many package updates wait. Each key is `null` when it cannot be read; the whole signal is left out when none of them can (macOS).

| Key | Type | Meaning |
|---|---|---|
| `uptime_seconds` | integer or null | Seconds since boot: the first number of `/proc/uptime`, floored |
| `reboot_required` | boolean or null | `true` when `/var/run/reboot-required` exists, `false` when it does not. `null` when `/var/run` cannot be read, or the `ID` and `ID_LIKE` of `/etc/os-release` name neither `debian` nor `ubuntu`: other distributions have no such marker |
| `reboot_packages` | array of strings or null | The packages that asked for the reboot, the unique lines of `/var/run/reboot-required.pkgs`: at most 50, each cut to 100 characters. `null` when that file does not exist |
| `updates_pending` | integer or null | Updates that can be installed, from the English text Ubuntu's update-notifier writes to `/var/lib/update-notifier/updates-available`: the first number of the line with "can be applied" ("14 updates can be applied immediately."), on Ubuntu 20.04 "can be installed", or before that "can be updated" ("14 packages can be updated."). Where that file cannot be read or no line matches (Debian, a minimal Ubuntu, another language), the number of lines of `apt list --upgradable` instead, as long as apt has package lists in `/var/lib/apt/lists` (a container image usually has none, and would look up to date). `null` when neither can tell |
| `updates_security` | integer or null | How many of those are security updates: the first number of each line with "security update" ("6 of these updates are standard security updates.", before 22.04 "6 updates are security updates."), added up: with Ubuntu Pro, ESM Infra, ESM Apps and standard each have a line. `0` when `updates_pending` was found without such a line. A line about "additional" security updates ("5 additional security updates can be applied with ESM Apps.") counts updates that cannot be installed without Ubuntu Pro, and counts as neither. Counted by apt, the packages one of whose suites ends in `-security` (`trixie-security`). `null` exactly when `updates_pending` is |
| `update_packages` | array of objects or null | The pending updates by name while `updates_pending` is above 0, from `apt list --upgradable` (run without a shell, in the C locale, 10 second timeout, on a system like Debian): at most 500 of `{name, version, security}`, `security` true when one of the package's suites ends in `-security`. Remembered in the app's cache until `updates-available` changes (apt rewrites it whenever its lists or the installed packages change), without update-notifier until `/var/lib/apt/lists` or `/var/lib/dpkg/status` changes, for a day at most, so apt runs rarely. `null` when none are pending, apt is missing, or it failed |

### `signals.app`

Always present. The tick runs during `php artisan down` too, so `maintenance` can be `true`. Queue workers pause while the app is down, so the canary of a tick with `maintenance: true` does not come back. PingPong should read `app.maintenance` before judging the canary.

| Key | Type | Meaning |
|---|---|---|
| `env` | string | The app's environment, `app()->environment()` (`APP_ENV`), for example `production` or `acceptance` |
| `maintenance` | boolean | Whether the app is down for maintenance (`php artisan down`) |
| `debug` | boolean | Whether `app.debug` (`APP_DEBUG`) is on |

### `signals.pm2`

Every process PM2 runs for the user that runs the scheduler, read with `pm2 jlist` with a 3 second timeout. Left out when `pm2` is not on the `PATH`. The Agent runs no PM2 command while no daemon runs, since every PM2 command starts a daemon when none runs; `processes` is then an empty list, as no process runs. The daemon runs when `$PM2_HOME/pm2.pid` (`PM2_HOME` defaulting to `~/.pm2`) holds the pid of a live process, checked through `/proc` or ext-posix. A pid file left behind by a daemon that died does not count. When neither `/proc` nor ext-posix can tell, the pid file alone counts. Notices PM2 prints before its JSON are skipped, also those that open with `[`. Only the keys below leave the server: `pm2 jlist` also carries the environment of every process, which is never sent.

| Key | Type | Meaning |
|---|---|---|
| `processes` | array or null | Every listed process with a `pm_id`, a `name` and a `pm2_env.status`, sorted by `id`, at most 100. An empty list when no daemon runs. `null` when the list could not be read |
| `processes[].id` | integer | `pm_id` |
| `processes[].name` | string | `name`, at most 255 characters. Instances of one app in cluster mode share it |
| `processes[].status` | string | `pm2_env.status`, for example `online`, `stopped`, `errored`, `launching` or `waiting restart` |
| `processes[].restarts` | integer | `pm2_env.restart_time`, 0 when PM2 leaves it out |
| `processes[].uptime_seconds` | integer or null | Seconds since the process started, while `online`. `null` otherwise |
| `processes[].memory_bytes` | integer or null | `monit.memory`. `null` when PM2 leaves it out |
| `error` | string or null | Why the list could not be read: no `PM2_HOME` or `HOME`, a timeout, a failed exit or output without a JSON list |

The cost is one `node` process per tick on a server that runs PM2, and a `PATH` scan and one file check on every other server.

### `signals.flare`

Whether an exception thrown in the app would reach [Flare](https://flareapp.io). Left out when neither `spatie/laravel-flare` nor `spatie/laravel-ignition` is installed, as Composer's runtime knows it, so a dev only install on production counts as not installed. When both are, `spatie/laravel-flare` is the one described.

The facts about the app are read every tick. Flare itself is asked about the key once an hour per server, and right away when the key changes: the Agent posts an empty report to the endpoint the client reports to (`flare.base_url`, else `https://ingress.flareapp.io/v1/errors` for laravel-flare and `https://flareapp.io/api/reports` for Ignition) with the key in `x-api-token`, a 3 second timeout and no redirects. Flare answers a valid key with a 2xx and drops the empty report, so no error is created. It answers an invalid key with `403 Invalid API key`. The answer is kept in the app's cache, and between two checks every tick repeats it with the time it was given. A check that could not tell is also kept for an hour. When the cache cannot keep it, Flare is asked every tick.

| Key | Type | Meaning |
|---|---|---|
| `client` | string | `spatie/laravel-flare` or `spatie/laravel-ignition` |
| `client_version` | string | Installed version of that package |
| `key_set` | boolean | Whether `flare.key` holds a key |
| `key_hash` | string or null | First 8 characters of the SHA-256 of the key, so PingPong can tell two Monitors that share a Flare project apart from two that do not. Never the key itself. `null` without a key |
| `report` | boolean or null | `flare.report` of laravel-flare (`FLARE_REPORT`), true unless the app set it. `null` for Ignition, which has no such switch |
| `handler_wired` | boolean or null | laravel-flare: whether the exception handler has the callback `Flare::handles($exceptions)` registers in `bootstrap/app.php`. Ignition: whether the default log channel, or a stack it leads to, has a channel with the `flare` driver. `null` when the handler's callbacks could not be read |
| `sender` | string or null | laravel-flare: `daemon` when `flare.sender.class` is the daemon sender, else `direct`. `null` for Ignition |
| `daemon_reachable` | boolean or null | Whether `GET {daemon_url}/health` answered with a 2xx within 1 second. `null` unless `sender` is `daemon`. A daemon that does not answer is not fatal: the client then sends straight to Flare |
| `key_accepted` | boolean or null | `true` when Flare answered the last key check with a 2xx, `false` with a 401 or 403. `null` without a key or when the check could not tell |
| `key_status` | integer or null | HTTP status of the last key check. `null` without a key or when Flare could not be reached |
| `key_checked_at` | string or null | ISO 8601 time of the last key check. `null` without a key |
| `error` | string or null | Why the last key check could not tell, for example a timeout or a 5xx |

### `signals.metrics`

The app's own numbers, registered with `PingPong::metric()` and read once per tick on the server that ticks. Left out while the app registered none; `values` is empty when it registered some and none read a usable number. Not a configured collector, so an app with a published `signals` list sends it too.

```json
"metrics": {
  "values": [
    {"name": "orders.pending", "value": 12, "unit": "count"},
    {"name": "import.lag", "value": 1.5, "unit": "seconds"}
  ]
}
```

| Key | Type | Meaning |
|---|---|---|
| `values` | array | At most 20 entries, one per registered metric that read a usable number |
| `values[].name` | string | Lower case words split by dots (`^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$`), at most 64 characters, unique within the tick |
| `values[].value` | integer or float | A finite number, at most `1e15` either side of zero |
| `values[].unit` | string | `count`, `percent`, `ms`, `seconds` or `bytes` |

The Agent refuses a name or unit outside these rules when it is registered, and a registration beyond 20, with a warning in the app's log. A closure that throws or reads something other than a finite number within range is left out of that tick, with a warning.

## Machine tick

`POST /api/machine/tick`, from the [machine agent](../machine/README.md) every minute. Its `host` object carries the keys of [`signals.host`](#signalshost), read the same way, and these besides:

| Key | Type | Meaning |
|---|---|---|
| `host.boot_id` | string or null | `/proc/sys/kernel/random/boot_id`, lower case: the kernel makes it once per boot, so a new one is a reboot, however short. `null` where it cannot be read (not Linux) |
| `host.package_changes` | array or null | What dpkg changed since the last tick PingPong accepted, oldest first, read from `/var/log/dpkg.log`. `null` where that log cannot be read. See below |
| `host.package_changes[].at` | string | When dpkg logged it, ISO 8601 with the offset the machine's timezone had then (`/etc/localtime`, else `/etc/timezone`, else UTC), such as `2026-11-26T06:12:04+01:00` |
| `host.package_changes[].action` | string | `install`, `upgrade`, `downgrade`, `remove` or `purge` |
| `host.package_changes[].package` | string | The package as dpkg logs it, with its architecture: `openssl:amd64` |
| `host.package_changes[].from` | string or null | The version before. `null` for an install |
| `host.package_changes[].to` | string or null | The version after. `null` for a removal or purge |
| `host.package_changes[].by` | string or null | Who ran it: `null` for unknown |

The agent keeps the inode of `dpkg.log` and the byte after the last line PingPong accepted in its state file, and reads from there: a tick that does not get a `2xx` leaves it, so the next tick sends the same changes again. Only lines that change a version count; `configure`, `trigproc`, `status` and `startup` lines are passed over. A tick carries at most 200 changes and reads at most 1 MiB of the log; the rest follow in the next ticks. When logrotate moved the log aside, the agent finishes `dpkg.log.1` from where it was before it reads the new log from its start, and a log that shrank is read from its start. The first tick of a new install starts at the end of the log: history begins there.

## Canary

`POST /api/agent/canary`, from the queue worker that ran the canary a tick dispatched. It is the one report that goes through the queue, since the queue is what it tests. The canary is tried once: it is not retried when the worker fails or PingPong is down, so every canary reports back at most once.

```json
{
    "schema": 1,
    "server": "worker-01",
    "machine_id": "a3e81f05c2d94b7e86f1d0c4b9a27e53",
    "os_name": "Ubuntu 24.04.1 LTS",
    "kernel": "6.8.0-45-generic",
    "fleet": null,
    "canary_id": "9b1f5e0c-3f4e-4a53-9a57-7f1f2c7e8d10",
    "dispatched_at": "2026-09-25T12:00:00.250Z",
    "dispatched_from": "web-01",
    "ran_at": "2026-09-25T12:00:03.500Z"
}
```

| Key | Type | Meaning |
|---|---|---|
| `canary_id` | string | UUID from `signals.queue.canary_id` of the tick that dispatched it |
| `dispatched_at` | string | When the tick dispatched the canary, ISO 8601 in UTC with milliseconds, by the clock of `dispatched_from` |
| `dispatched_from` | string | Hostname of the server that dispatched the canary |
| `ran_at` | string | When the worker ran the canary, same format, by the clock of `server` |

`server` is the worker's hostname, which can differ from `dispatched_from`. Both times come from the clocks of their own servers.

## Check-in

`POST /api/agent/check-in`, from the scheduler process (`schedule:run`) as a task starts and ends. Check-ins never go through the queue.

```json
{
    "schema": 1,
    "server": "web-01",
    "machine_id": "4f9c2b7e1a8d4e6f9b3c5a7d2e1f0c8b",
    "os_name": "Ubuntu 24.04.1 LTS",
    "kernel": "6.8.0-45-generic",
    "fleet": null,
    "slug": "backup-run-only-db",
    "signal": "start",
    "run_id": "0d6f0f4e-6a0e-4c8e-8f59-3c1f4f0f2a61",
    "cron": "0 3 * * *",
    "timezone": "Europe/Amsterdam",
    "overrides": {
        "max_runtime": 240,
        "grace": 10
    },
    "exit_code": null,
    "message": null,
    "occurred_at": "2026-11-12T09:42:07.250Z"
}
```

A failed run (`tests/Fixtures/schema-1/check-in-fail.json`):

```json
{
    "schema": 1,
    "server": "web-01",
    "machine_id": "4f9c2b7e1a8d4e6f9b3c5a7d2e1f0c8b",
    "os_name": "Ubuntu 24.04.1 LTS",
    "kernel": "6.8.0-45-generic",
    "fleet": null,
    "slug": "backup-run-only-db",
    "signal": "fail",
    "run_id": "0d6f0f4e-6a0e-4c8e-8f59-3c1f4f0f2a61",
    "cron": "0 3 * * *",
    "timezone": "Europe/Amsterdam",
    "overrides": {
        "max_runtime": 240,
        "grace": 10
    },
    "exit_code": 2,
    "message": "Scheduled command [backup:run --only-db] failed with exit code [2].",
    "occurred_at": "2026-11-12T09:43:01.500Z"
}
```

| Key | Type | Meaning |
|---|---|---|
| `slug` | string | Identifies the task, stable across deploys. At most 100 characters |
| `signal` | string | `start`, `success`, `fail` or `skipped` |
| `run_id` | string | UUID shared by the `start` of a run and the `success`, `fail` or `skipped` that ends it. With `signal` it identifies a Check-in, so PingPong treats a second one with the same `run_id` and `signal` as a duplicate |
| `cron` | string | The task's cron expression |
| `timezone` | string | The task's timezone (`->timezone()`), else the app's `app.timezone` |
| `overrides` | object | What the task sets in code with `->pingpong()`, see below. Always both keys |
| `overrides.max_runtime` | integer or null | Minutes a run may take. `null` leaves it to PingPong |
| `overrides.grace` | integer or null | Minutes a start may be late. `null` leaves it to PingPong |
| `exit_code` | integer or null | Exit code of a `fail`. `1` for a closure that threw. `null` for every other signal |
| `message` | string or null | Exception message of a `fail`, at most 255 characters |
| `occurred_at` | string | When the scheduler event behind the signal fired, ISO 8601 in UTC with milliseconds, by the clock of `server`. Taken when it happens, not when it is sent |

### Signals

| Scheduler event | Signal |
|---|---|
| `ScheduledTaskStarting` | `start`, with a new `run_id` |
| `ScheduledTaskFinished` of a task with `->runInBackground()` | nothing; the task has only been started |
| `ScheduledBackgroundTaskFinished`, exit code `0` | `success`, from the `schedule:finish` process |
| `ScheduledBackgroundTaskFinished`, exit code not `0` | `fail`, with the exit code and no message |
| `ScheduledTaskFinished`, exit code `0` | `success` |
| `ScheduledTaskFinished`, exit code not `0` | nothing; `ScheduledTaskFailed` follows and sends the `fail` |
| `ScheduledTaskFinished` without an exit code | `skipped`: the task started but found another run still going |
| `ScheduledTaskFailed` | `fail` |
| `ScheduledTaskSkipped` | `skipped`, with a `run_id` of its own. Sent when a filter (`->when()`, `->skip()`, `withoutOverlapping`) kept a due task from running, or the schedule is paused (`schedule:pause`) |

A background task ends in another process than it started in, so its `run_id` is kept in the app's cache for a day, per server, between the two.

### Outbox

A Check-in that does not reach PingPong (a network error, a timeout, a `5xx` or a `429`) is kept in `storage/framework/pingpong-agent/<server>/outbox.json`, one file per server. A Check-in PingPong answers with any other `4xx` is dropped, since sending it again changes nothing. Right after the next tick PingPong accepts, the Agent replays the kept Check-ins oldest first, exactly as they were first sent: same `run_id`, same `occurred_at`. It stops at the first one that does not get through, and replays at most 50 per tick. It starts no new replay once 10 seconds have passed, so with the 5 second request timeout a tick spends at most about 15 seconds on it. The rest waits for the next tick. A replayed Check-in PingPong rejects is dropped.

Every tick says in `outbox_pending` how many Check-ins wait, counted before that tick's replay, so PingPong knows more are coming while it is above `0`. A delivered tick reporting `0` means nothing is left to replay. An outbox that cannot be read (a corrupt file, or one the tick's user may not read) counts as `0`, so a broken file never holds PingPong's flush window open.

The outbox holds at most 500 Check-ins and none older than 24 hours. Beyond that the oldest are dropped, with one warning in the log until the outbox has emptied again.

Replays can arrive after newer Check-ins: once PingPong is reachable again, new Check-ins go out directly while older ones still wait their turn in the outbox, possibly for several ticks. So `occurred_at`, not arrival, says when a signal happened. PingPong treats a Check-in with a `run_id` and `signal` it already has as a duplicate. Only Check-ins are kept: ticks, canaries and handshakes are not replayed.

### Overrides

A task sets them where it is scheduled:

```php
Schedule::command('backup:run --only-db')
    ->dailyAt('03:00')
    ->pingpong(maxRuntime: 240, grace: 10);
```

The Agent only passes them on. PingPong decides what they mean for the task.

### Slugs

- A command: the command with its arguments, without the PHP and artisan binaries, so a new PHP path on the server keeps the slug. `backup:run --only-db` becomes `backup-run-only-db`, `exec('/usr/bin/certbot renew')` becomes `usr-bin-certbot-renew`.
- A closure or job: its name (`->name()`, or the job class for `$schedule->job()`).
- Longer than 100 characters: cut to 91 and ended with a dash and 8 characters of a hash of the full name.

Two tasks with the same command and arguments share a slug, and so a task in PingPong.

### Not reported

- Tasks that repeat within a minute (`everyThirtySeconds()` and friends).
- Closures without a name. There is nothing stable to derive a slug from, so the Agent logs an `info` line at the start of each run instead.
- `pingpong:ping` itself. PingPong treats the tick as a built in task.

## Responses

Any `2xx` counts as delivered. A `429` skips this request without a retry. Anything else, including a timeout after 5 seconds, is logged as a warning and dropped, except a Check-in that did not reach PingPong, which waits in the [outbox](#outbox). Every request that did not reach PingPong (anything but a `2xx` or a `4xx` other than `429`) counts towards the next tick's [`connectivity`](#connectivity).

The Agent reads one thing from a delivered tick's body. When PingPong lacks a list this server marked delivered (for instance because the app ticked before PingPong read it), it answers:

```json
{"status": "recorded", "send_tasks": true}
```

The Agent then forgets the cached `schedule_hash`, so the next tick carries `tasks` again. `send_inventory: true` does the same for `inventory`. Only a literal `true` asks. PingPong leaves the flags out otherwise, and every other key, a body that is not a JSON object and a body that is not JSON at all are ignored.
