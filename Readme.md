# OpenTelemetry Laravel Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laratel/opentelemetry.svg?style=flat&color=blue)](https://packagist.org/packages/laratel/opentelemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/laratel/opentelemetry.svg?style=flat&color=green)](https://packagist.org/packages/laratel/opentelemetry)
[![Tests](https://github.com/i-sazzad/laravel-opentelemetry/actions/workflows/tests.yml/badge.svg)](https://github.com/i-sazzad/laravel-opentelemetry/actions/workflows/tests.yml)

**OpenTelemetry Laravel** integrates OpenTelemetry into Laravel: automatic HTTP request tracing with W3C trace-context propagation, database query tracing, low-cardinality metrics for HTTP/database/cache/system resources, and OTLP log export with trace correlation.

---

## Features

- **Distributed tracing** — incoming `traceparent` headers continue the caller's trace (W3C trace context), and every response carries `trace-id` / `span-id` headers for correlation.
- **Automatic HTTP request tracing** with OTel semantic-convention attributes and route-template span names (`GET /api/users/{id}`).
- **Automatic database query tracing** with the SQL statement, connection, and execution time.
- **Metrics** for HTTP requests, database queries, cache operations (via Laravel cache events), errors, and Linux system resources — all with bounded, low-cardinality labels.
- **Efficient export** — spans are batched (`BatchSpanProcessor`) instead of exported one-by-one during the request.
- **One shared resource** — traces, metrics, and logs all carry `service.name`, `service.namespace`, `service.version`, and `deployment.environment`.
- **Graceful degradation** — if the collector is unreachable at boot, the package disables itself instead of breaking your app.
- Middleware aliases registered automatically; publishable, env-driven configuration.

See [CHANGELOG.md](CHANGELOG.md) for what changed in v2.0, including breaking label renames.

---

## Requirements

- PHP >= 8.2
- Laravel >= 10.x
- Dependencies:
    - `open-telemetry/exporter-otlp` ^1.1
    - `open-telemetry/sdk` ^1.1
    - `open-telemetry/transport-grpc` ^1.1

---

## Installation

### 1. Install via Composer

```bash
composer require laratel/opentelemetry
```

⚠️ If you encounter the following error:

```
open-telemetry/transport-grpc requires ext-grpc * -> it is missing from your system.
```

It means the **gRPC PHP extension** is not installed or enabled. You can fix this by:

- Enabling the `grpc` extension in your `php.ini` file:

  ```
  extension=grpc
  ```

- Restarting your web server (Apache, Nginx) or PHP-FPM service after making changes.

- Alternatively — and always, if you only use the HTTP protocol — bypass the requirement:

  ```bash
  composer require laratel/opentelemetry --ignore-platform-req=ext-grpc
  ```

### 2. Register the Service Provider (optional)

The provider is auto-discovered. To register it manually, add it to `config/app.php`:

```php
'providers' => [
    // Other Service Providers...
    Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider::class,
],
```

### 3. Publish the Configuration File

```bash
php artisan vendor:publish --provider="Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider"
```

This creates `config/opentelemetry.php`. Update the settings as needed, such as the OTLP endpoint, excluded routes, and system-metrics paths.

---

## Configuration

### `config/opentelemetry.php`

| Key | Default | Purpose |
|---|---|---|
| `protocol` | `http` | OTLP transport: `http` or `grpc` (gRPC requires `ext-grpc`). |
| `endpoint` | `http://localhost:4318` | OTLP collector endpoint (`4317` when protocol is `grpc`). |
| `service_name` / `service_namespace` / `service_version` | `laravel_app` / `laratel` / `1.0.0` | Resource attributes attached to every exported trace, metric, and log. |
| `enable_reachability_check` | `true` | Probe the collector on boot and disable telemetry if it is down. |
| `reachability_timeout` | `0.1` | Probe timeout in seconds. |
| `system_metrics_enabled` | `true` | Toggles CPU/memory/disk/network collection (reads `/proc`, Linux only). |
| `excluded_routes` | assets, health checks, … | Paths (with `*` wildcards) skipped by both middleware. |
| `excluded_queries` | — | Plain, **case-insensitive substrings**; any query containing one is not traced. |
| `cpu_path`, `memory_path`, `disk_path`, `network_path`, `connection_path` | `/proc/...`, `/` | Source paths for system metrics. |

### Environment Variables

```env
OTEL_SERVICE_NAME=your_service_name
OTEL_SERVICE_NAMESPACE=your_namespace
OTEL_SERVICE_VERSION=1.0.0
OTEL_EXPORTER_OTLP_PROTOCOL=http
OTEL_EXPORTER_OTLP_ENDPOINT=http://your_otel_collector:4318
OTEL_ENABLE_REACHABILITY_CHECK=true
OTEL_REACHABILITY_TIMEOUT=0.1
OTEL_SYSTEM_METRICS_ENABLED=true
```

### Log Channel Configuration

Add an `otel` channel to `config/logging.php` to ship logs over OTLP with trace correlation:

```php
'otel' => [
    'driver' => 'custom',
    'via' => Laratel\Opentelemetry\Logger\OtelLoggerFactory::class,
    'level' => 'debug',
],
```

---

## Usage

### Middleware

The package provides two middleware:

1. **`opentelemetry.trace`** — creates a server span per request (continuing any incoming `traceparent`) and traces database queries.
2. **`opentelemetry.metrics`** — records HTTP, database, cache, error, and system metrics.

Both aliases are **registered automatically** by the service provider, so route usage needs no Kernel changes:

```php
Route::middleware(['opentelemetry.metrics', 'opentelemetry.trace'])->group(function () {
    Route::get('api/example', function () {
        return response()->json(['message' => 'Tracing and metrics enabled']);
    });
});
```

To instrument **every** request, register them globally in `app/Http/Kernel.php`:

```php
protected $middleware = [
    \Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware::class,
    \Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware::class,
];
```

### Distributed Tracing

When an upstream service (or the browser instrumentation) sends a W3C `traceparent` header, the request span joins that trace instead of starting a new one — so a request that crosses several services produces **one** connected trace.

Every traced response also includes the identifiers for correlation:

```
trace-id: 0af7651916cd43dd8448eb211c80319c
span-id:  b7ad6b7169203331
```

### Logging

Use the `otel` log channel for logs with trace context:

```php
use Illuminate\Support\Facades\Log;

Log::channel('otel')->info('Test log for OpenTelemetry collector', ['user' => 'example']);
```

Logs carry the active trace/span IDs and are batched to the configured collector. Severity numbers follow the OpenTelemetry Logs specification.

### Automatic Query Tracing

Database queries are traced automatically when the trace middleware is active. Spans are named after the SQL operation (`select`, `update`, …) and include:

- `db.system` — the database driver
- `db.name` — the database name
- `db.statement` — the full SQL statement

Exclude noisy queries via `excluded_queries` in `config/opentelemetry.php`.

### Custom Instrumentation

Use the `TraceService` to create custom spans (resolve it from the container — it is a singleton):

```php
use Laratel\Opentelemetry\Services\TraceService;

$tracer = app(TraceService::class)->getTracer();

if ($tracer) {
    $span = $tracer->spanBuilder('custom-operation')->startSpan();
    $span->setAttribute('custom.attribute', 'value');
    // Perform some operation
    $span->end();
}
```

`getTracer()` returns `null` when telemetry is disabled (e.g. collector unreachable) — guard for it so your code runs everywhere.

---

## Exported Metrics

All metrics carry `service.name` etc. as OTLP **resource** attributes (not per-metric labels). Labels are deliberately low-cardinality: route templates instead of raw paths, SQL operations instead of SQL text, exception classes instead of messages, and no cache keys.

#### HTTP Metrics — labels: `http.request.method`, `http.route`, `http.response.status_code`
- laratel_http_request_total
- laratel_http_status_code_total
- laratel_http_failed_requests_total (extra label: `error.type`)
- laratel_http_request_latency_seconds
- laratel_http_request_size_bytes
- laratel_http_response_size_bytes
- laratel_http_requests_in_progress (UpDownCounter)

#### System Metrics (Linux only)
- laratel_system_cpu_time_seconds_total — per-`state` time deltas in seconds
- laratel_system_memory_usage_bytes — per `type`
- laratel_system_disk_total_bytes
- laratel_system_disk_free_bytes
- laratel_system_disk_usage_bytes

#### Network Metrics — labels: `network.interface`, `network.io.direction`
- laratel_system_network_io_bytes_total — per-direction byte deltas
- laratel_system_network_dropped_total
- laratel_system_network_errors_total
- laratel_active_network_connections — per TCP `network.state`

#### Database Metrics — labels: `db.operation`, `db.connection`
- laratel_db_query_total
- laratel_db_query_latency_seconds

#### Cache Metrics — label: `cache.store`
- laratel_cache_hit_total
- laratel_cache_miss_total
- laratel_cache_store_total
- laratel_cache_delete_total

#### Error Metrics — label: `exception.type`
- laratel_error_total

---

## Repository for Related Tools and Configurations

Find a complete repository containing a Docker Compose file and configuration for OpenTelemetry Collector, Prometheus, Tempo, Loki, Promtail and Grafana [here](https://github.com/i-sazzad/otel).

---

## Development & Contributing

Contributions are welcome! Please fork the repository, create a feature branch, and submit a pull request.

```bash
composer update --ignore-platform-req=ext-grpc

composer test      # PHPUnit
composer analyse   # PHPStan (level 5)
composer format    # Laravel Pint
```

The CI workflow runs the same three checks on PHP 8.2–8.4.

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).
