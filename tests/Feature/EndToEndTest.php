<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Drives real requests through the HTTP kernel with in-memory exporters
 * swapped in for the OTLP ones.
 */
class EndToEndTest extends TestCase
{
    private InMemoryExporter $spans;

    private InMemoryMetricExporter $metricExporter;

    protected function setUp(): void
    {
        $_ENV['OTEL_ENABLE_REACHABILITY_CHECK'] = $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK'] = 'false';
        putenv('OTEL_ENABLE_REACHABILITY_CHECK=false');

        parent::setUp();

        $this->spans = new InMemoryExporter;
        $this->metricExporter = new InMemoryMetricExporter;

        // Same wiring as the service provider (batched spans, exporting
        // reader), minus the network.
        $this->app->instance(TracerProvider::class, TracerProvider::builder()
            ->addSpanProcessor(BatchSpanProcessor::builder($this->spans)->build())
            ->build());
        $this->app->instance(MeterProvider::class, MeterProvider::builder()
            ->addReader(new ExportingReader($this->metricExporter))
            ->build());

        config()->set('opentelemetry.system_metrics_enabled', false);

        Route::middleware(['opentelemetry.metrics', 'opentelemetry.trace'])->group(function () {
            Route::get('users/{id}', function (string $id) {
                Event::dispatch(new QueryExecuted('select * from users where id = ?', [$id], 3.0, $this->connection()));

                return "user {$id}";
            })->name('users.show');

            Route::get('boom', fn () => throw new \RuntimeException('kaboom'));
        });
    }

    protected function tearDown(): void
    {
        unset($_ENV['OTEL_ENABLE_REACHABILITY_CHECK'], $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK']);
        putenv('OTEL_ENABLE_REACHABILITY_CHECK');

        parent::tearDown();
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getName')->willReturn('testing');
        $connection->method('getDriverName')->willReturn('sqlite');
        $connection->method('getDatabaseName')->willReturn(':memory:');

        return $connection;
    }

    /** @return array<string, Metric> */
    private function exportedMetrics(): array
    {
        $byName = [];
        foreach ($this->metricExporter->collect() as $metric) {
            $byName[$metric->name] = $metric;
        }

        return $byName;
    }

    public function test_request_and_query_spans_are_exported_when_the_app_terminates(): void
    {
        // $this->get() runs the kernel's terminate step, which is where the
        // batched spans must be flushed (they used to be lost entirely).
        $this->get('users/42')->assertOk()->assertHeader('trace-id');

        $spans = collect($this->spans->getSpans())->keyBy(fn ($span) => $span->getName());

        $this->assertSame(['select', 'GET /users/{id}'], $spans->keys()->all());

        $request = $spans['GET /users/{id}'];
        $this->assertSame(SpanKind::KIND_SERVER, $request->getKind());
        $this->assertSame('users/{id}', $request->getAttributes()->get('http.route'));
        $this->assertSame('users.show', $request->getAttributes()->get('laravel.route.name'));

        $query = $spans['select'];
        $this->assertSame(SpanKind::KIND_CLIENT, $query->getKind());
        $this->assertSame($request->getSpanId(), $query->getParentSpanId());
        $this->assertSame('sqlite', $query->getAttributes()->get('db.system'));
    }

    public function test_metrics_are_exported_when_the_app_terminates(): void
    {
        $this->get('users/42')->assertOk();

        $metrics = $this->exportedMetrics();

        $this->assertArrayHasKey('laratel_http_request_total', $metrics);
        $this->assertArrayHasKey('laratel_db_query_total', $metrics);

        $point = $metrics['laratel_http_request_total']->data->dataPoints[0];
        $this->assertSame(1, $point->value);
        $this->assertSame('users/{id}', $point->attributes->get('http.route'));
    }

    public function test_handled_exceptions_are_recorded_on_span_and_error_metric(): void
    {
        // Laravel turns the exception into a 500 response before it reaches
        // the middleware, so a try/catch alone never sees it.
        $this->get('boom')->assertStatus(500);

        $span = $this->spans->getSpans()[0];
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
        $this->assertSame(\RuntimeException::class, $span->getEvents()[0]->getAttributes()->get('exception.type'));

        $error = $this->exportedMetrics()['laratel_error_total']->data->dataPoints[0];
        $this->assertSame(\RuntimeException::class, $error->attributes->get('exception.type'));
    }

    public function test_listeners_are_registered_once_across_requests(): void
    {
        $this->get('users/1')->assertOk();
        $this->get('users/2')->assertOk();

        // 2 requests => 2 server spans + 2 query spans (not 3 query spans).
        $this->assertCount(4, $this->spans->getSpans());
    }

    public function test_queries_outside_a_traced_request_create_no_orphan_spans(): void
    {
        $this->get('users/1')->assertOk();
        $before = count($this->spans->getSpans());

        Event::dispatch(new QueryExecuted('select 1', [], 1.0, $this->connection()));
        $this->app->terminate();

        $this->assertCount($before, $this->spans->getSpans());
    }
}
