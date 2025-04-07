<?php

namespace Laratel\Opentelemetry\Logger;

use Exception;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporter;

class OtelLoggerFactory
{
    /**
     * @throws Exception
     */
    public function __invoke(array $config)
    {
        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        if (!$this->isOpenTelemetryServerReachable()) {
            return false;
        }

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
            default => throw new \InvalidArgumentException("Unsupported protocol: $protocol"),
        };

        // Create a log processor with configured batch settings
        $logProcessor = new BatchLogRecordProcessor($logExporter, new SystemClock(), 2048, 1000000000, 512);

        // Prepare the instrumentation and resource info
        $attributesFactory = new AttributesFactory();
        $instrumentationScopeFactory = new InstrumentationScopeFactory($attributesFactory);
        $resource = ResourceInfoFactory::defaultResource();

        // Create the Logger Provider
        $loggerProvider = new LoggerProvider($logProcessor, $instrumentationScopeFactory, $resource);

        // Register a shutdown function to ensure the logs are exported on shutdown
        register_shutdown_function(function () use ($logProcessor) {
            try {
                $logProcessor->shutdown();
            } catch (\Throwable $e) {
                throw new \Exception('Error during OpenTelemetry logger shutdown: ' . $e->getMessage());
            }
        });

        return new OtelLogger($loggerProvider);
    }

    private function isOpenTelemetryServerReachable(): bool
    {
        $endpoint = config('opentelemetry.endpoint');

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            Log::error('Invalid OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT) ?? 4318;

        $connection = @fsockopen($host, $port, $errno, $err_str, 2);

        if ($connection) {
            fclose($connection);
            return true;
        } else {
            Log::error('Unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            return false;
        }
    }
}
