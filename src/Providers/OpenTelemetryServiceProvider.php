<?php

namespace Laratel\Opentelemetry\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware;
use Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Services\TraceService;
use Laratel\Opentelemetry\Support\OtelResource;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    private static ?bool $isReachable = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opentelemetry.php', 'opentelemetry');

        $this->app->singleton(MetricsService::class);
        $this->app->singleton(TraceService::class);

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
                    ? new GrpcTransportFactory
                    : new OtlpHttpTransportFactory;

                $transport = $transportFactory->create(
                    $endpoint.($protocol === 'grpc'
                        ? '/opentelemetry.proto.collector.trace.v1.TraceService/Export'
                        : '/v1/traces'),
                    $protocol === 'grpc' ? 'application/x-protobuf' : 'application/json'
                );

                $spanExporter = new SpanExporter($transport);

                return TracerProvider::builder()
                    ->addSpanProcessor(BatchSpanProcessor::builder($spanExporter)->build())
                    ->setResource(OtelResource::build())
                    ->setSampler(new AlwaysOnSampler)
                    ->build();
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry TracerProvider setup failed', ['error' => $e->getMessage()]);

                return new NoopTracerProvider;
            }
        });

        $this->app->singleton('tracer', fn (Application $app) => $app->make(TracerProvider::class)->getTracer('otel-tracer')
        );

        $this->app->alias('tracer', TracerInterface::class);
    }

    private function registerMeterProvider(string $endpoint, string $protocol): void
    {
        $this->app->singleton(MeterProvider::class, function () use ($endpoint, $protocol) {
            try {
                $transportFactory = $protocol === 'grpc'
                    ? new GrpcTransportFactory
                    : new OtlpHttpTransportFactory;

                $transport = $transportFactory->create(
                    $endpoint.($protocol === 'grpc'
                        ? '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export'
                        : '/v1/metrics'),
                    $protocol === 'grpc' ? 'application/x-protobuf' : 'application/json'
                );

                $metricExporter = new MetricExporter($transport);

                return MeterProvider::builder()
                    ->setResource(OtelResource::build())
                    ->addReader(new ExportingReader($metricExporter))
                    ->build();
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry MeterProvider setup failed', ['error' => $e->getMessage()]);

                return new NoopMeterProvider;
            }
        });

        $this->app->singleton('metrics', fn (Application $app) => $app->make(MeterProvider::class)->getMeter('otel-metrics')
        );

        $this->app->alias('metrics', MeterInterface::class);
    }

    private function isOpenTelemetryServerReachable(): bool
    {
        if (! config('opentelemetry.enable_reachability_check', true)) {
            return true;
        }

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
            ?? (config('opentelemetry.protocol') === 'grpc' ? 4317 : 4318);
        $timeout = (float) config('opentelemetry.reachability_timeout', 0.1);

        $connection = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
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
            __DIR__.'/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], 'opentelemetry-config');

        $router = $this->app['router'];
        $router->aliasMiddleware('opentelemetry.trace', OpenTelemetryTraceMiddleware::class);
        $router->aliasMiddleware('opentelemetry.metrics', OpenTelemetryMetricsMiddleware::class);
    }
}
