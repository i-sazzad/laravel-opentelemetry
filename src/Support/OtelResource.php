<?php

namespace Laratel\Opentelemetry\Support;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

final class OtelResource
{
    /**
     * Build the resource attached to every exported signal.
     * Merged over the SDK default resource so custom attributes win.
     */
    public static function build(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                'service.name' => config('opentelemetry.service_name'),
                'service.namespace' => config('opentelemetry.service_namespace'),
                'service.version' => config('opentelemetry.service_version'),
                'deployment.environment' => config('app.env'),
            ]))
        );
    }
}
