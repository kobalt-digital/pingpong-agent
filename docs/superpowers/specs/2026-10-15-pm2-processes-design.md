# PM2 processes

Date: 2026-10-15

## Goal

Apps that run long lived processes under PM2 (my.tiemliner.com runs `queue-pusher` and `queue-worker`) check them today with a command of their own that mails an administrator. The tick takes that over: the Agent reports what PM2 runs, PingPong learns the processes per server and raises an Incident when one stops running or disappears.

The Agent reports facts. PingPong judges them, as with every other signal.

Three repositories change: `pingpong-agent`, `pingpong.kobaltdigital.nl` and `my.tiemliner.com`.

## Agent

### Signal

`Signals\Pm2`, named `pm2`, added to the default `signals` config after `AppState`. It is a platform fact with an `error`, like the backlog half of `queue`:

- `pm2` is not on the `PATH` of the process: left out. Nothing is spawned.
- `pm2` is on the `PATH` but no daemon runs for this user (`$PM2_HOME/pm2.pid` is missing, `PM2_HOME` defaulting to `~/.pm2`): `processes` is an empty list. No daemon means no process runs, and that is what a server rebooted without `pm2 startup` looks like. The Agent never runs a PM2 command here, since every PM2 command starts a daemon when none runs.
- Otherwise the Agent runs `pm2 jlist` through Laravel's `Process` facade with a 3 second timeout.
- A timeout, a non zero exit code or output without a JSON array gives `processes: null` and the reason in `error` (at most 255 characters). PM2 prints notices such as "In-memory PM2 is out-of-date" before the JSON, so the Agent parses from the first line that starts with `[`.

```json
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
}
```

| Key | Type | Meaning |
|---|---|---|
| `processes` | array or null | Every process `pm2 jlist` lists, sorted by `id`, at most 100. `null` when the list could not be read |
| `processes[].id` | integer | `pm_id` |
| `processes[].name` | string | `name`. Instances of one app in cluster mode share it |
| `processes[].status` | string | `pm2_env.status`: `online`, `stopping`, `stopped`, `launching`, `errored`, `one-launch-status` or `waiting restart` |
| `processes[].restarts` | integer | `pm2_env.restart_time` |
| `processes[].uptime_seconds` | integer or null | Seconds since `pm2_env.pm_uptime` while `online`, else `null` |
| `processes[].memory_bytes` | integer or null | `monit.memory`, `null` when PM2 leaves it out |
| `error` | string or null | Why the list could not be read |

Only these keys leave the server. `pm2_env` holds the environment of every process, secrets included, and is never sent.

The `pm2` lookup and the run live in a `Pm2` class next to `Host`, so tests fake it the way they fake `Host`. The cost is one `node` process per tick on servers that run PM2, and a `PATH` scan plus one `is_file` on every other server.

### Docs and fixtures

`docs/payloads.md` gains `signals.pm2`, the README lists the signal, and `tests/Fixtures/schema-1/tick.json` carries the example above. The schema stays `1`.

## PingPong

### Intake

- `TickRequest` validates `signals.pm2`: `processes` nullable list, max 100, each with `id` integer, `name` string max 255, `status` string max 32, `restarts` integer min 0, `uptime_seconds` and `memory_bytes` nullable integer min 0; `error` nullable string. The tick fixture is copied from the Agent.
- `RecordAppHealthSample::SIGNALS` gains `pm2`. The sample stores `pm2_processes` (json, nullable) and `pm2_error` (string, nullable, cut with `AgentError::cut`).

### Learned processes

New table `agent_pm2_processes`:

| Column | Type | Meaning |
|---|---|---|
| `monitor_id` | foreign key | |
| `server` | string | Server that runs it |
| `name` | string | PM2 process name |
| `status` | string, nullable | `online` when every instance in the last list that had it was, else the status of the first instance that was not |
| `restarts` | integer, nullable | Sum of restarts of its instances in that list |
| `first_seen_at` | timestamp | |
| `last_seen_at` | timestamp | Last tick that listed it |
| `ignored_at` | timestamp, nullable | Set by "Stop watching" |

Unique on `monitor_id`, `server`, `name`. A process is known by its name: PM2 hands out a new `id` after a delete and start, and cluster instances share a name. A name is down when any of its instances is not `online`.

`RecordPm2Processes` runs for every tick whose `pm2.processes` is a list, also while the Monitor is paused or App health is off, so a Monitor that is switched on later already knows its processes. It upserts every listed name with `last_seen_at`, `status` and `restarts`. A `null` list (an `error`) learns nothing and changes nothing.

### Judging

`TrackPm2Processes` runs from `RecordAppHealthSample::judge`, so only for an active Monitor with App health on, after the learning above.

- A sample is down when, for its server, a known name that is not ignored is missing from the list, or is listed with a status other than `online`.
- A sample without a `pm2` signal, or with `processes: null`, is unknown: it opens and resolves nothing.
- Down in `unreachable_after_samples` samples of the server in a row opens `IncidentCause::AUTO_APP_PM2` through `RaiseAppHealthIncident`. Details list every down name of that server with its status, or `missing`.
- The Incident resolves once the latest current sample of every server with a `pm2` list is not down: "Every PM2 process runs again".

`AUTO_APP_PM2` is wired like the other App health causes: capability `APP_HEALTH`, work category `APP_HEALTH`, label "PM2 process down", the open and resolve messages, `Incidents\Detail` and `AppHealthVitals` (reading: the down names, healthy when none).

### Stop watching

The App health tab shows a PM2 block under the server table when the Monitor knows any process: one row per server and name with status, restarts, uptime, memory and last seen, and a "Stop watching" or "Watch again" action. The actions need `update` on the Monitor and set or clear `ignored_at`.

Ignoring a name covers both a process removed on purpose (`pm2 delete`) and a process of another site on a shared daemon. An ignored name stays ignored when it is listed again. Ignoring the name that holds an open `AUTO_APP_PM2` Incident judges that Incident again at once and resolves it when nothing else is down.

No trend chart and no hourly roll up.

### Not covered

- A process PingPong never saw in a list. A server that reboots before its first tick with PM2 running is unknown, not down.
- A server whose `pm2` binary disappears from the `PATH`: the signal is left out, which is unknown.

## my.tiemliner.com

A draft pull request on `develop`, merged after the Agent release with `pm2` is out and PingPong is deployed:

- Require `kobaltdigital/pingpong-agent` at the release that carries `pm2`, and add `PINGPONG_KEY=` to `.env.example`.
- Remove `CheckPm2ProcessStatus`, `config/pm2.php`, `PM2_ADMINISTRATOR_EMAIL` and the two `pm2:check` lines in the console kernel.

The scheduler runs as the user that owns the PM2 daemon, as the current check already requires.

## Order

1. Agent: signal, docs, fixture, tests. Release.
2. PingPong: migration, intake, learning, judging, Incident cause, App health block, tests. Deploy.
3. my.tiemliner.com: install the Agent, set `PINGPONG_KEY`, switch App health on, remove the old check.
