<?php

return [
    // Endpoint configuration for OpenTelemetry
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4317'),

    // Additional configuration for tracing
    'traces_exporter' => env('OTEL_TRACES_EXPORTER', 'otlp'),
    'logs_exporter' => env('OTEL_LOGS_EXPORTER', 'otlp'),
    'metrics_exporter' => env('OTEL_METRICS_EXPORTER', 'otlp'),
    'propagators' => env('OTEL_PROPAGATORS', 'baggage,tracecontext'),
    'traces_sampler' => env('OTEL_TRACES_SAMPLER', 'always_on'),

    // OpenTelemetry service attributes
    'service_name' => env('OTEL_SERVICE_NAME', 'laravel_app'),
    'resource_attributes' => env('OTEL_RESOURCE_ATTRIBUTES', 'deployment.environment=production,service.namespace=default_namespace'),

    // Dynamic route exclusion for tracing and metrics
    'excluded_routes' => [
        'assets/*',
        'uploads/*',
        'css/*',
        'js/*',
        'images/*',
        'fonts/*',
        'metrics',
        'favicon.ico',
        'api/health'
    ],

    // Dynamic queries exclusion for tracing and metrics
    'excluded_queries' => ['select * from `contact_setting` limit 1', 'update `logs`'],

    'cpu_path' => '/proc/stat',
    'memory_path' => '/proc/meminfo',
    'disk_path' => '/',
    'network_path' => '/proc/net/dev',
    'connection_path' => '/proc/net/tcp',
    'network_states' => ['ESTABLISHED', 'CLOSE_WAIT', 'TIME_WAIT', 'LISTEN', 'SYN_SENT', 'SYN_RECV'],
];
