# Supervisor processes

Date: 2026-10-15

Status: parked. PM2 ships first. Supervisor waits for a daemon the queue canary does not cover (Reverb, an SSR server, a custom script), and for a design that keeps a dead queue worker from paging twice (queue stalled and process down).

Builds on `2026-10-15-pm2-processes-design.md`. Where the two differ, this one wins.

## Goal

Apps that run their queue workers and daemons under Supervisor (every Forge daemon) get the same watch as PM2: the Agent reports what Supervisor runs, PingPong learns the processes per server and raises an Incident when a known one stops running or disappears.

PingPong treats PM2 and Supervisor as two process managers of one feature. Nothing is shipped yet, so the PM2 work is generalised before it is merged, not after.

A server that runs only one of the two shows nothing for the other: no empty block, no empty row, no "not reported" line.

## Agent

### Signal

`Signals\Supervisor`, named `supervisor`, added to the default `signals` config after `Signals\Pm2`. A platform fact with an `error`, like `pm2`:

- `supervisorctl` is not on the `PATH` of the process: left out. Nothing is spawned. `Host::executable('supervisorctl')` looks it up by hand, as for `pm2`.
- Forge installs Supervisor on every server, also where an app runs no daemon. When the default socket `/var/run/supervisor.sock` exists and the process may not write to it (`is_writable`, which is what connecting to a Unix socket needs), the Agent skips the run and reports `processes: null` with `error` "Permission denied on /var/run/supervisor.sock". That keeps a `python` start out of every tick of every app that cannot read Supervisor anyway.
- Otherwise the Agent runs `supervisorctl status` through Laravel's `Process` facade with a 3 second timeout, from the app's base path.
- `supervisorctl status` exits non zero whenever a process is not running, so the exit code is not read. The output is: one line per process that matches `<name> <STATE> <description>` is a process; any other output without a single process line is the `error` (its first line, at most 255 characters), with `processes: null`. Empty output is an empty list.
- A timeout gives `processes: null` and `error` "supervisorctl status did not answer within 3 seconds".
- A socket the scheduler's user may not open prints `error: <class 'PermissionError'>, [Errno 13] Permission denied ...`, which lands in `error` as above. The README tells how to give the user access (see Server access).

```json
"supervisor": {
    "processes": [
        {
            "name": "daemon-123456:daemon-123456_00",
            "status": "RUNNING",
            "uptime_seconds": 93784
        }
    ],
    "error": null
}
```

| Key | Type | Meaning |
|---|---|---|
| `processes` | array or null | Every process `supervisorctl status` lists, in its order, at most 100. `null` when the list could not be read |
| `processes[].name` | string | `group:name`, or `name` when the group has the name of its only process |
| `processes[].status` | string | `STOPPED`, `STARTING`, `RUNNING`, `BACKOFF`, `STOPPING`, `EXITED`, `FATAL` or `UNKNOWN` |
| `processes[].uptime_seconds` | integer or null | Parsed from `uptime 1 day, 2:03:04` or `uptime 2:03:04` while `RUNNING`, else `null` |
| `error` | string or null | Why the list could not be read |

Supervisor counts no restarts and reports no memory, so those keys are not sent. The description after the state (`Exited too quickly`, a pid) is not sent either.

The cost is one `python` process per tick on a server with Supervisor, and a `PATH` scan on every other server.

### Server access

Forge creates `/var/run/supervisor.sock` with `chmod=0700`, owned by root, so the `forge` user that runs the scheduler cannot read it. The README documents the change in `/etc/supervisor/supervisord.conf`:

```ini
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0770
chown=root:forge
```

followed by `sudo service supervisor restart`. This lets `forge` read and also control Supervisor, which it can already do through sudo on Forge.

### Docs and fixtures

`docs/payloads.md` gains `signals.supervisor`, the README lists the signal and the access change, and `tests/Fixtures/schema-1/tick.json` carries the example above.

## PingPong

### One model for both managers

- Enum `App\Enums\ProcessManager`: `PM2 = 'pm2'`, `SUPERVISOR = 'supervisor'`, with `label()` ("PM2", "Supervisor") and `runningStatus()` (`online`, `RUNNING`).
- `agent_pm2_processes` becomes `agent_processes` with a `manager` column (string, the enum), unique on `monitor_id`, `server`, `manager`, `name`. `restarts` stays nullable and is null for Supervisor. Model `AgentProcess`. The PM2 migration is rewritten, not followed by a rename, since it never ran outside tests.
- `app_health_samples.pm2_processes` and `pm2_error` become one jsonb column `processes`: an object keyed by manager, each `{"processes": list or null, "error": string or null}` as the tick sent it, with only the managers the tick carried. `null` when the tick carried neither.
- `TickRequest` validates `signals.supervisor` like `signals.pm2`, without `id`, `restarts` and `memory_bytes`.

### Learning and judging

Everything from the PM2 design, per manager:

- `RecordProcesses` (was `RecordPm2Processes`) upserts every listed name of every manager in the tick.
- `FindDownProcesses` (was `FindDownPm2Processes`) returns the down names of a sample per manager: `array<manager, array<name, status>>`, a manager whose list is `null` or absent left out. A name is down when any instance's status is not the manager's `runningStatus()`, or when it is known and missing.
- A sample counts as unknown for a manager when the sample has no list for that manager. The streak runs per manager: `unreachable_after_samples` samples of the server in a row with something down for that manager.
- One cause, `IncidentCause::AUTO_APP_PM2` renamed to `AUTO_APP_PROCESS` (`auto_app_process`), label "Process down", title "A process stopped on {monitor}", recovery "Every process on {monitor} runs again". Details label each down name with its manager: `PM2 queue-worker`, value `errored` or `missing`.
- `ResolveProcessIncident` (was `ResolvePm2Incident`) resolves once no current server has anything down for any manager.
- Audit events become `process.ignored` and `process.watched` (`PROCESS_IGNORED`, `PROCESS_WATCHED`), with `manager` in the properties.

### App health tab

The block is titled "Processes" and shows only what a server reported:

- No learned process at all: no block.
- One table, grouped by manager in enum order. A manager without a learned process for this Monitor has no group, so a PM2 only app shows only PM2.
- A server whose newest sample carries an `error` for a manager that has learned processes on that server shows one line under that group: "{Manager} on {server} could not be read" with the error as its hint. A manager that was never readable on a server, so has no learned process there, shows nothing; the README carries the fix for Supervisor.
- Columns: process, status, restarts, memory, action. Restarts and memory read "–" for Supervisor.

`AppHealthVitals` for `AUTO_APP_PROCESS` lists the down names with their manager.

## my.tiemliner.com

Unchanged: it runs PM2. The release it requires is `^1.0`.

## Release

The Agent is tagged `v1.0.0` on `main` once this and the PM2 work are merged. Packagist is set up by Kylian.

## Not covered

- Supervisor programs on a server whose socket the scheduler's user cannot read, until the access change is made. Shown as an error once a process was learned there, never judged.
- systemd units and other process managers.
