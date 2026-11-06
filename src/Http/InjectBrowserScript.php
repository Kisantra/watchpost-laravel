<?php

namespace Kisantra\Watchpost\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Kisantra\Watchpost\Client;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Gives every web request a trace id (the browser's, when it sent one) so an
 * error and the browser's screenshot of it can be matched, and adds the
 * browser script to HTML pages.
 */
class InjectBrowserScript
{
    public const TRACE_HEADER = 'X-Watchpost-Trace';

    public const TRACE_ATTRIBUTE = 'watchpost_trace';

    public function __construct(private Client $client) {}

    public function handle(Request $request, Closure $next): Response
    {
        $trace = $request->header(self::TRACE_HEADER);
        $request->attributes->set(self::TRACE_ATTRIBUTE, is_string($trace) && Str::isUuid($trace) ? $trace : (string) Str::uuid());

        $response = $next($request);

        try {
            if ($this->wantsScript($response)) {
                $this->inject($request, $response);
            }
        } catch (Throwable) {
            // Monitoring must never break the website.
        }

        return $response;
    }

    private function wantsScript(Response $response): bool
    {
        if (! config('watchpost.browser.enabled', true) || ! $this->client->enabled()) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        // View responses only get their Content-Type when they are sent, so none means HTML.
        $type = (string) $response->headers->get('Content-Type');
        $content = $response->getContent();

        return ($type === '' || str_contains($type, 'text/html'))
            && is_string($content)
            && stripos($content, '</head>') !== false;
    }

    /**
     * Sites with a CSP that uses Vite's nonce need it on this tag too.
     */
    private function nonce(): ?string
    {
        try {
            return Vite::cspNonce();
        } catch (Throwable) {
            return null;
        }
    }

    private function inject(Request $request, Response $response): void
    {
        $base = $request->getBaseUrl();
        $attributes = [
            'src' => $base.'/watchpost/browser.js?v='.BrowserScriptController::version(),
            'data-endpoint' => $base.'/watchpost/client',
            'data-screenshots' => config('watchpost.browser.screenshots', true) ? '1' : '0',
            'data-breadcrumbs' => (string) (int) config('watchpost.browser.breadcrumbs', 10),
            // Set when this page is itself the error page of a full page load.
            'data-failed-trace' => $response->getStatusCode() >= 500 ? (string) $request->attributes->get(self::TRACE_ATTRIBUTE) : null,
            'nonce' => $this->nonce(),
        ];

        $tag = '<script defer'.collect($attributes)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $name) => ' '.$name.'="'.e($value).'"')
            ->implode('').'></script>';

        // setContent() replaces the response's original view with the new string;
        // keep the view, which the website's own tests read (viewData, assertInertia).
        $original = $response instanceof IlluminateResponse ? $response->original : null;

        $response->setContent((string) preg_replace('~</head>~i', $tag.'</head>', (string) $response->getContent(), 1));

        if ($original !== null && $response instanceof IlluminateResponse) {
            $response->original = $original;
        }
    }
}
