<?php

namespace Kisantra\Watchpost;

use Illuminate\Http\Client\PendingRequest;
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
     * The browser's trail and screenshot for a failed request. Sent after the
     * response, so it may take longer than an error report.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendClientReport(array $payload): bool
    {
        return $this->post('/api/ingest/client', $payload, 204, max(10.0, (float) config('watchpost.timeout', 2)));
    }

    /**
     * A bug report, with its attachment when there is one. Returns Watchpost's
     * answer (ticket id, number, url, linked_to_error), or null when it could
     * not be delivered. Sent from a queued job, so it may wait for the upload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ticket: int, number: string, url: string, linked_to_error: bool}|null
     */
    public function sendTicket(array $payload, ?string $attachmentPath = null, ?string $attachmentName = null): ?array
    {
        try {
            $request = $this->request(max(10.0, (float) config('watchpost.timeout', 2)));
            $contents = $attachmentPath !== null && is_file($attachmentPath) && is_readable($attachmentPath)
                ? file_get_contents($attachmentPath)
                : false;

            $response = $contents !== false
                ? $request->attach('attachment', $contents, $attachmentName ?? basename((string) $attachmentPath))
                    ->post($this->url('/api/ingest/tickets'), self::multipart($payload))
                : $request->post($this->url('/api/ingest/tickets'), $payload);

            // 201 for a new report, 200 when Watchpost already has this one.
            if (! in_array($response->status(), [200, 201], true)) {
                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['ticket'], $data['number'], $data['url'])) {
                return null;
            }

            return [
                'ticket' => (int) $data['ticket'],
                'number' => (string) $data['number'],
                'url' => (string) $data['url'],
                'linked_to_error' => (bool) ($data['linked_to_error'] ?? false),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tickets holding this website's reports that changed since the cursor,
     * and the cursor for the next call. Null when Watchpost could not answer.
     *
     * @return array{tickets: list<array{ticket: int, number: string, url: string, status: string, resolved_at: string|null, references: list<string>}>, next_since: string}|null
     */
    public function fetchTicketUpdates(?string $since): ?array
    {
        try {
            $response = $this->request((float) config('watchpost.timeout', 2))
                ->get($this->url('/api/ingest/tickets/updates'), array_filter(['since' => $since]));

            $data = $response->status() === 200 ? $response->json() : null;

            if (! is_array($data) || ! is_array($data['tickets'] ?? null) || ! is_string($data['next_since'] ?? null)) {
                return null;
            }

            $tickets = [];

            foreach ($data['tickets'] as $ticket) {
                if (! is_array($ticket) || ! isset($ticket['ticket'], $ticket['number'], $ticket['url'], $ticket['status'])) {
                    continue;
                }

                $tickets[] = [
                    'ticket' => (int) $ticket['ticket'],
                    'number' => (string) $ticket['number'],
                    'url' => (string) $ticket['url'],
                    'status' => (string) $ticket['status'],
                    'resolved_at' => isset($ticket['resolved_at']) ? (string) $ticket['resolved_at'] : null,
                    'references' => array_values(array_map('strval', array_filter((array) ($ticket['references'] ?? []), 'is_scalar'))),
                ];
            }

            return ['tickets' => $tickets, 'next_since' => $data['next_since']];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Success means the exact status Watchpost answers with, so a wrong
     * WATCHPOST_URL that happens to serve a normal page is not mistaken for it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, int $expectedStatus, ?float $timeout = null): bool
    {
        try {
            return $this->request($timeout ?? (float) config('watchpost.timeout', 2))
                ->post($this->url($path), $payload)
                ->status() === $expectedStatus;
        } catch (Throwable) {
            return false;
        }
    }

    private function request(float $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout($timeout)
            ->acceptJson()
            ->withToken((string) config('watchpost.token'));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('watchpost.url'), '/').$path;
    }

    /**
     * Nested fields as form names (reporter[name], breadcrumbs[0][type]), the
     * way Watchpost reads them from a multipart body. Empty values are left out.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array{name: string, contents: string}>
     */
    private static function multipart(array $data, ?string $prefix = null): array
    {
        $parts = [];

        foreach ($data as $key => $value) {
            $name = $prefix === null ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                array_push($parts, ...self::multipart($value, $name));
            } elseif (is_bool($value)) {
                $parts[] = ['name' => $name, 'contents' => $value ? '1' : '0'];
            } elseif (is_scalar($value)) {
                $parts[] = ['name' => $name, 'contents' => (string) $value];
            }
        }

        return $parts;
    }
}
