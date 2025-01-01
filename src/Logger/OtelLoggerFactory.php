<?php

namespace Laratel\Opentelemetry\Logger;

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
    public function __invoke(array $config): OtelLogger
    {
        $endpoint = config('opentelemetry.endpoint');
        $protocol = config('opentelemetry.protocol');

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid OTEL_EXPORTER_OTLP_ENDPOINT URL provided.');
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

        $logProcessor = new BatchLogRecordProcessor($logExporter, new SystemClock(), 2048, 1000000000, 512);
        $attributesFactory = new AttributesFactory();
        $instrumentationScopeFactory = new InstrumentationScopeFactory($attributesFactory);
        $resource = ResourceInfoFactory::defaultResource();

        $loggerProvider = new LoggerProvider($logProcessor, $instrumentationScopeFactory, $resource);

        register_shutdown_function(function () use ($logProcessor) {
            $logProcessor->shutdown();
        });

        return new OtelLogger($loggerProvider);
    }
}
