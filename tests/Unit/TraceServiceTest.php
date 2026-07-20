<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Services\TraceService;
use Laratel\Opentelemetry\Tests\TestCase;

class TraceServiceTest extends TestCase
{
    public function test_excluded_query_substrings_match_case_insensitively(): void
    {
        config()->set('opentelemetry.excluded_queries', ['contact_setting', 'update `logs`']);

        $service = new TraceService;

        $this->assertTrue($service->shouldExcludeQuery('select * from `contact_setting` where id = 1'));
        $this->assertTrue($service->shouldExcludeQuery('UPDATE `LOGS` set level = ?'));
        $this->assertFalse($service->shouldExcludeQuery('select * from `users`'));
    }

    public function test_no_exclusions_configured_excludes_nothing(): void
    {
        config()->set('opentelemetry.excluded_queries', []);

        $this->assertFalse((new TraceService)->shouldExcludeQuery('select 1'));
    }
}
