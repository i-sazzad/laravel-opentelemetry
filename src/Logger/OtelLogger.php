<?php

namespace Laratel\Opentelemetry\Logger;

use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

readonly class OtelLogger implements LoggerInterface
{
    /** PSR-3 levels, least to most severe. */
    private const LEVELS = [
        LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
        LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY,
    ];

    private int $minimumLevel;

    /**
     * @param  string  $minimumLevel  records below this PSR-3 level are dropped (the channel's "level")
     */
    public function __construct(
        private LoggerProviderInterface $loggerProvider,
        string $minimumLevel = LogLevel::DEBUG
    ) {
        $index = array_search(strtolower($minimumLevel), self::LEVELS, true);
        $this->minimumLevel = $index === false ? 0 : (int) $index;
    }

    public function log($level, $message, array $context = []): void
    {
        $level = strtolower((string) $level);

        $index = array_search($level, self::LEVELS, true);
        if ($index !== false && (int) $index < $this->minimumLevel) {
            return;
        }

        // Extract exception for special handling, if present
        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $now = (int) (microtime(true) * 1_000_000_000);

        $logRecord = (new LogRecord((string) $message))
            ->setTimestamp($now)
            ->setObservedTimestamp($now)
            ->setSeverityNumber(self::mapSeverityNumber($level))
            ->setSeverityText(strtoupper($level))
            ->setAttributes(self::normalizeContext($context))
            ->setContext(Context::getCurrent());

        // Attach exception details if they exist
        if ($exception) {
            $logRecord->setAttribute('exception.type', get_class($exception));
            $logRecord->setAttribute('exception.message', $exception->getMessage());
            $logRecord->setAttribute('exception.stacktrace', $exception->getTraceAsString());
        }

        try {
            $this->loggerProvider->getLogger('otel-logger', '1.0.0')->emit($logRecord);
        } catch (\Throwable) {
            // Logging must never take the application down with it.
        }
    }

    /**
     * OTLP attributes only carry scalars and arrays of them; anything else
     * (models, dates, enums, closures, resources) is reduced to a string so
     * the exporter does not choke on it.
     */
    public static function normalizeContext(array $context, int $depth = 0): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = match (true) {
                $value === null, is_scalar($value) => $value,
                is_array($value) => $depth >= 5 ? '[...]' : self::normalizeContext($value, $depth + 1),
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \Stringable => (string) $value,
                $value instanceof \JsonSerializable => json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: get_class($value),
                is_object($value) => get_class($value),
                default => get_debug_type($value),
            };
        }

        return $normalized;
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
