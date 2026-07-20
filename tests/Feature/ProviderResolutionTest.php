<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Laratel\Opentelemetry\Logger\OtelLogger;
use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class ProviderResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        // Skip the reachability probe so bindings register without a collector.
        // Must be an env var: the provider registers before defineEnvironment()
        // config overrides apply on Testbench 10.
        $_ENV['OTEL_ENABLE_REACHABILITY_CHECK'] = $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK'] = 'false';
        putenv('OTEL_ENABLE_REACHABILITY_CHECK=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['OTEL_ENABLE_REACHABILITY_CHECK'], $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK']);
        putenv('OTEL_ENABLE_REACHABILITY_CHECK');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('opentelemetry.service_name', 'my-test-service');
    }

    public function test_tracer_and_meter_resolve(): void
    {
        $this->assertInstanceOf(TracerProvider::class, $this->app->make(TracerProvider::class));
        $this->assertInstanceOf(TracerInterface::class, $this->app->make('tracer'));
        $this->assertInstanceOf(MeterProvider::class, $this->app->make(MeterProvider::class));
        $this->assertInstanceOf(MeterInterface::class, $this->app->make('metrics'));
    }

    public function test_tracer_provider_uses_batch_processor_and_service_name(): void
    {
        $provider = $this->app->make(TracerProvider::class);

        $reflection = new \ReflectionObject($provider);

        // The provider holds shared state with the span processor and resource.
        $stateProp = $reflection->getProperty('tracerSharedState');
        $state = $stateProp->getValue($provider);

        $stateReflection = new \ReflectionObject($state);

        $processor = $stateReflection->getProperty('spanProcessor')->getValue($state);
        $this->assertInstanceOf(BatchSpanProcessor::class, $processor);

        $resource = $stateReflection->getProperty('resource')->getValue($state);
        $this->assertSame(
            'my-test-service',
            $resource->getAttributes()->get('service.name')
        );
    }

    public function test_logger_provider_resource_carries_service_name(): void
    {
        // The reachability check is disabled in this class, so the factory
        // builds a real LoggerProvider (its exporter never connects — lazy).
        $logger = (new OtelLoggerFactory)([]);
        $this->assertInstanceOf(OtelLogger::class, $logger);

        $providerProp = new \ReflectionProperty(OtelLoggerFactory::class, 'loggerProvider');
        $provider = $providerProp->getValue();

        $stateProp = (new \ReflectionObject($provider))->getProperty('loggerSharedState');
        $state = $stateProp->getValue($provider);
        $resource = (new \ReflectionObject($state))->getProperty('resource')->getValue($state);

        $this->assertSame(
            'my-test-service',
            $resource->getAttributes()->get('service.name')
        );
    }
}
