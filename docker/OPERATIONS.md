# Operations deployment contract

Use synthetic/local data for smoke checks. Provision production credentials separately;
never put secrets in Compose, client bundles, logs, diagnostics or notification ledgers.
Run `docker compose run --rm migrate` before accepting traffic. The outbox migration
is required, and `APP_KEY` must remain stable (or use Laravel's previous encryption
keys during rotation) until encrypted pending outbox payloads have drained.

## Readiness and workers

`/up` is process liveness only. Route traffic only to `/ready` returning 200; it checks
DB, migration, a cache write/read and (by default in production) recent worker and
scheduler heartbeats. It returns only `ready` or `unavailable`, never exception text.
Compose app checks prerequisites via `ops:health app`; nginx also checks HTTP readiness.
Workers and schedulers must start independently of app readiness to avoid a startup
cycle. Heartbeats expire after 180s; configure a larger window for long-running jobs.
One `OPERATIONS_HEARTBEAT_PREFIX` is shared by each deployment/worker group. These
are group-level availability checks, not per-replica proof; use orchestrator process
checks and a separate prefix/check for each independently required queue group.

`ops:outbox-replay` runs every minute and uses compare-and-swap claims with 300s leases.
Delivery attempts use 30s exponential backoff capped at one hour. Worker timeout (60s)
must stay below queue retry-after (90s), which must stay below the outbox lease. A dead
worker's lease is reclaimed. Delivery is at-least-once across process crashes: database
notifications are deterministic/idempotent; clients dedupe broadcasts by notification
ID. WhatsApp cannot promise exactly-once unless the chosen provider offers an
idempotency API. Completed encrypted payloads are removed but dedupe tombstones remain.
The notification log holds IDs/statuses and fixed error reasons, not phone/message text.

## Reverb and TLS

Reverb runs only on the internal Compose network at port 8080. Nginx proxies websocket
`/app/` and signed publishing `/apps/`. Terminate TLS at a trusted ingress in front of
nginx and configure `TRUSTED_PROXIES` to the actual ingress IP/CIDR (not a blanket wildcard
when the service is publicly reachable). Configure Laravel's publishing endpoint as
`BROADCAST_CONNECTION=reverb`, `REVERB_HOST=<public TLS host>`, `REVERB_PORT=443`,
`REVERB_SCHEME=https`. Outbound HTTPS to that hostname must resolve from app/worker.
The ingress must forward websocket upgrades; do not expose port 8080 or FPM publicly.
Provision `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`; only the **Reverb
public app key** belongs on the client, never Reverb secret or Laravel APP_KEY.
Configure approved origins in the separately owned Reverb configuration before production.

Subscribe to private `App.Models.User.<UUID>` with token-authenticated
`POST /api/v1/broadcasting/auth`. Broadcasts contain only `{id, kind: notification_available}`;
fetch details through the existing authenticated notifications API. Notifications generated
outside NotificationDispatcher must use the same safe broadcast/outbox pattern.
Read/unread remains a database API operation; clients invalidate/refetch on broadcasts.

## Vendor-neutral monitoring

`ops:monitor` runs every five minutes, emits fixed redacted diagnostics, exits nonzero
and logs CRITICAL when prerequisites/heartbeats/storage fail, failed jobs exist, the
queue reaches its threshold, or outbox records remain undelivered beyond the age limit.
Run it externally too: a stopped scheduler cannot alert on its own absence. Scrape only
CLI/log output into an operator-selected monitoring backend; diagnostics are not public
HTTP metrics. Alert on CRITICAL snapshots and nonzero exits, plus `/ready` 503 responses.
Do not scrape full exception/job payloads containing clinical information or tokens.

For an alert: check service health and DB/cache connectivity, run `ops:monitor`, restore
worker/scheduler, inspect outbox fixed failure reasons, and replay `ops:outbox-replay`.
Verify pending/backlog counts fall. Do not delete proofs or outbox rows to clear an alert.
`local_storage_writable` tests local log/upload directories only; cloud S3/provider delivery
and backup/restore tests need operator-approved external checks. Sentry/metrics backend,
alert receiver, retention and escalation policy remain explicit operator choices; this
change does not provision or claim working cloud monitoring.
