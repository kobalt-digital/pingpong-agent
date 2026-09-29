# Tick signals

Date: 2026-09-29

## Goal

The tick already carries five checks PingPong consumes: `database`, `cache`, `disk`, `failed_jobs` and `queue`. This release makes the signals a configurable registry and adds the cheap facts that catch common silent outages: a high load, memory pressure, a growing queue backlog, and an app left in maintenance or debug mode. The handshake gains `os` and `cores`.

The Agent reports facts. PingPong judges them. Thresholds, incidents and history stay in PingPong.

## Principles

1. **Never hurt the host app.** A collector that throws costs one logged warning and is left out of the tick. The tick itself always goes out.
2. **Cheap.** Every collector runs once a minute in the `pingpong:ping` process that `schedule:run` starts in the foreground: syscalls, one small file read, a few capped queries. No shelling out.
3. **Raw values.** Bytes, seconds and counts, never percentages.
4. **Keep the existing shapes.** The five checks keep their keys, so PingPong's validation keeps passing. New keys are optional, so `schema` stays `1`.
5. **One signal per subject.** The queue backlog is part of `queue`, next to the canary, not a signal of its own.

## Two conventions

- **Checks** (`database`, `cache`, `disk`, `failed_jobs`, `queue`) run something and are always present. A failing check reports itself in its `error` key.
- **Platform facts** (`load`, `memory`, and the backlog keys of `queue`) are left out when the platform does not provide them. A missing one means unknown, never a problem.

`app` is always present. Caps mean "or more". Inside a container, load, memory and cores describe the host. Queue workers pause while the app is down, so PingPong reads `app.maintenance` before judging the canary.

## Code

```
src/
  Contracts/Collector.php     name(): string, collect(): ?array
  Actions/CollectSignals.php  runs the configured collectors, one guard each
  Actions/SendTick.php        sends the collected signals with the hashes, tasks and inventory
  Host.php                    load average, meminfo and cores, null when unavailable
  DatabaseProbe.php           the one database connection the signals share
  Signals/                    only the collectors, plus the CollectsFacts trait
    Database.php  Cache.php  Disk.php  FailedJobs.php  Queue.php
    Load.php  Memory.php  AppState.php
```

## Registry

- A check returns an array every time; a platform fact returns `null` when unavailable.
- `CollectSignals` resolves every class in `config('pingpong-agent.signals')`, calls `collect()` and `name()` inside one guard, leaves out `null` results and logs one warning per collector that throws.
- `SendTick` sends `signals` as that array, or an empty object when nothing was collected. `schedule_hash`, `inventory_hash`, `tasks`, `inventory` and the `send_tasks` and `send_inventory` answers are unchanged.
- Config order: `Database`, `Cache`, `Disk`, `FailedJobs`, `Queue`, `Load`, `Memory`, `AppState`. Removing a class stops that signal.

## Host

`Host` reads the facts only some platforms offer: `loadAverage()` from `sys_getloadavg()` or `/proc/loadavg`, `memInfo()` from `/proc/meminfo`, `cores()` from `/proc/cpuinfo`. Every method answers `null` instead of throwing, and suppresses the warnings `open_basedir` raises. The test suite binds a `FakeHost`, so results are the same on macOS and CI.

## Database probe

`DatabaseProbe` is a singleton that opens the one connection the database signals share. For a networked driver it registers a clone of the default connection as `pingpong-agent` with a 2 second connect timeout (`PDO::ATTR_TIMEOUT` for MySQL, MariaDB and PostgreSQL, `login_timeout` for SQL Server); SQLite keeps the default connection. It calls `getPdo()` once per process and keeps the outcome, and `error()` returns the message when the connect failed. Laravel retries a timed out connect once, so a dead default database costs about 4 seconds, once per tick. A `select 1` that hangs after connecting and connections other than the default are not bounded. `connectionFor(?string $name)` gives the probe's connection for `null` or the default name (`null` when the probe failed) and the named connection otherwise.

- `Database` reports `reachable: false` with the probe's error when the probe failed, else times only `select 1` on the probe's connection.
- `FailedJobs` reads through `connectionFor(queue.failed.database)`, and reports `new: null` with the probe's error when that is the default and the probe failed, without a second connect.
- `Queue` on the `database` driver reads its backlog through `connectionFor()` of the queue connection's `connection`. When that is the default and the probe failed, it dispatches no canary and reports the probe's error, since the dispatch would wait for the dead database again without the connect timeout.

## New signals and keys

| Key | Source | Left out when |
|---|---|---|
| `load` | `Host::loadAverage()`: `1m`, `5m`, `15m` | the host has no load average |
| `memory` | `Host::memInfo()`: `total_bytes`, `available_bytes`, `swap_used_bytes` | no readable `/proc/meminfo` |
| `app` | `isDownForMaintenance()` and `app.debug`: `maintenance`, `debug` | never. The tick is scheduled `evenInMaintenanceMode()`, so `maintenance` can be `true` |
| `queue.pending`, `queue.oldest_pending_seconds` | the default queue's jobs table | the driver is not `database`, the queue is on the default connection and the probe failed, or the query throws (one warning) |

The backlog counts over at most the 10000 oldest unreserved jobs in a bounded subquery, grouped by queue. Jobs due in the future count in `pending` but not in `oldest_pending_seconds`. `pending` is an empty object when nothing waits. The backlog is read before the canary is dispatched, so the canary is not counted. Failed jobs are not part of it; `failed_jobs.new` covers them.

The handshake adds `os` (`PHP_OS_FAMILY`, always) and `cores` (left out when `Host` gives `null`).

## Testing

- Each collector has tests for its happy path and its unavailable path; `Queue` also covers the cap, future jobs, reserved jobs, and keeping the canary keys when the backlog cannot be read.
- `CollectSignals`: a throwing `collect()` or `name()` is logged once and skipped, `null` is left out, an empty result is sent as `{}`.
- The probe connects a dead database once, however many signals ask for it.
- The schema 1 fixtures show every signal and both handshake keys, and the shape tests compare what the Agent sends against them.
