<?php

namespace Laratel\Opentelemetry\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Metrics\StalenessHandler\ImmediateStalenessHandlerFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Metrics\MetricFactory\StreamFactory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Context\ContextStorage;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');

        $this->registerTracer();
        $this->registerMetrics();
    }

    private function registerTracer(): void
    {
        $this->app->singleton('tracer', function () {
            $endpoint = config('opentelemetry.endpoint');
            $protocol = config('opentelemetry.protocol');

            if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !$this->isOpenTelemetryServerReachable()) {
                Log::error('Invalid or unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
                return null;  // Return null if endpoint is invalid
            }

            try {
                $spanExporter = match ($protocol) {
                    'grpc' => new SpanExporter(
                        (new GrpcTransportFactory())->create(
                            $endpoint . '/opentelemetry.proto.collector.trace.v1.TraceService/Export',
                            'application/x-protobuf'
                        )
                    ),
                    'http' => new SpanExporter(
                        (new OtlpHttpTransportFactory())->create(
                            $endpoint . '/v1/traces',
                            'application/json'
                        )
                    ),
                    default => throw new \InvalidArgumentException("Unsupported protocol: $protocol"),
                };

                return (new TracerProvider(
                    new SimpleSpanProcessor($spanExporter),
                    new AlwaysOnSampler(),
                    ResourceInfoFactory::defaultResource()
                ))->getTracer('otel_tracer');
            } catch (\Exception $e) {
                Log::error('OpenTelemetry Tracer connection error: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'stack' => $e->getTraceAsString()
                ]);
                return null;  // Return null if connection fails
            }
        });
    }

    private function registerMetrics(): void
    {
        $this->app->singleton('meterProvider', function () {
            $endpoint = config('opentelemetry.endpoint');
            $protocol = config('opentelemetry.protocol');

            if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !$this->isOpenTelemetryServerReachable()) {
                Log::error('Invalid or unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
                return null;  // Return null if endpoint is invalid
            }

            try {
                $metricExporter = match ($protocol) {
                    'grpc' => new MetricExporter(
                        (new GrpcTransportFactory())->create(
                            $endpoint . '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export',
                            'application/x-protobuf'
                        )
                    ),
                    'http' => new MetricExporter(
                        (new OtlpHttpTransportFactory())->create(
                            $endpoint . '/v1/metrics',
                            'application/json'
                        )
                    ),
                    default => throw new \InvalidArgumentException("Unsupported protocol: $protocol"),
                };

                $attributesFactory = new AttributesFactory();

                return new MeterProvider(
                    contextStorage: new ContextStorage(),
                    resource: ResourceInfoFactory::defaultResource(),
                    clock: new SystemClock(),
                    attributesFactory: $attributesFactory,
                    instrumentationScopeFactory: new InstrumentationScopeFactory($attributesFactory),
                    metricReaders: [new ExportingReader($metricExporter)],
                    viewRegistry: new CriteriaViewRegistry(),
                    exemplarFilter: null,
                    stalenessHandlerFactory: new ImmediateStalenessHandlerFactory(),
                    metricFactory: new StreamFactory(),
                    configurator: null
                );
            } catch (\Exception $e) {
                Log::error('OpenTelemetry Metrics connection error: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'stack' => $e->getTraceAsString()
                ]);
                return null;  // Return null if connection fails
            }
        });

        $this->app->singleton('metrics', function () {
            $meterProvider = $this->app->make('meterProvider');
            return $meterProvider ? $meterProvider->getMeter('otel_metrics') : null;  // Ensure meterProvider is available
        });
    }

    private function isOpenTelemetryServerReachable(): bool
    {
        $client = new Client();
        try {
            // Attempt to send a simple request (e.g., a GET request) to the OpenTelemetry endpoint
            $response = $client->get(config('opentelemetry.endpoint') . '/health', [
                'timeout' => 1 // Set a short timeout for the request
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            // Log the error but do not interrupt the request flow
            Log::warning('Failed to connect to OpenTelemetry server: ' . $e->getMessage());
            return false;  // Return false to skip tracing
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ]);
    }
}
