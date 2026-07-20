<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use Symfony\Component\HttpFoundation\Response;

class MetricsServiceTest extends TestCase
{
    /** Build a MetricsService around a fully mocked meter. */
    protected function makeService(): MetricsService
    {
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createCounter')
            ->willReturnCallback(fn () => $this->createMock(CounterInterface::class));
        $meter->method('createHistogram')
            ->willReturnCallback(fn () => $this->createMock(HistogramInterface::class));
        $meter->method('createUpDownCounter')
            ->willReturnCallback(fn () => $this->createMock(UpDownCounterInterface::class));

        return new MetricsService($meter);
    }

    public function test_service_is_a_container_singleton(): void
    {
        $this->assertSame(
            $this->app->make(MetricsService::class),
            $this->app->make(MetricsService::class)
        );
    }

    public function test_service_without_meter_has_no_instruments(): void
    {
        $service = new MetricsService(null);

        $this->assertSame([], $service->metrics);
    }

    public function test_in_progress_instrument_is_an_updowncounter(): void
    {
        $service = $this->makeService();

        $this->assertInstanceOf(
            UpDownCounterInterface::class,
            $service->metrics['http_requests_in_progress']
        );
    }

    public function test_meaningless_instruments_are_gone(): void
    {
        $service = $this->makeService();

        $this->assertArrayNotHasKey('application_uptime_seconds', $service->metrics);
        $this->assertArrayNotHasKey('network_inbound_bytes', $service->metrics);
        $this->assertArrayNotHasKey('network_outbound_bytes', $service->metrics);
        $this->assertArrayNotHasKey('db_error_total', $service->metrics);
    }

    public function test_http_labels_are_low_cardinality(): void
    {
        $service = $this->makeService();

        $counter = $this->createMock(CounterInterface::class);
        $counter->expects($this->once())->method('add')->with(1, $this->callback(
            fn (array $labels) => $labels === [
                'http.request.method' => 'GET',
                'http.route' => 'unknown',
                'http.response.status_code' => 200,
            ]
        ));
        $service->metrics['http_request_total'] = $counter;

        // Path contains an ID; it must NOT appear in any label (route is unresolved => 'unknown').
        $request = Request::create('/api/users/42', 'GET');
        $service->recordHttpMetrics($request, new Response('ok', 200), microtime(true));
    }

    public function test_failed_requests_get_error_type_label(): void
    {
        $service = $this->makeService();

        $counter = $this->createMock(CounterInterface::class);
        $counter->expects($this->once())->method('add')->with(1, $this->callback(
            fn (array $labels) => $labels['error.type'] === 'server_error'
                && $labels['http.response.status_code'] === 503
        ));
        $service->metrics['http_failed_requests_total'] = $counter;

        $request = Request::create('/api/users', 'GET');
        $service->recordHttpMetrics($request, new Response('down', 503), microtime(true));
    }

    public function test_middleware_passes_through_without_meter(): void
    {
        $middleware = new OpenTelemetryMetricsMiddleware(new Helper, new MetricsService(null));

        $response = $middleware->handle(
            Request::create('/anything', 'GET'),
            fn () => new Response('ok')
        );

        $this->assertSame('ok', $response->getContent());
    }
}
