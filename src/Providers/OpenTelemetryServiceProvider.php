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
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\StalenessHandler\ImmediateStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    private static ?bool $isReachable = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');

        if (! $this->isOpenTelemetryServerReachable()) {
            return;
        }

        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        $this->registerTracerProvider($endpoint, $protocol);
        $this->registerMeterProvider($endpoint, $protocol);
    }

    private function registerTracerProvider(string $endpoint, string $protocol): void
    {
        $this->app->singleton(TracerProvider::class, function () use ($endpoint, $protocol) {
            try {
                $transportFactory = $protocol === 'grpc'
                    ? new GrpcTransportFactory()
                    : new OtlpHttpTransportFactory();

                $transport = $transportFactory->create(
                    $endpoint . ($protocol === 'grpc'
                        ? '/opentelemetry.proto.collector.trace.v1.TraceService/Export'
                        : '/v1/traces'),
                    $protocol === 'grpc' ? 'application/x-protobuf' : 'application/json'
                );

                $spanExporter = new SpanExporter($transport);

                return new TracerProvider(
                    new SimpleSpanProcessor($spanExporter),
                    new AlwaysOnSampler(),
                    ResourceInfoFactory::defaultResource()
                );
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry TracerProvider setup failed', ['error' => $e->getMessage()]);
                return new \OpenTelemetry\SDK\Trace\NoopTracerProvider();
            }
        });

        $this->app->singleton('tracer', fn(Application $app) =>
            $app->make(TracerProvider::class)->getTracer('otel-tracer')
        );

        $this->app->alias('tracer', TracerInterface::class);
    }

    private function registerMeterProvider(string $endpoint, string $protocol): void
    {
        $this->app->singleton(MeterProvider::class, function () use ($endpoint, $protocol) {
            try {
                $transportFactory = $protocol === 'grpc'
                    ? new GrpcTransportFactory()
                    : new OtlpHttpTransportFactory();

                $transport = $transportFactory->create(
                    $endpoint . ($protocol === 'grpc'
                        ? '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export'
                        : '/v1/metrics'),
                    $protocol === 'grpc' ? 'application/x-protobuf' : 'application/json'
                );

                $metricExporter = new MetricExporter($transport);
                $attributesFactory = new \OpenTelemetry\SDK\Common\Attribute\AttributesFactory();

                $resource = ResourceInfo::create(Attributes::create([
                    'service.name' => config('opentelemetry.service_name'),
                    'deployment.environment' => config('app.env'),
                ]));

                return new MeterProvider(
                    null,
                    $resource,
                    new SystemClock(),
                    $attributesFactory,
                    new InstrumentationScopeFactory($attributesFactory),
                    [new ExportingReader($metricExporter)],
                    new CriteriaViewRegistry(),
                    null,
                    new ImmediateStalenessHandlerFactory()
                );
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry MeterProvider setup failed', ['error' => $e->getMessage()]);
                return new \OpenTelemetry\SDK\Metrics\NoopMeterProvider();
            }
        });

        $this->app->singleton('metrics', fn(Application $app) =>
            $app->make(MeterProvider::class)->getMeter('otel-metrics')
        );

        $this->app->alias('metrics', MeterInterface::class);
    }

    private function isOpenTelemetryServerReachable(): bool
    {
        if (self::$isReachable !== null) {
            return self::$isReachable;
        }

        $endpoint = config('opentelemetry.endpoint');
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            Log::error('Invalid OTEL endpoint', ['endpoint' => $endpoint]);
            return self::$isReachable = false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT)
            ?? (str_starts_with($endpoint, 'http') ? 4318 : 4317);

        $connection = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 0.05, STREAM_CLIENT_CONNECT);
        self::$isReachable = is_resource($connection);
        if (self::$isReachable) {
            fclose($connection);
        } else {
            Log::warning("OTLP endpoint unreachable: {$host}:{$port}", compact('errstr'));
        }

        return self::$isReachable;
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], 'opentelemetry-config');
    }
}
