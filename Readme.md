# OpenTelemetry Laravel Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laratel/opentelemetry.svg?style=flat&color=blue)](https://packagist.org/packages/laratel/opentelemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/laratel/opentelemetry.svg?style=flat&color=green)](https://packagist.org/packages/laratel/opentelemetry)
[![Tests](https://github.com/i-sazzad/laravel-opentelemetry/actions/workflows/tests.yml/badge.svg)](https://github.com/i-sazzad/laravel-opentelemetry/actions/workflows/tests.yml)

Traces, metrics, and logs for Laravel over OTLP: HTTP request tracing with W3C trace-context propagation, database query spans, low-cardinality metrics for HTTP, database, cache, errors and system resources, and a log channel that correlates log records with the active trace.

It is built to stay out of your application's way: telemetry is exported after the response has been sent, and a collector that is down, slow, or misconfigured pauses telemetry instead of slowing down or breaking requests.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
- [When the collector is down](#when-the-collector-is-down)
- [PHP-FPM, Octane, and metric temporality](#php-fpm-octane-and-metric-temporality)
- [Exported telemetry](#exported-telemetry)
- [Troubleshooting](#troubleshooting)
- [Upgrading from v1](#upgrading-from-v1)
- [Related tools](#related-tools)
- [Development & contributing](#development--contributing)

---

## Features

- **Distributed tracing** — an incoming `traceparent` header continues the caller's trace (W3C trace context); every traced response carries `trace-id` / `span-id` headers.
- **HTTP request spans** with OTel semantic-convention attributes and route-template names (`GET /api/users/{id}`).
- **Database query spans** as children of the request span, with driver, database, and SQL statement.
- **Metrics** for HTTP requests, database queries, cache operations, errors, and Linux system resources — all with bounded, low-cardinality labels.
- **Log channel** that ships records over OTLP with the active trace and span IDs attached.
- **No request latency** — spans are batched and everything is exported when the application terminates, after the response has been sent.
- **Safe when the collector is down** — short export timeouts and a circuit breaker with a cool-down; see [When the collector is down](#when-the-collector-is-down).
- **One shared resource** — traces, metrics, and logs all carry `service.name`, `service.namespace`, `service.version`, and `deployment.environment`.
- Works under PHP-FPM and Octane; middleware aliases are registered automatically; configuration is env-driven and publishable.

---

## Requirements

- PHP 8.2 or newer
- Laravel 10, 11, 12, or 13 (Laravel 13 itself requires PHP 8.3)
- An OTLP endpoint, e.g. the [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/)
- For the `grpc` protocol only: `ext-grpc` and `open-telemetry/transport-grpc`

---

## Quick start

**1. Install**

```bash
composer require laratel/opentelemetry
```

The service provider is auto-discovered.

**2. Point it at your collector** in `.env`

```env
OTEL_SERVICE_NAME=my-app
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```

**3. Add the middleware** to the routes you want instrumented

```php
Route::middleware(['opentelemetry.metrics', 'opentelemetry.trace'])->group(function () {
    Route::get('api/example', fn () => response()->json(['message' => 'Tracing and metrics enabled']));
});
```

That is all that is needed for traces and metrics. For logs, add the [log channel](#logging).

### Exporting over gRPC

The default transport is OTLP over HTTP and needs nothing else. To use gRPC, install the `grpc` PHP extension and the transport package, then switch the protocol:

```bash
composer require open-telemetry/transport-grpc
```

```env
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4317
```

### Publishing the config file

Optional — only needed to change the excluded routes/queries or the system-metric paths, which have no environment variable:

```bash
php artisan vendor:publish --tag=opentelemetry-config
```

---

## Configuration

Every setting below lives in `config/opentelemetry.php`.

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `OTEL_ENABLED` | `true` | Master switch. `false` makes the package a no-op: no probe, no exporters. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http` | `http` (same as `http/json`), `http/protobuf`, or `grpc`. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://localhost:4318` | Base URL of the collector, without a `/v1/...` path (`http://localhost:4317` when the protocol is `grpc`). Without a port, the scheme default (80 / 443) is used. |
| `OTEL_EXPORTER_OTLP_HEADERS` | — | Extra headers for every export, as `key1=value1,key2=value2` — e.g. an auth token for a hosted backend. |
| `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE` | `delta` | `delta` or `cumulative`. See [metric temporality](#php-fpm-octane-and-metric-temporality). |
| `OTEL_SERVICE_NAME` | `laravel_app` | `service.name` resource attribute. |
| `OTEL_SERVICE_NAMESPACE` | `laratel` | `service.namespace` resource attribute. |
| `OTEL_SERVICE_VERSION` | `1.0.0` | `service.version` resource attribute. |
| `OTEL_ENABLE_REACHABILITY_CHECK` | `true` | Probe the endpoint (a TCP connect) before wiring telemetry up, and stay disabled if it is down. |
| `OTEL_REACHABILITY_TIMEOUT` | `0.1` | Probe timeout, in seconds. |
| `OTEL_EXPORT_TIMEOUT` | `3` | Seconds a single export may take. |
| `OTEL_EXPORT_RETRIES` | `0` | Retries for a failed export. |
| `OTEL_RETRY_AFTER` | `30` | Seconds telemetry stays paused after a failed probe or export. |
| `OTEL_SYSTEM_METRICS_ENABLED` | `true` | Collect CPU, memory, disk, and network metrics (reads `/proc`; Linux only). |
| `OTEL_STATE_PATH` | `storage/framework/cache/otel-system-metrics.json` | Small state file shared between requests. Set it empty to keep state in memory only. |

`deployment.environment` is taken from `APP_ENV`.

### Settings in the published config file

| Key | Default | Purpose |
|---|---|---|
| `excluded_routes` | `assets/*`, `uploads/*`, `css/*`, `js/*`, `images/*`, `fonts/*`, `metrics`, `favicon.ico`, `api/health`, `healthz`, `status` | Request paths, with `*` wildcards, skipped by both middleware. |
| `excluded_queries` | `` `contact_setting` ``, ``update `logs` `` | Plain, case-insensitive substrings. A query containing one gets no span. Replace these with your own. |
| `headers` | from env | May also be an array: `['x-api-key' => '...']`. |
| `cpu_path`, `memory_path`, `network_path`, `connection_path` | `/proc/stat`, `/proc/meminfo`, `/proc/net/dev`, `/proc/net/tcp` | Sources for system metrics. |
| `disk_path` | `/` | Filesystem whose capacity is reported. |

### The state file

`state_path` holds two small pieces of information that must survive from one request to the next, because under PHP-FPM every request is a new process:

- the previous CPU and network counter readings, so the next request can report the difference;
- the "collector is down" marker used by the circuit breaker.

It is read and written under a file lock. The default location is inside Laravel's `storage` directory, which is already writable by the web server. If the file cannot be written, state falls back to memory for the current process.

---

## Usage

### Middleware

| Alias | Class | What it does |
|---|---|---|
| `opentelemetry.trace` | `OpenTelemetryTraceMiddleware` | Creates a server span per request, continues an incoming `traceparent`, and traces database queries. |
| `opentelemetry.metrics` | `OpenTelemetryMetricsMiddleware` | Records HTTP, database, cache, error, and system metrics. |

Both aliases are registered by the service provider, so they can be used on routes directly, as in the [quick start](#quick-start).

To instrument **every** request, register them globally. On Laravel 11 and newer, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append([
        \Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware::class,
        \Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware::class,
    ]);
})
```

On Laravel 10, in `app/Http/Kernel.php`:

```php
protected $middleware = [
    \Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware::class,
    \Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware::class,
];
```

Applying a middleware both globally and on a route is harmless: the request is only instrumented once.

Paths matching `excluded_routes` pass straight through.

### Distributed tracing

When an upstream service (or browser instrumentation) sends a W3C `traceparent` header, the request span joins that trace instead of starting a new one, so a request that crosses several services produces one connected trace.

Every traced response includes the identifiers, which is handy for support tickets and log searches:

```
trace-id: 0af7651916cd43dd8448eb211c80319c
span-id:  b7ad6b7169203331
```

### Database query tracing

Queries that run inside a traced request — or inside any span you started yourself — get a child span. No setup is needed beyond the trace middleware.

- Spans are `CLIENT` spans named after the SQL operation: `select`, `insert`, `update`, … and `other` for anything unrecognised.
- Attributes: `db.system` (driver), `db.name` (database), `db.statement` (the SQL, with placeholders — bindings are not recorded).
- Queries outside any span (console commands, queued jobs, excluded routes) are not traced, so they never appear as orphan root spans.
- Silence noisy queries with `excluded_queries`.

### Logging

Add a channel to `config/logging.php`:

```php
'otel' => [
    'driver' => 'custom',
    'via' => Laratel\Opentelemetry\Logger\OtelLoggerFactory::class,
    'level' => 'debug',
],
```

Then use it directly, or add it to your `stack` channel so every log line is shipped:

```php
use Illuminate\Support\Facades\Log;

Log::channel('otel')->info('Order placed', ['order_id' => 1234]);
```

- Records carry the active trace and span IDs, so your backend can jump from a log line to its trace.
- Records below the channel's `level` are not exported.
- Context values are exported as attributes. Scalars and arrays are kept as they are; dates become ISO-8601 strings, enums their value, `Stringable` objects their string form, and other objects their class name.
- An `exception` in the context is exported as `exception.type`, `exception.message`, and `exception.stacktrace`.
- Severity numbers follow the OpenTelemetry Logs specification.

It is safe to make `otel` the default channel: when the collector is unreachable the channel discards records instead of failing.

### Custom spans

Resolve the `TraceService` from the container and use the tracer:

```php
use Laratel\Opentelemetry\Services\TraceService;

$tracer = app(TraceService::class)->getTracer();

if ($tracer) {
    $span = $tracer->spanBuilder('generate-invoice')->startSpan();
    $scope = $span->activate();

    try {
        $span->setAttribute('invoice.id', $invoice->id);
        // ... work; queries run here become children of this span
    } finally {
        $scope->detach();
        $span->end();
    }
}
```

`getTracer()` returns `null` when telemetry is disabled or the collector was unreachable — keep the guard so your code runs everywhere.

### Console commands and queued jobs

Telemetry is exported when the application terminates. An HTTP request and an Artisan command both do that on their own. A long-running process that does not — a queue worker handling many jobs, for instance — should flush explicitly when a unit of work is done:

```php
use Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider;

OpenTelemetryServiceProvider::flush(app());
```

---

## When the collector is down

Telemetry must never cost you a request. If the endpoint cannot be reached, refuses connections, or accepts them and never answers:

1. The first failed probe or export marks the collector **down for `OTEL_RETRY_AFTER` seconds** (30 by default) and writes one warning to your application log:

   ```
   [OTEL] OTLP endpoint unreachable: localhost:4318. Telemetry is paused for 30s.
   ```

2. While it is down, requests do not probe, do not export, and do not log the warning again. Spans, metrics, and log records produced in that window are dropped.
3. After the cool-down the next request tries again, and telemetry resumes on its own once the collector is back.

A single export is bounded by `OTEL_EXPORT_TIMEOUT` (3 seconds by default) and runs after the response has been sent, so even the one request that discovers the outage is not held up for long. The down marker lives in the [state file](#the-state-file), so all PHP-FPM workers share it.

**Octane and other long-lived workers:** if the collector was down when a worker booted, that worker stays disabled until it is reloaded (`php artisan octane:reload`). A collector that goes down and comes back while workers are running is handled by the cool-down as described above.

To turn the startup probe off — for example when the collector sits behind a proxy that always accepts connections — set `OTEL_ENABLE_REACHABILITY_CHECK=false`. Failed exports still trip the breaker.

---

## PHP-FPM, Octane, and metric temporality

Under PHP-FPM every request runs in a fresh process and exports its own metrics. That shapes two defaults.

**Metrics are exported with delta temporality.** A *cumulative* counter would restart from zero on every request and never grow; deltas are correct for both PHP-FPM and long-lived workers. Your pipeline has to accept delta metrics:

- The OpenTelemetry Collector's `prometheus` exporter accumulates deltas into the cumulative series Prometheus expects.
- If you send OTLP straight to Prometheus, delta ingestion has to be enabled there.
- If your backend cannot take deltas and you run a long-lived runtime such as Octane, set `OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=cumulative`.

**System counters are kept across requests.** CPU time and network I/O are differences between two readings of `/proc`. The previous reading is stored in the [state file](#the-state-file) under a lock, so each interval is reported by exactly one request no matter how many workers are running.

---

## Exported telemetry

All signals carry `service.name`, `service.namespace`, `service.version`, and `deployment.environment` as OTLP **resource** attributes, not as per-metric labels.

### Request span

Named `METHOD /route/template` (just the method when no route matched, so 404s do not create a span name per URL). Kind `SERVER`.

| Attribute | Value |
|---|---|
| `http.request.method` | `GET`, `POST`, … |
| `http.route` | Route template, e.g. `api/users/{id}` |
| `http.response.status_code` | Status code |
| `url.full` | Full URL including the query string |
| `client.address` | Client IP |
| `user_agent.original` | `User-Agent` header |
| `http.request.body.size` / `http.response.body.size` | Sizes in bytes |
| `http.server.duration_ms` | Request duration |
| `laravel.route.name` / `laravel.controller.action` | Route name and action |
| `enduser.id` | Authenticated user's identifier, when there is one |

Responses with status 400 and above mark the span as an error. An exception that Laravel rendered as an error page is recorded on the span as an `exception` event.

### Metrics

Labels are deliberately low-cardinality: route templates instead of raw paths, SQL operations instead of SQL text, exception classes instead of messages, and no cache keys.

**HTTP** — labels `http.request.method`, `http.route`, `http.response.status_code`

| Metric | Type |
|---|---|
| `laratel_http_request_total` | Counter |
| `laratel_http_status_code_total` | Counter |
| `laratel_http_failed_requests_total` (extra label `error.type`: `client_error` / `server_error`) | Counter |
| `laratel_http_request_latency_seconds` | Histogram |
| `laratel_http_request_size_bytes` | Histogram |
| `laratel_http_response_size_bytes` | Histogram |
| `laratel_http_requests_in_progress` | UpDownCounter |

**Database** — labels `db.operation`, `db.connection`

| Metric | Type |
|---|---|
| `laratel_db_query_total` | Counter |
| `laratel_db_query_latency_seconds` | Histogram |

**Cache** — label `cache.store`

| Metric | Type |
|---|---|
| `laratel_cache_hit_total` | Counter |
| `laratel_cache_miss_total` | Counter |
| `laratel_cache_store_total` | Counter |
| `laratel_cache_delete_total` | Counter |

**Errors** — label `exception.type`

| Metric | Type |
|---|---|
| `laratel_error_total` — includes exceptions Laravel rendered as an error response | Counter |

**System** (Linux only; disable with `OTEL_SYSTEM_METRICS_ENABLED=false`)

| Metric | Type | Labels |
|---|---|---|
| `laratel_system_cpu_time_seconds_total` | Counter | `state`: `user`, `nice`, `system`, `idle`, `iowait`, `irq`, `softirq`, `steal` |
| `laratel_system_memory_usage_bytes` | Gauge | `type`: `total`, `free`, `cached`, `buffers`, `used` |
| `laratel_system_disk_total_bytes` | Gauge | — |
| `laratel_system_disk_free_bytes` | Gauge | — |
| `laratel_system_disk_usage_bytes` | Gauge | — |
| `laratel_system_network_io_bytes_total` | Counter | `network.interface`, `network.io.direction` (`receive` / `transmit`) |
| `laratel_system_network_errors_total` | Counter | `network.interface`, `network.io.direction` |
| `laratel_system_network_dropped_total` | Counter | `network.interface`, `network.io.direction` |
| `laratel_active_network_connections` | Gauge | `network.state`: `ESTABLISHED`, `LISTEN`, `TIME_WAIT`, … |

---

## Troubleshooting

**Nothing arrives at the collector.**
Check your application log for an `[OTEL]` warning — it names the host and port that were tried and the reason. Then check that the middleware is applied to the route and the path is not in `excluded_routes`. `OTEL_EXPORTER_OTLP_ENDPOINT` must be the base URL; the package appends `/v1/traces`, `/v1/metrics`, and `/v1/logs` itself.

**Traces arrive but metrics do not (or counters never increase).**
Your pipeline is probably rejecting or mishandling delta metrics. See [metric temporality](#php-fpm-octane-and-metric-temporality).

**Telemetry stops for a while after a collector restart.**
That is the cool-down. It resumes within `OTEL_RETRY_AFTER` seconds; lower the value if 30 seconds is too long for you.

**The collector is behind HTTPS or a load balancer and is reported as unreachable.**
Include the port in the endpoint if it is not 443. Without a port, the scheme default is used for both the probe and the export.

**gRPC is selected and telemetry is disabled.**
The log will say that `ext-grpc` or `open-telemetry/transport-grpc` is missing. Install both, or switch to `http`.

**CPU or network metrics are missing.**
They need two readings, so the very first request after a deploy only stores a snapshot. They also need a writable `OTEL_STATE_PATH` under PHP-FPM and a readable `/proc` (Linux).

**Turning it off quickly.**
Set `OTEL_ENABLED=false`. The middleware can stay in place; it passes requests straight through.

---

## Upgrading from v1

v2 renames metric labels and span attributes to the OpenTelemetry semantic conventions, changes several metric types, and removes a few metrics and config keys. Dashboards and alerts built on v1 need updating. The full list is in [CHANGELOG.md](CHANGELOG.md); the changes most likely to need action are:

- Metrics default to **delta** temporality.
- `http.route` is the route template, not the raw path; `http.method` → `http.request.method`; `http.status_code` → `http.response.status_code`.
- Memory, disk, and connection-count metrics are **gauges**, not histograms.
- `excluded_queries` entries are plain substrings, not regular expressions.
- `open-telemetry/transport-grpc` is no longer installed automatically; gRPC users must require it.

---

## Related tools

A ready-made Docker Compose stack with the OpenTelemetry Collector, Prometheus, Tempo, Loki, Promtail, and Grafana is available at [i-sazzad/otel](https://github.com/i-sazzad/otel).

---

## Development & contributing

Contributions are welcome. Fork the repository, create a feature branch, and open a pull request.

```bash
composer update --ignore-platform-req=ext-grpc
```

```bash
composer test
```

```bash
composer analyse
```

```bash
composer format
```

The gRPC transport is a dev dependency, hence the platform flag on machines without `ext-grpc`. CI runs the tests (PHPUnit), static analysis (PHPStan level 5), and code style (Pint) on PHP 8.2 through 8.5.

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).
