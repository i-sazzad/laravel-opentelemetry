# Changelog

## v2.0.0 (Unreleased)

### Breaking changes

- **PHP >= 8.2 and Laravel >= 10 required** (the code already used PHP 8.2 syntax; composer.json now says so). Laravel 13 is supported; CI runs on PHP 8.2 through 8.5.
- **`excluded_queries` config values are now plain case-insensitive substrings**, not regexes. Previously the shipped regex defaults never matched anything.
- **Metric labels changed** (dashboards must be updated):
  - `service.name`, `host` / `net.host.name` labels removed from all metrics — they are now OTLP *resource* attributes on every export.
  - HTTP metrics: `http.method`→`http.request.method`, `http.status_code`→`http.response.status_code`; `http.route` now uses the route template (e.g. `api/users/{id}`) instead of the raw path.
  - DB metrics: the raw-SQL `query` label is replaced by `db.operation` + `db.connection`.
  - Cache metrics: the `key` label is removed; `cache.store` added.
  - Error metric: the `message` label is replaced by `exception.type`.
  - Network metrics: split by `network.interface` + `network.io.direction`; values are now correct deltas instead of ever-growing cumulative totals.
- **Metrics removed:** `laratel_application_uptime_seconds`, `laratel_network_inbound_bytes`, `laratel_network_outbound_bytes`, `laratel_db_error_total`.
- **Span attributes renamed to OTel semantic conventions:** `http.method`→`http.request.method`, `http.url`→`url.full`, `http.status_code`→`http.response.status_code`, `http.client_ip`→`client.address`, `http.user_agent`→`user_agent.original`.
- **DB query span names** are now the SQL operation keyword (`select`, `update`, …) instead of the full SQL text (still available in `db.statement`).
- **Config keys removed** (were never read): `traces_exporter`, `logs_exporter`, `metrics_exporter`, `propagators`, `traces_sampler`, `network_states`, `resource_attributes`.
- **Point-in-time system metrics are now gauges**, not histograms: `laratel_system_memory_usage_bytes`, `laratel_system_disk_{total,free,usage}_bytes`, `laratel_active_network_connections`. Queries that used `_bucket` / `_sum` / `_count` series must read the gauge value instead.
- **Telemetry is exported when the application terminates** (after the response is sent) instead of inside the metrics middleware. Code that calls the middleware by hand must call `$app->terminate()` or `OpenTelemetryServiceProvider::flush($app)`.
- **Server span names** start as the bare HTTP method and are renamed to `METHOD /route/template` once the route is matched; unmatched requests (404s) no longer create one span name per URL.
- **DB query spans** are only created inside an active span (no orphan root spans from console commands, queues, or excluded routes), are `CLIENT` spans, and unrecognised leading keywords are reported as `other`.
- **The reachability probe uses the URL's default port** (80/443) when the endpoint has none, matching where the exporter actually connects, instead of assuming 4317/4318.
- **Metrics are exported with delta temporality by default** (was the SDK's cumulative default). Under PHP-FPM cumulative counters restart at zero on every request and never grew. Set `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=cumulative` if your backend cannot ingest delta metrics and you run a long-lived runtime.
- **`open-telemetry/transport-grpc` is no longer a hard dependency.** HTTP users no longer need `--ignore-platform-req=ext-grpc`; gRPC users must `composer require open-telemetry/transport-grpc`.
- **Log severity numbers** now follow the OTel spec (DEBUG=5 … EMERGENCY=22). Log backends that filtered on the old numbers must be updated.

### Added

- W3C trace-context propagation: incoming `traceparent` headers continue the caller's trace.
- Batched span export (`BatchSpanProcessor`) instead of one synchronous export per span.
- Traces, metrics, and logs share one resource carrying `service.name`, `service.namespace`, `service.version`, `deployment.environment` (previously traces and logs exported as `unknown_service`).
- Middleware aliases `opentelemetry.trace` / `opentelemetry.metrics` auto-registered.
- Cache metrics now actually work, via Laravel cache events (the old undocumented `otel` cache-driver wrapper was removed).
- `enable_reachability_check` is honored, with configurable `reachability_timeout`.
- `system_metrics_enabled` flag.
- **Circuit breaker for a dead collector**: after a failed probe or export the collector is marked down for `retry_after` seconds (`OTEL_RETRY_AFTER`, default 30). While down, requests neither probe, nor export, nor log again; the marker is shared between PHP-FPM requests through `state_path`.
- `export_timeout` (`OTEL_EXPORT_TIMEOUT`, default 3s) and `export_retries` (`OTEL_EXPORT_RETRIES`, default 0).
- `enabled` (`OTEL_ENABLED`) master switch.
- `headers` (`OTEL_EXPORTER_OTLP_HEADERS`) for authenticated/hosted collectors.
- `metrics_temporality` (`OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE`): `delta` (default) or `cumulative`.
- `state_path` (`OTEL_STATE_PATH`): previous CPU/network counter readings are kept in a lock-protected file, so `laratel_system_cpu_time_seconds_total` and the network counters now produce values under PHP-FPM (they previously only worked in long-lived workers).
- `http/json` and `http/protobuf` protocol values, alongside `http` and `grpc`.
- The log channel honors its `level` option.
- Exceptions handled by Laravel (rendered as error responses) are recorded on the request span and counted in `laratel_error_total`.
- Test suite, PHPStan, Pint, GitHub Actions CI.

### Fixed

- Default `protocol`/`endpoint` no longer disagree (HTTP payloads were sent to the gRPC port).
- Exporter-setup failure fallback referenced a nonexistent Noop class and crashed.
- `Helper::flushMetrics()` no longer fatals when a Noop provider is bound.
- CPU metrics record time deltas in seconds instead of re-adding cumulative `/proc/stat` ticks every request.
- Disk metrics no longer do arithmetic on `false` when the path is invalid.
- `http_requests_in_progress` is a real UpDownCounter instead of a histogram of +1/-1.
- Error responses (HTTP >= 400) now correctly mark the span status as Error (previously the unconditional Ok status made the later Error status a no-op).
- **Spans were never exported for normal requests**: the batch processor was never flushed, so spans were dropped unless a request produced 512 of them or ran longer than 5 seconds. Traces, metrics, and logs are now flushed when the application terminates, with a shutdown-time fallback.
- Exporting no longer delays the response: the metrics middleware used to export synchronously before returning it.
- `laratel_active_network_connections` always reported 0 (the `/proc/net/tcp` pattern could not match a real line).
- Network interfaces whose names contain `-`, `.` or `@` (`br-…`, `eth0.100`, `veth…@if7`) were skipped.
- An unreadable `/proc` file or a failing instrument could turn a successful request into a 500; system/DB/cache/error recording can no longer throw into the application.
- Using the `otel` channel as the default log channel (or inside the default stack) recursed endlessly when the collector was unreachable.
- Log context containing objects (models, dates, enums) is normalized instead of being dropped or breaking the export; non-string messages are cast.
- A trailing slash on the endpoint no longer produces `//v1/traces` (404).
- The log channel and the service provider now share one reachability probe and one protocol/endpoint resolution (they previously disagreed on the default port and on unknown protocols).
- DB/cache listeners no longer capture a stale service instance (Octane) and are registered once per event dispatcher; they no longer require a default database connection to be configured.
- `http.route`/`db.operation` labels stay bounded for multi-line SQL; an empty `excluded_queries` entry no longer excludes every query; a published config missing `excluded_routes` no longer errors.
- The request span start time is taken per request (`REQUEST_TIME_FLOAT`); `LARAVEL_START` is fixed at worker boot under Octane.
- `enduser.id` uses the auth identifier instead of assuming an `id` property.
- Applying the trace middleware both globally and on a route no longer creates nested duplicate spans.
- **A collector that was down, or accepted connections without answering, could take the application down with it**: every export waited for the SDK defaults (10s per attempt, 3 retries, for each of traces, metrics and logs — up to two minutes per request), running into PHP's `max_execution_time`. Exports now fail fast, the first failure pauses telemetry for the cool-down, and the remaining signals are dropped instead of each timing out.
- The unreachable-collector warning was logged on every request; it is now logged once per cool-down.
