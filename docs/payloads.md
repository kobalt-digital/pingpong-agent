# Payloads

Every request is a JSON `POST` to the configured endpoint with `Authorization: Bearer <PINGPONG_KEY>`. The canonical examples live in `tests/Fixtures/schema-1`, and the test suite asserts that what the Agent sends has the same keys and value types. PingPong copies these fixtures into its own tests.

## Schema versions

Every payload carries `schema`, an integer. PingPong accepts the current and the previous schema, so an app on an older Agent keeps working after a PingPong deploy. Adding an optional key does not bump the schema. Removing a key, renaming one or changing its type does.

## Common keys

| Key | Type | Meaning |
|---|---|---|
| `schema` | integer | Payload schema version, currently `1` |
| `server` | string | Hostname of the sending server. All servers of a Monitor share one key, so this tells them apart |

## Handshake

`POST /api/agent/handshake`, once per Agent version.

```json
{
    "schema": 1,
    "server": "web-01",
    "agent_version": "0.1.0",
    "php_version": "8.4.12",
    "laravel_version": "13.2.0"
}
```

| Key | Type | Meaning |
|---|---|---|
| `agent_version` | string | Installed version of this package, as Composer reports it |
| `php_version` | string | `PHP_VERSION` of the process that sent it |
| `laravel_version` | string | `app()->version()` |

## Tick

`POST /api/agent/tick`, every minute from `pingpong:ping`.

```json
{
    "schema": 1,
    "server": "web-01",
    "signals": {
        "database": {
            "reachable": true,
            "latency_ms": 1.84,
            "error": null
        },
        "cache": {
            "reachable": true,
            "latency_ms": 0.42,
            "error": null
        },
        "disk": {
            "free_bytes": 52613349376,
            "total_bytes": 105226698752,
            "error": null
        },
        "failed_jobs": {
            "new": 0,
            "error": null
        },
        "queue": {
            "connection": "redis",
            "driver": "redis",
            "canary_id": "9b1f5e0c-3f4e-4a53-9a57-7f1f2c7e8d10",
            "error": null
        }
    }
}
```

| Key | Type | Meaning |
|---|---|---|
| `signals` | object | Health signals keyed by name, always a JSON object |

Every signal is a raw fact measured on the sending server. The Agent never decides whether a value is bad; PingPong owns the thresholds. A check that fails is reported in the signal itself, with `error` holding the message (at most 255 characters). An `error` of `null` means the check worked.

### `signals.database`

Runs `select 1` on the app's default database connection.

| Key | Type | Meaning |
|---|---|---|
| `reachable` | boolean | Whether the query succeeded |
| `latency_ms` | float or null | Time the query took, connecting included, in milliseconds. `null` when unreachable |
| `error` | string or null | Why the database was unreachable |

### `signals.cache`

Writes a random key to the app's default cache store, reads it back and removes it. The probe uses a key of its own, so it does not count the overlap lock `pingpong:ping` holds in the same store as proof that the cache works.

| Key | Type | Meaning |
|---|---|---|
| `reachable` | boolean | Whether the write, read and removal succeeded and the read returned what was written |
| `latency_ms` | float or null | Time the three operations took together, in milliseconds. `null` when unreachable |
| `error` | string or null | Why the cache was unreachable |

### `signals.disk`

The disk that holds the app's base path.

| Key | Type | Meaning |
|---|---|---|
| `free_bytes` | integer or null | Free space in bytes. `null` when it could not be read |
| `total_bytes` | integer or null | Size of the disk in bytes. `null` when it could not be read |
| `error` | string or null | Why the disk space could not be read |

### `signals.failed_jobs`

Jobs that landed in the failed jobs table (`queue.failed.table`, usually `failed_jobs`) since the previous tick of this server. The Agent remembers the highest id it saw in the app's cache, per server. The first tick after install only records that id and reports `0`, so an old backlog is not reported as new. When the table was emptied since the previous tick, every row in it counts as new.

Every server counts on its own, so an app on two servers reports each failed job twice, once per `server`.

| Key | Type | Meaning |
|---|---|---|
| `new` | integer or null | Number of jobs that failed since the previous tick. `null` when it could not be counted |
| `error` | string or null | Why it could not be counted, for example a missing table, failed jobs stored outside the database (`file`, `dynamodb`, `null` driver) or an unreachable database or cache |

### `signals.queue`

The app's default queue connection (`queue.default`). When its driver uses workers, every tick dispatches a canary job to it and names the canary here. A worker that runs the canary reports it back with a [Canary](#canary). A canary that never comes back means no worker picks up jobs from this queue.

No canary is dispatched when the driver runs jobs without a worker: `sync`, `deferred` and `background` run them in the dispatching process, `null` drops them.

| Key | Type | Meaning |
|---|---|---|
| `connection` | string or null | Name of the default queue connection |
| `driver` | string or null | Driver of that connection |
| `canary_id` | string or null | UUID of the canary this tick dispatched. `null` when none was dispatched |
| `error` | string or null | Why the canary could not be dispatched, for example an unreachable Redis |

## Canary

`POST /api/agent/canary`, from the queue worker that ran the canary a tick dispatched. It is the one report that goes through the queue, since the queue is what it tests. The canary is tried once: it is not retried when the worker fails or PingPong is down, so every canary reports back at most once.

```json
{
    "schema": 1,
    "server": "worker-01",
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
    "slug": "backup-run-only-db",
    "signal": "start",
    "run_id": "0d6f0f4e-6a0e-4c8e-8f59-3c1f4f0f2a61",
    "cron": "0 3 * * *",
    "timezone": "Europe/Amsterdam",
    "exit_code": null,
    "message": null
}
```

A failed run (`tests/Fixtures/schema-1/check-in-fail.json`):

```json
{
    "schema": 1,
    "server": "web-01",
    "slug": "backup-run-only-db",
    "signal": "fail",
    "run_id": "0d6f0f4e-6a0e-4c8e-8f59-3c1f4f0f2a61",
    "cron": "0 3 * * *",
    "timezone": "Europe/Amsterdam",
    "exit_code": 2,
    "message": "Scheduled command [backup:run --only-db] failed with exit code [2]."
}
```

| Key | Type | Meaning |
|---|---|---|
| `slug` | string | Identifies the task, stable across deploys. At most 100 characters |
| `signal` | string | `start`, `success`, `fail` or `skipped` |
| `run_id` | string | UUID shared by the `start` of a run and the `success`, `fail` or `skipped` that ends it |
| `cron` | string | The task's cron expression |
| `timezone` | string | The task's timezone (`->timezone()`), else the app's `app.timezone` |
| `exit_code` | integer or null | Exit code of a `fail`. `1` for a closure that threw. `null` for every other signal |
| `message` | string or null | Exception message of a `fail`, at most 255 characters |

### Signals

| Scheduler event | Signal |
|---|---|
| `ScheduledTaskStarting` | `start`, with a new `run_id` |
| `ScheduledTaskFinished`, exit code `0` | `success` |
| `ScheduledTaskFinished`, exit code not `0` | nothing; `ScheduledTaskFailed` follows and sends the `fail` |
| `ScheduledTaskFinished` without an exit code | `skipped`: the task started but found another run still going |
| `ScheduledTaskFailed` | `fail` |
| `ScheduledTaskSkipped` | `skipped`, with a `run_id` of its own. Sent when a filter (`->when()`, `->skip()`, `withoutOverlapping`) kept a due task from running, or the schedule is paused (`schedule:pause`) |

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

Any `2xx` counts as delivered. A `429` skips this request without a retry. Anything else, including a timeout after 5 seconds, is logged as a warning and dropped.
