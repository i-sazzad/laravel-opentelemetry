<?php

return [

    // === OTLP Endpoint Configuration ===
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', env('OTEL_EXPORTER_OTLP_PROTOCOL', 'grpc') === 'grpc'
        ? 'http://localhost:4317'
        : 'http://localhost:4318'),
    'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http'),

    // === Exporters (Traces / Metrics / Logs) ===
    'traces_exporter'  => env('OTEL_TRACES_EXPORTER', 'otlp'),
    'logs_exporter'    => env('OTEL_LOGS_EXPORTER', 'otlp'),
    'metrics_exporter' => env('OTEL_METRICS_EXPORTER', 'otlp'),

    // === Propagation & Sampling ===
    'propagators' => env('OTEL_PROPAGATORS', 'baggage,tracecontext'),
    'traces_sampler' => env('OTEL_TRACES_SAMPLER', 'always_on'),

    // === Service Metadata ===
    'service_name'      => env('OTEL_SERVICE_NAME', 'laravel_app'),
    'service_namespace' => env('OTEL_SERVICE_NAMESPACE', 'laratel'),
    'service_version'   => env('OTEL_SERVICE_VERSION', '1.0.0'),
    'resource_attributes' => array_filter(
        explode(',', env('OTEL_RESOURCE_ATTRIBUTES', 'deployment.environment=production,service.namespace=default_namespace'))
    ),

    // === Optional Flags ===
    'enable_reachability_check' => env('OTEL_ENABLE_REACHABILITY_CHECK', true),

    // === Route Exclusions ===
    'excluded_routes' => [
        'assets/*',
        'uploads/*',
        'css/*',
        'js/*',
        'images/*',
        'fonts/*',
        'metrics',
        'favicon.ico',
        'api/health',
        'healthz',
        'status',
    ],

    // === Query Exclusions ===
    'excluded_queries' => [
        '/select\s+\*\s+from\s+`contact_setting`/i',
        '/update\s+`logs`/i',
    ],

    // === System Paths for Metrics ===
    'cpu_path'        => '/proc/stat',
    'memory_path'     => '/proc/meminfo',
    'disk_path'       => '/',
    'network_path'    => '/proc/net/dev',
    'connection_path' => '/proc/net/tcp',

    'network_states' => [
        'ESTABLISHED',
        'CLOSE_WAIT',
        'TIME_WAIT',
        'LISTEN',
        'SYN_SENT',
        'SYN_RECV',
    ],
];
