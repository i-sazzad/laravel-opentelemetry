<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Logger\OtelLogger;
use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Tests\TestCase;
use Monolog\Logger;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use Psr\Log\LogLevel;

class OtelLoggerTest extends TestCase
{
    /** @return array{OtelLogger, \ArrayObject} */
    private function makeLogger(string $level = LogLevel::DEBUG): array
    {
        $storage = new \ArrayObject;
        $provider = new LoggerProvider(
            new SimpleLogRecordProcessor(new InMemoryExporter($storage)),
            new InstrumentationScopeFactory(Attributes::factory())
        );

        return [new OtelLogger($provider, $level), $storage];
    }

    public function test_severity_numbers_follow_otel_spec(): void
    {
        $this->assertSame(5, OtelLogger::mapSeverityNumber(LogLevel::DEBUG));
        $this->assertSame(9, OtelLogger::mapSeverityNumber(LogLevel::INFO));
        $this->assertSame(10, OtelLogger::mapSeverityNumber(LogLevel::NOTICE));
        $this->assertSame(13, OtelLogger::mapSeverityNumber(LogLevel::WARNING));
        $this->assertSame(17, OtelLogger::mapSeverityNumber(LogLevel::ERROR));
        $this->assertSame(18, OtelLogger::mapSeverityNumber(LogLevel::CRITICAL));
        $this->assertSame(21, OtelLogger::mapSeverityNumber(LogLevel::ALERT));
        $this->assertSame(22, OtelLogger::mapSeverityNumber(LogLevel::EMERGENCY));
        $this->assertSame(9, OtelLogger::mapSeverityNumber('unknown-level'));
    }

    public function test_records_below_the_channel_level_are_dropped(): void
    {
        [$logger, $storage] = $this->makeLogger(LogLevel::WARNING);

        $logger->debug('noise');
        $logger->info('noise');
        $logger->warning('kept');
        $logger->error('kept');

        $this->assertCount(2, $storage);
        $this->assertSame('WARNING', $storage[0]->getSeverityText());
    }

    public function test_context_is_exported_with_non_scalar_values_normalized(): void
    {
        [$logger, $storage] = $this->makeLogger();

        $logger->error('boom', [
            'user' => 7,
            'when' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            'model' => new \stdClass,
            'nested' => ['ok' => true, 'object' => new \ArrayObject],
            'exception' => new \RuntimeException('bad'),
        ]);

        $attributes = $storage[0]->getAttributes()->toArray();

        $this->assertSame('boom', $storage[0]->getBody());
        $this->assertSame(7, $attributes['user']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $attributes['when']);
        $this->assertSame(\stdClass::class, $attributes['model']);
        $this->assertSame(['ok' => true, 'object' => \ArrayObject::class], $attributes['nested']);
        $this->assertSame(\RuntimeException::class, $attributes['exception.type']);
        $this->assertSame('bad', $attributes['exception.message']);
        $this->assertArrayNotHasKey('exception', $attributes);
    }

    public function test_factory_returns_a_null_logger_when_collector_is_unreachable(): void
    {
        $this->assertInstanceOf(Logger::class, (new OtelLoggerFactory)([]));
    }

    public function test_factory_returns_a_null_logger_when_disabled(): void
    {
        config()->set('opentelemetry.enable_reachability_check', false);
        config()->set('opentelemetry.enabled', false);

        $this->assertInstanceOf(Logger::class, (new OtelLoggerFactory)([]));
    }

    public function test_default_log_channel_can_be_the_otel_channel_without_recursing(): void
    {
        // The unreachable-collector warning is itself logged; when the
        // default channel is the otel channel that re-enters the factory.
        config()->set('logging.default', 'otel');
        config()->set('logging.channels.otel', ['driver' => 'custom', 'via' => OtelLoggerFactory::class]);

        $this->app['log']->info('does not blow the stack');

        $this->addToAssertionCount(1);
    }
}
