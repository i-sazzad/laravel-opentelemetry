<?php

namespace Laratel\Opentelemetry\Logger;

use Exception;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\API\Common\Time\SystemClock;

class OtelLoggerFactory
{
    /** The singleton LoggerProvider instance. */
    private static ?LoggerProvider $loggerProvider = null;

    /** Cache for the reachability check result. */
    private static ?bool $isReachable = null;

    /** Flag to ensure the shutdown function is only registered once. */
    private static bool $shutdownFunctionRegistered = false;

    /**
     * Create a custom Monolog instance.
     *
     * @param array $config
     * @return Logger|OtelLogger
     * @throws Exception
     */
    public function __invoke(array $config): Logger|OtelLogger
    {
        // If the server is not reachable, return a logger that does nothing.
        if (! $this->isServerReachable()) {
            return new Logger('otel_fallback', [new NullHandler()]);
        }

        // If we've already created the provider, reuse it.
        if (self::$loggerProvider !== null) {
            return new OtelLogger(self::$loggerProvider);
        }

        // --- This part runs only once ---

        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        $logExporter = match ($protocol) {
            'grpc' => new LogsExporter(
                (new GrpcTransportFactory())->create(
                    $endpoint . '/opentelemetry.proto.collector.logs.v1.LogsService/Export',
                    'application/x-protobuf'
                )
            ),
            'http' => new LogsExporter(
                (new OtlpHttpTransportFactory())->create(
                    $endpoint . '/v1/logs',
                    'application/json'
                )
            ),
            default => throw new \InvalidArgumentException("Unsupported OTLP protocol: $protocol"),
        };

        $logProcessor = new BatchLogRecordProcessor($logExporter, new SystemClock());
        $attributesFactory = new AttributesFactory();

        // Create and cache the LoggerProvider instance.
        self::$loggerProvider = new LoggerProvider(
            $logProcessor,
            new InstrumentationScopeFactory($attributesFactory),
            ResourceInfoFactory::defaultResource()
        );

        // Register a shutdown function to ensure buffered logs are sent.
        if (! self::$shutdownFunctionRegistered) {
            register_shutdown_function([$logProcessor, 'shutdown']);
            self::$shutdownFunctionRegistered = true;
        }

        return new OtelLogger(self::$loggerProvider);
    }

    /**
     * Check if the OpenTelemetry server is reachable.
     * Caches the result in a static variable to avoid repeated checks.
     */
    private function isServerReachable(): bool
    {
        if (self::$isReachable !== null) {
            return self::$isReachable;
        }

        $endpoint = config('opentelemetry.endpoint');
        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            Log::error('Invalid OTEL_EXPORTER_OTLP_ENDPOINT provided.', ['endpoint' => $endpoint]);
            return self::$isReachable = false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        if (!$port) {
            $scheme = parse_url($endpoint, PHP_URL_SCHEME);
            $port = ($scheme === 'https' || $scheme === 'http') ? 4318 : 4317;
        }

        $connection = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);
            self::$isReachable = true;
        } else {
            Log::warning("OTLP endpoint is not reachable. OpenTelemetry logging will be disabled.", [
                'endpoint' => "{$host}:{$port}",
                'error' => trim($errstr ?? ''),
            ]);
            self::$isReachable = false;
        }

        return self::$isReachable;
    }
}
