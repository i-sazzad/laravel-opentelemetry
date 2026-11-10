<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Laratel\Opentelemetry\Support\OtlpTransport;
use Laratel\Opentelemetry\Support\Reachability;
use Laratel\Opentelemetry\Support\Sql;
use Laratel\Opentelemetry\Tests\TestCase;

class SupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The service provider already probed (and cached) while booting.
        Reachability::reset();
    }

    public function test_sql_operation_is_a_bounded_keyword(): void
    {
        $this->assertSame('select', Sql::operation('SELECT * FROM users'));
        $this->assertSame('select', Sql::operation("  select\n*\nfrom users"));
        $this->assertSame('select', Sql::operation('(select 1) union (select 2)'));
        $this->assertSame('insert', Sql::operation("insert\tinto users values (?)"));
        $this->assertSame('other', Sql::operation('/* hint */ select 1'));
        $this->assertSame('other', Sql::operation(''));
    }

    public function test_headers_parse_the_otel_env_format(): void
    {
        config()->set('opentelemetry.headers', 'Authorization=Bearer%20abc, x-tenant = acme ,broken');

        $this->assertSame(
            ['Authorization' => 'Bearer abc', 'x-tenant' => 'acme'],
            OtlpTransport::headers()
        );
    }

    public function test_headers_accept_an_array_and_default_to_none(): void
    {
        $this->assertSame([], OtlpTransport::headers());

        config()->set('opentelemetry.headers', ['x-api-key' => 'secret']);
        $this->assertSame(['x-api-key' => 'secret'], OtlpTransport::headers());
    }

    public function test_unsupported_protocol_is_rejected(): void
    {
        config()->set('opentelemetry.protocol', 'carrier-pigeon');

        $this->expectException(\InvalidArgumentException::class);
        OtlpTransport::create(OtlpTransport::TRACES);
    }

    public function test_http_protocol_variants_build_a_transport(): void
    {
        config()->set('opentelemetry.endpoint', 'http://127.0.0.1:1/');

        foreach (['http' => 'application/json', 'http/json' => 'application/json', 'http/protobuf' => 'application/x-protobuf'] as $protocol => $contentType) {
            config()->set('opentelemetry.protocol', $protocol);
            $this->assertSame($contentType, OtlpTransport::create(OtlpTransport::LOGS)->contentType());
        }
    }

    public function test_unreachable_endpoint_is_probed_once_and_cached(): void
    {
        Log::shouldReceive('log')->once()->withArgs(fn ($level) => $level === 'warning');

        $this->assertFalse(Reachability::check());
        $this->assertFalse(Reachability::check());
    }

    public function test_invalid_endpoint_disables_telemetry(): void
    {
        config()->set('opentelemetry.endpoint', 'not a url');
        Log::shouldReceive('log')->once()->withArgs(fn ($level) => $level === 'error');

        $this->assertFalse(Reachability::check());
    }

    public function test_probe_uses_the_scheme_default_port_when_none_is_given(): void
    {
        // Listen on an ephemeral port to prove an explicit port is honoured...
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        config()->set('opentelemetry.endpoint', "http://127.0.0.1:{$port}");
        $this->assertTrue(Reachability::check());
        fclose($server);

        // ...and that a port-less URL is not probed on the collector ports
        // (nothing listens on 127.0.0.1:80 in the test environment).
        Reachability::reset();
        config()->set('opentelemetry.endpoint', 'http://127.0.0.1');
        Log::shouldReceive('log')->once()->withArgs(
            fn ($level, $message) => str_contains($message, '127.0.0.1:80.')
        );
        $this->assertFalse(Reachability::check());
    }

    public function test_reachability_check_can_be_disabled(): void
    {
        config()->set('opentelemetry.enable_reachability_check', false);

        $this->assertTrue(Reachability::check());
    }
}
