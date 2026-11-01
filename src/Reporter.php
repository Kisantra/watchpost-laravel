<?php

namespace Kisantra\Watchpost;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Called by the exception handler for every reported exception.
 */
class Reporter
{
    /** Repeats of the same error within this many seconds are dropped. */
    private const THROTTLE_SECONDS = 10;

    /** @var array<string, true> */
    private array $sent = [];

    private bool $reporting = false;

    public function __construct(
        private Client $client,
        private PayloadFactory $payloads,
    ) {}

    public function report(Throwable $exception): void
    {
        // Guard against reporting errors raised while reporting.
        if ($this->reporting || ! $this->client->enabled()) {
            return;
        }

        $this->reporting = true;

        try {
            $payload = $this->payloads->fromException($exception);
            $fingerprint = sha1($payload['exception'].'|'.$payload['file'].'|'.$payload['line']);

            if (isset($this->sent[$fingerprint]) || ! $this->claim($fingerprint)) {
                return;
            }

            $this->sent[$fingerprint] = true;
            $send = fn () => $this->client->sendError($payload);

            // In a web request, send after the response has gone out to the visitor.
            PayloadFactory::inHttpRequest() ? app()->terminating($send) : $send();
        } catch (Throwable) {
            // Monitoring must never break the website.
        } finally {
            $this->reporting = false;
        }
    }

    private function claim(string $fingerprint): bool
    {
        try {
            return Cache::add('watchpost:sent:'.$fingerprint, true, self::THROTTLE_SECONDS);
        } catch (Throwable) {
            // The cache itself may be what is failing; report anyway.
            return true;
        }
    }
}
