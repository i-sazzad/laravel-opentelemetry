<?php

return [

    // === OTLP Endpoint Configuration ===
    'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http'),
    'endpoint' => env(
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http') === 'grpc'
            ? 'http://localhost:4317'
            : 'http://localhost:4318'
    ),

    // === Service Metadata ===
    'service_name' => env('OTEL_SERVICE_NAME', 'laravel_app'),
    'service_namespace' => env('OTEL_SERVICE_NAMESPACE', 'laratel'),
    'service_version' => env('OTEL_SERVICE_VERSION', '1.0.0'),

    // === Optional Flags ===
    'enable_reachability_check' => env('OTEL_ENABLE_REACHABILITY_CHECK', true),
    'reachability_timeout' => (float) env('OTEL_REACHABILITY_TIMEOUT', 0.1),
    'system_metrics_enabled' => env('OTEL_SYSTEM_METRICS_ENABLED', true),

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

    // === Query Exclusions (plain case-insensitive substrings) ===
    'excluded_queries' => [
        '`contact_setting`',
        'update `logs`',
    ],

    // === System Paths for Metrics ===
    'cpu_path' => '/proc/stat',
    'memory_path' => '/proc/meminfo',
    'disk_path' => '/',
    'network_path' => '/proc/net/dev',
    'connection_path' => '/proc/net/tcp',
];
