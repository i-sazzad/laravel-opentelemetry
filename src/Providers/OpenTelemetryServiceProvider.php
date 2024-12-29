<?php

namespace Laratel\Opentelemetry\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Metrics\StalenessHandler\ImmediateStalenessHandlerFactory;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
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
            $endpoint = config('opentelemetry.endpoint') . '/opentelemetry.proto.collector.trace.v1.TraceService/Export';

            if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            }

            $transport = (new GrpcTransportFactory())->create($endpoint, 'application/x-protobuf');
            $spanExporter = new SpanExporter($transport);

            return (new TracerProvider(
                new SimpleSpanProcessor($spanExporter),
                new AlwaysOnSampler(),
                ResourceInfoFactory::defaultResource()
            ))->getTracer('otel_tracer');
        });
    }

    private function registerMetrics(): void
    {
        $this->app->singleton('meterProvider', function () {
            $endpoint = config('opentelemetry.endpoint') . '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export';

            if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException('Invalid OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            }

            $transport = (new GrpcTransportFactory())->create($endpoint, 'application/x-protobuf');
            $metricExporter = new MetricExporter($transport);
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
        });

        $this->app->singleton('metrics', function () {
            $meterProvider = $this->app->make('meterProvider');
            return $meterProvider->getMeter('otel_metrics');
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ]);
    }

}
