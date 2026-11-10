<?php

return [

    // === Master Switch ===
    // false turns the whole package into a no-op (no probe, no exporters).
    'enabled' => env('OTEL_ENABLED', true),

    // === OTLP Endpoint Configuration ===
    // 'http' (alias of 'http/json'), 'http/protobuf', or 'grpc' (needs ext-grpc).
    'protocol' => env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http'),
    'endpoint' => env(
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        env('OTEL_EXPORTER_OTLP_PROTOCOL', 'http') === 'grpc'
            ? 'http://localhost:4317'
            : 'http://localhost:4318'
    ),

    // Extra headers sent with every export, e.g. an auth token for a hosted
    // backend. "key1=value1,key2=value2" or an array.
    'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),

    // 'delta' or 'cumulative'. Under PHP-FPM every request is a fresh process
    // whose counters restart at zero, so cumulative values never grow there;
    // 'delta' is correct for both FPM and long-lived workers. Switch to
    // 'cumulative' only for a long-lived runtime (Octane) feeding a backend
    // that cannot ingest delta metrics.
    'metrics_temporality' => env('OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE', 'delta'),

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

    // Where the previous CPU/network counter readings are kept between
    // requests, so deltas work under PHP-FPM (one process per request).
    // null keeps them in memory only (enough for Octane).
    'state_path' => env('OTEL_STATE_PATH', storage_path('framework/cache/otel-system-metrics.json')),

    // === System Paths for Metrics ===
    'cpu_path' => '/proc/stat',
    'memory_path' => '/proc/meminfo',
    'disk_path' => '/',
    'network_path' => '/proc/net/dev',
    'connection_path' => '/proc/net/tcp',
];
