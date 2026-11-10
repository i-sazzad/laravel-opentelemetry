<?php

namespace Laratel\Opentelemetry\Support;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * Wraps an OTLP transport with the circuit breaker: the first failed
 * export marks the collector down, and while it is down exports are
 * dropped immediately instead of each waiting for a timeout.
 *
 * @implements TransportInterface<string>
 */
final class GuardedTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $inner) {}

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if (! Reachability::check()) {
            // Resolves like an empty collector response: the batch is
            // dropped quietly rather than reported as an export error.
            return new CompletedFuture(null);
        }

        try {
            return $this->inner->send($payload, $cancellation)->catch(self::tripBreaker(...));
        } catch (\Throwable $e) {
            return new CompletedFuture(self::tripBreaker($e));
        }
    }

    /**
     * Mark the collector down and swallow the failure: it is reported once
     * through the application log, instead of once per signal (with a full
     * stack trace) through the SDK's error_log channel.
     */
    private static function tripBreaker(\Throwable $e): null
    {
        Reachability::markDown('OTLP export failed', ['error' => $e->getMessage()]);

        return null;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }
}
