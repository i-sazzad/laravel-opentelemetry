<?php

namespace Laratel\Opentelemetry\Providers;

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
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Metrics\MetricFactory\StreamFactory;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Context\ContextStorage;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');

        if($this->isOpenTelemetryServerReachable()){
            $this->registerTracer();
            $this->registerMetrics();
        }
    }

    private function registerTracer(): void
    {
        $this->app->singleton('tracer', function () {
            $endpoint = config('opentelemetry.endpoint');
            $protocol = config('opentelemetry.protocol');

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

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function isOpenTelemetryServerReachable(): bool
    {
        if (request()->has('otel.server_reachable')) {
            return request()->get('otel.server_reachable');
        }

        $endpoint = config('opentelemetry.endpoint');
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            Log::error('Invalid OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            request()->merge(['otel.server_reachable' => false]);  // Store it in the request
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT) ?? 4318;

        $connection = @fsockopen($host, $port, $errno, $err_str, 0.1);

        $reachable = false;
        if ($connection) {
            fclose($connection);
            $reachable = true;
        } else {
            Log::error('Unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided. Error: ' . $err_str);
        }

        // Store the result in the request object for this lifecycle
        request()->merge(['otel.server_reachable' => $reachable]);

        return $reachable;
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ]);
    }
}
