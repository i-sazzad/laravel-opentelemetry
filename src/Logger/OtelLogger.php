<?php

namespace Laratel\Opentelemetry\Logger;

use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

readonly class OtelLogger implements LoggerInterface
{
    public function __construct(private LoggerProviderInterface $loggerProvider) {}

    public function log($level, $message, array $context = []): void
    {
        // Extract exception for special handling, if present
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $logRecord = (new LogRecord($message))
            ->setTimestamp((int) (microtime(true) * 1_000_000_000))
            ->setObservedTimestamp((int) (microtime(true) * 1_000_000_000))
            ->setSeverityNumber(self::mapSeverityNumber($level))
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

    /**
     * Map PSR-3 levels to OpenTelemetry severity numbers.
     * Spec: TRACE=1-4, DEBUG=5-8, INFO=9-12, WARN=13-16, ERROR=17-20, FATAL=21-24.
     */
    public static function mapSeverityNumber(string $level): int
    {
        return match ($level) {
            LogLevel::DEBUG => 5,
            LogLevel::INFO => 9,
            LogLevel::NOTICE => 10,
            LogLevel::WARNING => 13,
            LogLevel::ERROR => 17,
            LogLevel::CRITICAL => 18,
            LogLevel::ALERT => 21,
            LogLevel::EMERGENCY => 22,
            default => 9,
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
