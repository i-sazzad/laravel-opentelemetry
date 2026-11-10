<?php

namespace Laratel\Opentelemetry\Support;

use InvalidArgumentException;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

/**
 * Builds the OTLP transport for one signal from the package config, so
 * traces, metrics, and logs resolve protocol, endpoint, and headers the
 * same way.
 */
final class OtlpTransport
{
    public const TRACES = 'traces';

    public const METRICS = 'metrics';

    public const LOGS = 'logs';

    private const GRPC_METHODS = [
        self::TRACES => '/opentelemetry.proto.collector.trace.v1.TraceService/Export',
        self::METRICS => '/opentelemetry.proto.collector.metrics.v1.MetricsService/Export',
        self::LOGS => '/opentelemetry.proto.collector.logs.v1.LogsService/Export',
    ];

    /**
     * @param  self::TRACES|self::METRICS|self::LOGS  $signal
     *
     * @throws InvalidArgumentException for an unsupported protocol
     */
    public static function create(string $signal): TransportInterface
    {
        // A trailing slash would otherwise produce "//v1/traces" (a 404).
        $endpoint = rtrim((string) config('opentelemetry.endpoint'), '/');
        $protocol = strtolower((string) config('opentelemetry.protocol', 'http'));
        $headers = self::headers();

        return match ($protocol) {
            'grpc' => (new GrpcTransportFactory)->create(
                $endpoint.self::GRPC_METHODS[$signal],
                'application/x-protobuf',
                $headers
            ),
            'http', 'http/json' => (new OtlpHttpTransportFactory)->create(
                "{$endpoint}/v1/{$signal}",
                'application/json',
                $headers
            ),
            'http/protobuf' => (new OtlpHttpTransportFactory)->create(
                "{$endpoint}/v1/{$signal}",
                'application/x-protobuf',
                $headers
            ),
            default => throw new InvalidArgumentException("Unsupported OTLP protocol: {$protocol}"),
        };
    }

    /**
     * Extra request headers (e.g. an Authorization token for a hosted
     * backend). Accepts an array, or the OTEL_EXPORTER_OTLP_HEADERS string
     * format: "key1=value1,key2=value2".
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        $configured = config('opentelemetry.headers');

        if (is_array($configured)) {
            return array_map('strval', $configured);
        }

        $headers = [];
        foreach (explode(',', (string) $configured) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $name = trim((string) $name);
            if ($name !== '' && $value !== null) {
                $headers[$name] = urldecode(trim($value));
            }
        }

        return $headers;
    }
}
