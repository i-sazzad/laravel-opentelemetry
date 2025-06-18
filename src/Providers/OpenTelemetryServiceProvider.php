<?php

namespace Laratel\Opentelemetry\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\StalenessHandler\ImmediateStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Cache for the reachability of the OpenTelemetry server.
     * Using a static variable ensures the check runs only once per process lifecycle.
     * @var bool|null
     */
    private static ?bool $isReachable = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');

        // Exit early if the OTLP endpoint is not configured or reachable.
        if (! $this->isOpenTelemetryServerReachable()) {
            return;
        }

        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        $this->registerTracerProvider($endpoint, $protocol);
        $this->registerMeterProvider($endpoint, $protocol);
    }

    /**
     * Lazily registers the TracerProvider and the Tracer itself.
     * The expensive object graph is only constructed when the tracer is requested.
     */
    private function registerTracerProvider(string $endpoint, string $protocol): void
    {
        // The TracerProvider is the main singleton.
        $this->app->singleton(TracerProvider::class, function () use ($endpoint, $protocol) {
            try {
                $transportFactory = match ($protocol) {
                    'grpc' => new GrpcTransportFactory(),
                    'http' => new OtlpHttpTransportFactory(),
                    default => throw new \InvalidArgumentException("Unsupported OTLP protocol: {$protocol}"),
                };

                $transport = match ($protocol) {
                    'grpc' => $transportFactory->create($endpoint . '/opentelemetry.proto.collector.trace.v1.TraceService/Export', 'application/x-protobuf'),
                    'http' => $transportFactory->create($endpoint . '/v1/traces', 'application/json'),
                };

                $spanExporter = new SpanExporter($transport);

                return new TracerProvider(
                    new SimpleSpanProcessor($spanExporter),
                    new AlwaysOnSampler(),
                    ResourceInfoFactory::defaultResource()
                );
            } catch (\Exception $e) {
                Log::error('OpenTelemetry TracerProvider setup failed.', [
                    'exception' => $e->getMessage(),
                    'stack' => $e->getTraceAsString(),
                ]);
                return new \OpenTelemetry\SDK\Trace\NoopTracerProvider();
            }
        });

        // The 'tracer' binding resolves the provider first, then gets the tracer.
        $this->app->singleton('tracer', function (Application $app) {
            /** @var TracerProvider $provider */
            $provider = $app->make(TracerProvider::class);
            return $provider->getTracer('otel-tracer');
        });

        // Alias for dependency injection.
        $this->app->alias('tracer', TracerInterface::class);
    }

    /**
     * Lazily registers the MeterProvider and the Meter itself.
     */
    private function registerMeterProvider(string $endpoint, string $protocol): void
    {
        // The MeterProvider is the main singleton.
        $this->app->singleton(MeterProvider::class, function () use ($endpoint, $protocol) {
            try {
                $transportFactory = match ($protocol) {
                    'grpc' => new GrpcTransportFactory(),
                    'http' => new OtlpHttpTransportFactory(),
                    default => throw new \InvalidArgumentException("Unsupported OTLP protocol: {$protocol}"),
                };

                $transport = match ($protocol) {
                    'grpc' => $transportFactory->create($endpoint . '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export', 'application/x-protobuf'),
                    'http' => $transportFactory->create($endpoint . '/v1/metrics', 'application/json'),
                };

                $metricExporter = new MetricExporter($transport);
                $attributesFactory = new AttributesFactory();

                return new MeterProvider(
                    null, // contextStorage is optional and will default
                    ResourceInfoFactory::defaultResource(),
                    new SystemClock(),
                    $attributesFactory,
                    new InstrumentationScopeFactory($attributesFactory),
                    [new ExportingReader($metricExporter)],
                    new CriteriaViewRegistry(),
                    null,
                    new ImmediateStalenessHandlerFactory()
                );
            } catch (\Exception $e) {
                Log::error('OpenTelemetry MeterProvider setup failed.', [
                    'exception' => $e->getMessage(),
                    'stack' => $e->getTraceAsString(),
                ]);
                return new \OpenTelemetry\SDK\Metrics\NoopMeterProvider();
            }
        });

        // The 'metrics' binding resolves the provider first, then gets the meter.
        $this->app->singleton('metrics', function (Application $app) {
            /** @var MeterProvider $provider */
            $provider = $app->make(MeterProvider::class);
            return $provider->getMeter('otel-metrics');
        });

        // Alias for dependency injection.
        $this->app->alias('metrics', MeterInterface::class);
    }

    /**
     * Check if the OpenTelemetry server is reachable.
     * Caches the result in a static variable to avoid repeated checks within a single process.
     */
    private function isOpenTelemetryServerReachable(): bool
    {
        // Use static cache to prevent re-checking during the same application lifecycle (e.g., in Octane).
        if (self::$isReachable !== null) {
            return self::$isReachable;
        }

        $endpoint = config('opentelemetry.endpoint');
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            Log::error('Invalid OTEL_EXPORTER_OTLP_ENDPOINT provided.', ['endpoint' => $endpoint]);
            return self::$isReachable = false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);

        // Determine the default port based on the protocol if not explicitly set in the URL.
        if (! $port) {
            $scheme = parse_url($endpoint, PHP_URL_SCHEME);
            // Default OTLP ports are 4317 for gRPC/HTTP and 4318 for HTTP/JSON.
            $port = ($scheme === 'https' || $scheme === 'http') ? 4318 : 4317;
        }

        // Use a very short timeout to avoid blocking the request.
        $timeout = 0.1;
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (is_resource($connection)) {
            fclose($connection);
            self::$isReachable = true;
        } else {
            Log::warning("OTLP endpoint is not reachable. OpenTelemetry will be disabled.", [
                'endpoint' => "{$host}:{$port}",
                'error' => trim($errstr),
            ]);
            self::$isReachable = false;
        }

        return self::$isReachable;
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], 'opentelemetry-config');
    }
}
