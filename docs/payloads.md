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

## Responses

Any `2xx` counts as delivered. A `429` skips this request without a retry. Anything else, including a timeout after 5 seconds, is logged as a warning and dropped.
