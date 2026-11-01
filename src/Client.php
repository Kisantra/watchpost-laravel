<?php

namespace Kisantra\Watchpost;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to the Watchpost ingest API. Never throws: a monitoring outage
 * must not turn into an outage of the website itself.
 */
class Client
{
    public function enabled(): bool
    {
        return (bool) config('watchpost.enabled')
            && filled(config('watchpost.url'))
            && filled(config('watchpost.token'))
            && in_array(app()->environment(), (array) config('watchpost.environments'), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendError(array $payload): bool
    {
        return $this->post('/api/ingest/errors', $payload, 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendHeartbeat(array $payload): bool
    {
        return $this->post('/api/ingest/heartbeat', $payload, 204);
    }

    /**
     * Success means the exact status Watchpost answers with, so a wrong
     * WATCHPOST_URL that happens to serve a normal page is not mistaken for it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, int $expectedStatus): bool
    {
        $timeout = (float) config('watchpost.timeout', 2);

        try {
            return Http::timeout($timeout)
                ->connectTimeout($timeout)
                ->acceptJson()
                ->withToken((string) config('watchpost.token'))
                ->post(rtrim((string) config('watchpost.url'), '/').$path, $payload)
                ->status() === $expectedStatus;
        } catch (Throwable) {
            return false;
        }
    }
}
