# OpenTelemetry Laravel Package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laratel/opentelemetry.svg?style=flat&color=blue)](https://packagist.org/packages/laratel/opentelemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/laratel/opentelemetry.svg?style=flat&color=green)](https://packagist.org/packages/laratel/opentelemetry)

**OpenTelemetry Laravel** is a Laravel package that integrates OpenTelemetry for automatic HTTP request tracing, query tracing, metrics collection, and enhanced logging with contextual trace information.

---

## Features

- Automatic HTTP request tracing.
- Automatic database query tracing with detailed SQL metrics.
- Metrics collection for HTTP requests, database queries, and system performance.
- Enhanced logging with contextual trace information.
- Middleware support for seamless integration.
- Customizable configuration.

---

## Requirements

- PHP >= 8.0
- Laravel >= 9.x
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
open-telemetry/transport-grpc 1.1.3 requires ext-grpc * -> it is missing from your system.
```

It means the **gRPC PHP extension** is not installed or enabled. You can fix this by:

- Enabling the `grpc` extension in your `php.ini` file:

  ```
  extension=grpc
  ```

  For example: `C:\xampp\php\php.ini` on Windows.

- Restart your web server (Apache, Nginx) or PHP-FPM service after making changes.

- Alternatively, to bypass this requirement during development, use:

  ```bash
  composer require laratel/opentelemetry --ignore-platform-req=ext-grpc
  ```

---

### 2. Register the Service Provider

Add the service provider to the `providers` array in `config/app.php` (this step is optional if your package uses auto-discovery):

```php
'providers' => [
    // Other Service Providers...
    Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider::class,
],
```

---

### 3. Publish the Configuration File

Publish the configuration file to your application:

```bash
php artisan vendor:publish --provider="Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider"
```

This will create a configuration file at `config/opentelemetry.php`. Update the settings as needed, such as the OTLP endpoint, excluded routes, and logging configuration.

---

## Configuration

### OpenTelemetry Configuration

The `config/opentelemetry.php` file allows you to configure:

- **OTLP Endpoint**:
  Specify the OTLP collector endpoint for sending telemetry data.

- **Excluded Routes**:
  Define routes to exclude from tracing or metrics collection.

- **Excluded Queries**:
  Specify database queries that should not be traced.

---

### Log Channel Configuration

Add the following configuration to `config/logging.php` for enhanced OpenTelemetry logging:

```php
'otel' => [
    'driver' => 'custom',
    'via' => Laratel\Opentelemetry\Logger\OtelLoggerFactory::class,
    'level' => 'debug',
],
```

---

## Usage

## Environment Variables

To configure OpenTelemetry via environment variables, include the following in your `.env` file:

```env
OTEL_SERVICE_NAME=your_service_name
OTEL_TRACES_SAMPLER=always_on
OTEL_EXPORTER_OTLP_PROTOCOL=grpc
OTEL_EXPORTER_OTLP_ENDPOINT=http://your_otel_collector_endpoint:port
OTEL_RESOURCE_ATTRIBUTES=deployment.environment=production,service.namespace=service_namespace,service.version=1.0,service.instance.id=instance_id
```

---

### Middleware

The package provides two middleware for automatic tracing and metrics collection:

1. **`opentelemetry.metrics`**: Collects HTTP and system metrics.
2. **`opentelemetry.trace`**: Captures tracing information for HTTP requests and database queries.

#### Register Middleware in Kernel

To apply middleware globally, add them to the `$middleware` array in `app/Http/Kernel.php`:

```php
protected $middleware = [
    \Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware::class,
    \Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware::class,
];
```

#### Register Middleware Aliases

Alternatively, register middleware aliases in the `Kernel` class:

```php
protected $routeMiddleware = [
    'opentelemetry.metrics' => \Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware::class,
    'opentelemetry.trace' => \Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware::class,
];
```

#### Use Middleware in Routes

Once aliases are registered, use them in your routes:

```php
Route::middleware(['opentelemetry.metrics', 'opentelemetry.trace'])->group(function () {
    Route::get('api/example', function () {
        return response()->json(['message' => 'Tracing and metrics enabled']);
    });
});
```

---

### Logging

Use the `otel` log channel for enhanced logging with trace context:

```php
use Illuminate\Support\Facades\Log;

Log::channel('otel')->info('Test log for OpenTelemetry collector', ['user' => 'example']);
```

Logs will include trace information and be sent to the configured OpenTelemetry collector.

---

### Automatic Query Tracing

The package automatically traces database queries. Traces include:

- SQL statements
- Bindings
- Execution times

You can customize which queries to exclude using the `config/opentelemetry.php` file.

---

### Custom Instrumentation

#### Custom Traces

Use the `TraceService` to create custom traces:

```php
use Laratel\Opentelemetry\Services\TraceService;

$traceService = new TraceService();
$tracer = $traceService->getCustomTracer();

$span = $tracer->spanBuilder('custom-operation')->startSpan();
$span->setAttribute('custom.attribute', 'value');
// Perform some operation
$span->end();
```

---

## Repository for Related Tools and Configurations

Find a complete repository containing Docker Compose file, configuration files for OpenTelemetry Collector, Prometheus, Tempo, Loki, Promtail and Grafana [here](https://github.com/i-sazzad/otel).

---

## Contributing

Contributions are welcome! Please fork the repository, create a feature branch, and submit a pull request.

---

## License

This package is open-source software licensed under the [MIT license](LICENSE).

