<?php

namespace Laratel\Opentelemetry\Logger;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use Illuminate\Support\Facades\Log;

class OtelLoggerFactory
{
    /**
     * @throws Exception
     */
    public function __invoke(array $config): OtelLogger
    {
        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !$this->isOpenTelemetryServerReachable()) {
            Log::error('Invalid or unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
            throw new \InvalidArgumentException('Invalid or unreachable OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
        }

        try {
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
                    Log::error('Error during OpenTelemetry logger shutdown: ' . $e->getMessage());
                }
            });

            Log::info('OpenTelemetry Logger successfully created.');

            return new OtelLogger($loggerProvider);

        } catch (Exception $e) {
            Log::error('Failed to create OpenTelemetry logger: ' . $e->getMessage());
            throw $e;  // Re-throw exception to let the application handle it
        }
    }

    private function isOpenTelemetryServerReachable(): bool
    {
        $client = new Client();
        try {
            // Attempt to send a simple request (e.g., a GET request) to the OpenTelemetry endpoint
            $response = $client->get(config('opentelemetry.endpoint') . '/health', [
                'timeout' => 1 // Set a short timeout for the request
            ]);
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            // Log the error but do not interrupt the request flow
            Log::warning('Failed to connect to OpenTelemetry server: ' . $e->getMessage());
            return false;  // Return false to skip tracing
        }
    }
}
