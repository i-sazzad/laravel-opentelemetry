<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware;
use Laratel\Opentelemetry\Services\TraceService;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpFoundation\Response;

class TraceMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;

    private function makeMiddleware(): OpenTelemetryTraceMiddleware
    {
        $this->exporter = new InMemoryExporter;
        $tracer = (new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test');

        return new OpenTelemetryTraceMiddleware(new Helper, new TraceService($tracer));
    }

    public function test_span_continues_incoming_traceparent(): void
    {
        $middleware = $this->makeMiddleware();

        $request = Request::create('/api/users', 'GET');
        $request->headers->set(
            'traceparent',
            '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'
        );

        $middleware->handle($request, fn () => new Response('ok'));

        $spans = $this->exporter->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('0af7651916cd43dd8448eb211c80319c', $spans[0]->getContext()->getTraceId());
        $this->assertSame('b7ad6b7169203331', $spans[0]->getParentSpanId());
    }

    public function test_span_has_semconv_attributes_and_trace_headers(): void
    {
        $middleware = $this->makeMiddleware();

        $request = Request::create('https://example.test/api/users?page=2', 'GET');
        $response = $middleware->handle($request, fn () => new Response('hello', 200));

        $spans = $this->exporter->getSpans();
        $attributes = $spans[0]->getAttributes();

        $this->assertSame('GET', $attributes->get('http.request.method'));
        $this->assertSame(200, $attributes->get('http.response.status_code'));
        $this->assertSame('https://example.test/api/users?page=2', $attributes->get('url.full'));
        // No routing ran in this unit test, so the route template is unknown.
        $this->assertSame('unknown', $attributes->get('http.route'));
        $this->assertNotEmpty($response->headers->get('trace-id'));
        $this->assertNotEmpty($response->headers->get('span-id'));
    }

    public function test_error_status_marks_span(): void
    {
        $middleware = $this->makeMiddleware();

        $middleware->handle(
            Request::create('/api/fail', 'GET'),
            fn () => new Response('nope', 500)
        );

        $this->assertSame('Error', $this->exporter->getSpans()[0]->getStatus()->getCode());
    }

    public function test_excluded_route_creates_no_span(): void
    {
        config()->set('opentelemetry.excluded_routes', ['healthz']);
        $middleware = $this->makeMiddleware();

        $middleware->handle(Request::create('/healthz', 'GET'), fn () => new Response('ok'));

        $this->assertCount(0, $this->exporter->getSpans());
    }
}
