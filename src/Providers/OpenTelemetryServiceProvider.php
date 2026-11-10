<?php

namespace Laratel\Opentelemetry\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware;
use Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Services\TraceService;
use Laratel\Opentelemetry\Support\OtelResource;
use Laratel\Opentelemetry\Support\OtlpTransport;
use Laratel\Opentelemetry\Support\Reachability;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opentelemetry.php', 'opentelemetry');

        $this->app->singleton(MetricsService::class);
        $this->app->singleton(TraceService::class);

        if (! config('opentelemetry.enabled', true) || ! Reachability::check()) {
            return;
        }

        $this->registerTracerProvider();
        $this->registerMeterProvider();
    }

    private function registerTracerProvider(): void
    {
        $this->app->singleton(TracerProvider::class, function () {
            try {
                $provider = TracerProvider::builder()
                    ->addSpanProcessor(
                        BatchSpanProcessor::builder(new SpanExporter(OtlpTransport::create(OtlpTransport::TRACES)))->build()
                    )
                    ->setResource(OtelResource::build())
                    ->setSampler(new AlwaysOnSampler)
                    ->build();

                // Last-resort export for processes that exit without
                // terminating the application (fatal errors, exit()).
                ShutdownHandler::register($provider->shutdown(...));

                return $provider;
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry TracerProvider setup failed', ['error' => $e->getMessage()]);

                return new NoopTracerProvider;
            }
        });

        $this->app->singleton('tracer', fn (Application $app) => $app->make(TracerProvider::class)->getTracer('otel-tracer'));

        $this->app->alias('tracer', TracerInterface::class);
    }

    private function registerMeterProvider(): void
    {
        $this->app->singleton(MeterProvider::class, function () {
            try {
                $temporality = strtolower((string) config('opentelemetry.metrics_temporality', 'delta')) === 'cumulative'
                    ? Temporality::CUMULATIVE
                    : Temporality::DELTA;

                $exporter = new MetricExporter(OtlpTransport::create(OtlpTransport::METRICS), $temporality);

                return MeterProvider::builder()
                    ->setResource(OtelResource::build())
                    ->addReader(new ExportingReader($exporter))
                    ->build();
            } catch (\Throwable $e) {
                Log::error('OpenTelemetry MeterProvider setup failed', ['error' => $e->getMessage()]);

                return new NoopMeterProvider;
            }
        });

        $this->app->singleton('metrics', fn (Application $app) => $app->make(MeterProvider::class)->getMeter('otel-metrics'));

        $this->app->alias('metrics', MeterInterface::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/opentelemetry.php' => config_path('opentelemetry.php'),
        ], 'opentelemetry-config');

        $router = $this->app['router'];
        $router->aliasMiddleware('opentelemetry.trace', OpenTelemetryTraceMiddleware::class);
        $router->aliasMiddleware('opentelemetry.metrics', OpenTelemetryMetricsMiddleware::class);

        // Export after the response has been sent, so telemetry never adds
        // latency to the request itself. The application is injected (not
        // captured) so the callback sees the current container under Octane.
        $this->app->terminating(static function (Application $app): void {
            self::flush($app);
        });
    }

    /**
     * Export everything buffered by the providers that were actually used.
     */
    public static function flush(Application $app): void
    {
        try {
            if ($app->resolved(TracerProvider::class)) {
                $tracerProvider = $app->make(TracerProvider::class);
                if ($tracerProvider instanceof TracerProvider) {
                    $tracerProvider->forceFlush();
                }
            }

            if ($app->resolved(MeterProvider::class)) {
                $app->make(Helper::class)->flushMetrics();
            }

            OtelLoggerFactory::flush();
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::warning('[OTEL] Flush failed: '.$e->getMessage());
            }
        }
    }
}
