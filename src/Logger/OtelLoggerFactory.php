<?php

namespace Laratel\Opentelemetry\Logger;

use Laratel\Opentelemetry\Support\OtelResource;
use Laratel\Opentelemetry\Support\OtlpTransport;
use Laratel\Opentelemetry\Support\Reachability;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use Psr\Log\LogLevel;

class OtelLoggerFactory
{
    /** The LoggerProvider shared by every otel channel in this process. */
    private static ?LoggerProvider $loggerProvider = null;

    /** Flag to ensure the shutdown function is only registered once. */
    private static bool $shutdownFunctionRegistered = false;

    /**
     * Create the logger for a `custom` log channel.
     *
     * Falls back to a logger that discards everything when telemetry is
     * disabled, the collector is unreachable, or the exporter cannot be
     * built — a logging channel must never break the application.
     */
    public function __invoke(array $config): Logger|OtelLogger
    {
        if (! config('opentelemetry.enabled', true) || ! Reachability::check()) {
            return self::nullLogger();
        }

        try {
            self::$loggerProvider ??= self::buildProvider();
        } catch (\Throwable $e) {
            error_log('[OTEL] LoggerProvider setup failed: '.$e->getMessage());

            return self::nullLogger();
        }

        return new OtelLogger(self::$loggerProvider, (string) ($config['level'] ?? LogLevel::DEBUG));
    }

    /**
     * Export any buffered log records now. Called when the application
     * terminates so long-running workers ship logs after every request.
     */
    public static function flush(): bool
    {
        try {
            return self::$loggerProvider?->forceFlush() ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function buildProvider(): LoggerProvider
    {
        $provider = new LoggerProvider(
            new BatchLogRecordProcessor(
                new LogsExporter(OtlpTransport::create(OtlpTransport::LOGS)),
                Clock::getDefault()
            ),
            new InstrumentationScopeFactory(Attributes::factory()),
            OtelResource::build()
        );

        // Last-resort flush for processes that exit without terminating
        // the application (fatal errors, exit()).
        if (! self::$shutdownFunctionRegistered) {
            register_shutdown_function(static function (): void {
                try {
                    self::$loggerProvider?->shutdown();
                } catch (\Throwable) {
                    // Nothing useful can be done at shutdown.
                }
            });
            self::$shutdownFunctionRegistered = true;
        }

        return $provider;
    }

    private static function nullLogger(): Logger
    {
        return new Logger('otel_fallback', [new NullHandler]);
    }
}
