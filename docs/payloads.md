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
    "signals": {}
}
```

| Key | Type | Meaning |
|---|---|---|
| `signals` | object | Health signals keyed by name. Always a JSON object, empty until the Agent ships its first signals |

## Responses

Any `2xx` counts as delivered. A `429` skips this request without a retry. Anything else, including a timeout after 5 seconds, is logged as a warning and dropped.
