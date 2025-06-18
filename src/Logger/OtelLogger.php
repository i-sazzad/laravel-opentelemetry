<?php

namespace Laratel\Opentelemetry\Logger;

use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

readonly class OtelLogger implements LoggerInterface
{
    public function __construct(private LoggerProviderInterface $loggerProvider)
    {
    }

    public function log($level, $message, array $context = []): void
    {
        // Extract exception for special handling, if present
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $logRecord = (new LogRecord($message))
            ->setTimestamp((int)(microtime(true) * 1_000_000_000))
            ->setObservedTimestamp((int)(microtime(true) * 1_000_000_000))
            ->setSeverityNumber($this->mapSeverityNumber($level))
            ->setSeverityText(strtoupper($level))
            ->setAttributes($context)
            ->setContext(Context::getCurrent());

        // Attach exception details if they exist
        if ($exception) {
            $logRecord->setAttribute('exception.type', get_class($exception));
            $logRecord->setAttribute('exception.message', $exception->getMessage());
            $logRecord->setAttribute('exception.stacktrace', $exception->getTraceAsString());
        }

        $this->loggerProvider->getLogger('otel-logger', '1.0.0')->emit($logRecord);
    }

    private function mapSeverityNumber(string $level): int
    {
        // Mapping PSR-3 log levels to OpenTelemetry severity numbers.
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
}
