<?php

namespace Kisantra\Watchpost\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kisantra\Watchpost\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives the browser's trail and screenshot for a failed request and passes
 * them to Watchpost with this website's token, after the response is sent.
 * The browser never sees the token.
 */
class ClientReportController
{
    public function __invoke(Request $request, Client $client): Response
    {
        if (! config('watchpost.browser.enabled', true) || ! $client->enabled()) {
            return response()->noContent();
        }

        $data = $request->validate([
            'trace' => ['required', 'uuid'],
            'url' => ['nullable', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:2000'],
            'breadcrumbs' => ['nullable', 'array', 'max:50'],
            'breadcrumbs.*.type' => ['required', 'string', 'in:page,click,submit,request'],
            'breadcrumbs.*.label' => ['required', 'string', 'max:200'],
            'breadcrumbs.*.at' => ['required', 'date'],
            // A JPEG data URL; about 3 MB decoded at most.
            'screenshot' => ['nullable', 'string', 'max:4000000', 'starts_with:data:image/'],
            'viewport' => ['nullable', 'string', 'max:20'],
            'user_agent' => ['nullable', 'string'],
        ]);

        $payload = [
            'trace_id' => $data['trace'],
            'url' => $data['url'] ?? null,
            'page' => $data['page'] ?? null,
            'breadcrumbs' => array_values($data['breadcrumbs'] ?? []),
            'screenshot' => config('watchpost.browser.screenshots', true) ? ($data['screenshot'] ?? null) : null,
            'viewport' => $data['viewport'] ?? null,
            'user_agent' => isset($data['user_agent']) ? Str::limit($data['user_agent'], 500, '') : null,
        ];

        app()->terminating(fn () => $client->sendClientReport($payload));

        return response()->noContent();
    }
}
