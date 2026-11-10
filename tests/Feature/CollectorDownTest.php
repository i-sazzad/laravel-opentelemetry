<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Support\Reachability;
use Laratel\Opentelemetry\Support\SnapshotStore;
use Laratel\Opentelemetry\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The collector being down — refused, unresolvable, or accepting
 * connections and never answering — must never surface in the application.
 */
class CollectorDownTest extends TestCase
{
    /** @var resource|null */
    private $blackHole = null;

    protected function tearDown(): void
    {
        foreach (['OTEL_ENABLE_REACHABILITY_CHECK', 'OTEL_EXPORT_TIMEOUT'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        if (is_resource($this->blackHole)) {
            fclose($this->blackHole);
        }

        parent::tearDown();
    }

    /** Boot a fresh application against $endpoint and register a fully instrumented route. */
    private function bootAgainst(string $endpoint, bool $probe): void
    {
        $env = [
            'OTEL_EXPORTER_OTLP_ENDPOINT' => $endpoint,
            'OTEL_ENABLE_REACHABILITY_CHECK' => $probe ? 'true' : 'false',
            'OTEL_EXPORT_TIMEOUT' => '0.3',
        ];
        foreach ($env as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        Reachability::reset();
        $this->refreshApplication();

        config()->set('logging.channels.otel', ['driver' => 'custom', 'via' => OtelLoggerFactory::class]);
        config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['otel', 'null']]);
        config()->set('logging.default', 'stack');

        Route::middleware(['opentelemetry.metrics', 'opentelemetry.trace'])->get('work', function () {
            Log::info('through the default stack');
            Log::channel('otel')->error('straight to the otel channel');

            return 'ok';
        });
    }

    /** A port that accepts TCP connections but never answers. */
    private function blackHoleEndpoint(): string
    {
        $this->blackHole = stream_socket_server('tcp://127.0.0.1:0');

        return 'http://'.stream_socket_get_name($this->blackHole, false);
    }

    public static function deadEndpoints(): array
    {
        return [
            'connection refused, probed' => ['http://127.0.0.1:1', true],
            'connection refused, probe disabled' => ['http://127.0.0.1:1', false],
            'unresolvable host, probed' => ['http://no-such-host.invalid:4318', true],
            'unresolvable host, probe disabled' => ['http://no-such-host.invalid:4318', false],
            'malformed endpoint' => ['not a url', true],
        ];
    }

    #[DataProvider('deadEndpoints')]
    public function test_request_succeeds_when_the_collector_is_dead(string $endpoint, bool $probe): void
    {
        $this->bootAgainst($endpoint, $probe);

        $this->withoutExceptionHandling()->get('work')->assertOk()->assertSee('ok');
        $this->withoutExceptionHandling()->get('work')->assertOk();

        $this->assertFalse(Reachability::check(), 'the collector should be marked down');
    }

    public function test_a_collector_that_never_answers_cannot_hold_the_request(): void
    {
        // The probe passes (the port is open), so everything is wired up
        // and the failure only shows when exporting.
        $this->bootAgainst($this->blackHoleEndpoint(), true);

        $started = microtime(true);
        $this->withoutExceptionHandling()->get('work')->assertOk();
        $first = microtime(true) - $started;

        // One timed-out export (0.3s) trips the breaker; the other two
        // signals are dropped instead of each waiting for their own timeout.
        // (The SDK defaults would have been 10s x 4 attempts x 3 signals.)
        $this->assertLessThan(0.3 * 2, $first, 'only one export may wait for the timeout');
        $this->assertFalse(Reachability::check());

        $started = microtime(true);
        $this->withoutExceptionHandling()->get('work')->assertOk();
        $this->assertLessThan(0.25, microtime(true) - $started, 'no export is attempted while the collector is down');
    }

    public function test_down_marker_is_shared_through_the_state_file_and_expires(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'otel-state-');
        config()->set('opentelemetry.state_path', $stateFile);
        config()->set('opentelemetry.enable_reachability_check', false);
        Reachability::reset();

        Reachability::markDown('test');
        $this->assertFalse(Reachability::check());

        // A different PHP-FPM process: no static state, same file.
        $this->forgetProcessState();
        $this->assertFalse(Reachability::check(), 'other processes must see the marker');

        // Cool-down over: telemetry resumes.
        SnapshotStore::put('collector_down', ['until' => time() - 1]);
        $this->forgetProcessState();
        $this->assertTrue(Reachability::check());

        @unlink($stateFile);
    }

    public function test_unreachable_warning_is_logged_once_per_cool_down(): void
    {
        config()->set('opentelemetry.enable_reachability_check', true);
        Reachability::reset();

        Log::shouldReceive('log')->once();

        Reachability::check();
        $this->forgetProcessState();   // next request
        Reachability::check();
    }

    private function forgetProcessState(): void
    {
        (new \ReflectionProperty(Reachability::class, 'reachable'))->setValue(null, null);
        (new \ReflectionProperty(Reachability::class, 'expiresAt'))->setValue(null, 0);
    }
}
