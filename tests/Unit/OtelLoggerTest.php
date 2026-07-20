<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Logger\OtelLogger;
use Laratel\Opentelemetry\Tests\TestCase;
use Psr\Log\LogLevel;

class OtelLoggerTest extends TestCase
{
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
}
