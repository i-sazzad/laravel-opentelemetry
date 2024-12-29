<?php

namespace Laratel\Opentelemetry\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Context\Context;

class OtelLogger implements LoggerInterface
{
    private LoggerProvider $loggerProvider;

    public function __construct(LoggerProvider $loggerProvider)
    {
        $this->loggerProvider = $loggerProvider;
    }

    public function log($level, $message, array $context = []): void
    {
        $logRecord = (new LogRecord($message))
            ->setTimestamp((int) (microtime(true) * 1_000_000_000))
            ->setObservedTimestamp((int) (microtime(true) * 1_000_000_000))
            ->setSeverityNumber($this->mapSeverityNumber($level))
            ->setSeverityText(strtoupper($level))
            ->setAttributes($context)
            ->setContext(Context::getCurrent());

        $this->loggerProvider->getLogger('otel_logger')->emit($logRecord);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    private function mapSeverityNumber(string $level): int
    {
        return match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT => 1, // FATAL
            LogLevel::CRITICAL => 3,  // ERROR
            LogLevel::ERROR => 4,     // ERROR
            LogLevel::WARNING => 5,   // WARN
            LogLevel::NOTICE => 6,    // INFO
            LogLevel::INFO => 7,      // INFO
            LogLevel::DEBUG => 8,     // DEBUG
            default => 8,             // Default to DEBUG
        };
    }
}
