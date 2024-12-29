<?php

namespace Laratel\Opentelemetry\Logger;

use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\API\Common\Time\SystemClock;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporter;

class OtelLoggerFactory
{
    public function __invoke(array $config): OtelLogger
    {
        $endpoint = config('opentelemetry.endpoint') . '/opentelemetry.proto.collector.logs.v1.LogsService/Export';

        $transport = (new GrpcTransportFactory())->create($endpoint, 'application/x-protobuf');
        $logExporter = new LogsExporter($transport);
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
