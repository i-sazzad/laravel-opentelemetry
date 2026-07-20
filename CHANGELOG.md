# Changelog

## v2.0.0 (Unreleased)

### Breaking changes

- **PHP >= 8.2 and Laravel >= 10 required** (the code already used PHP 8.2 syntax; composer.json now says so).
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
- **Log severity numbers** now follow the OTel spec (DEBUG=5 … EMERGENCY=22). Log backends that filtered on the old numbers must be updated.

### Added

- W3C trace-context propagation: incoming `traceparent` headers continue the caller's trace.
- Batched span export (`BatchSpanProcessor`) instead of one synchronous export per span.
- Traces, metrics, and logs share one resource carrying `service.name`, `service.namespace`, `service.version`, `deployment.environment` (previously traces and logs exported as `unknown_service`).
- Middleware aliases `opentelemetry.trace` / `opentelemetry.metrics` auto-registered.
- Cache metrics now actually work, via Laravel cache events (the old undocumented `otel` cache-driver wrapper was removed).
- `enable_reachability_check` is honored, with configurable `reachability_timeout`.
- `system_metrics_enabled` flag.
- Test suite, PHPStan, Pint, GitHub Actions CI.

### Fixed

- Default `protocol`/`endpoint` no longer disagree (HTTP payloads were sent to the gRPC port).
- Exporter-setup failure fallback referenced a nonexistent Noop class and crashed.
- `Helper::flushMetrics()` no longer fatals when a Noop provider is bound.
- CPU metrics record time deltas in seconds instead of re-adding cumulative `/proc/stat` ticks every request.
- Disk metrics no longer do arithmetic on `false` when the path is invalid.
- `LARAVEL_START` is guarded, fixing crashes under Octane and PHPUnit.
- `http_requests_in_progress` is a real UpDownCounter instead of a histogram of +1/-1.
- Error responses (HTTP >= 400) now correctly mark the span status as Error (previously the unconditional Ok status made the later Error status a no-op).
