<?php

namespace Kisantra\Watchpost;

use Illuminate\Support\Str;
use Kisantra\Watchpost\Http\InjectBrowserScript;
use Throwable;

/**
 * Turns an exception into the JSON Watchpost expects, without secrets.
 */
class PayloadFactory
{
    private const MAX_FRAMES = 60;

    private const CONTEXT_LINES = 3;

    /** What Watchpost accepts as context on an error: 20 values of 255 characters. */
    private const MAX_CONTEXT = 20;

    private const BREADCRUMB_TYPES = ['page', 'click', 'submit', 'request'];

    private static ?string $release = null;

    /** @var list<callable(): array<string, mixed>> */
    private array $context = [];

    /**
     * Extra values the website sends with every error, such as the error code
     * its error page shows the visitor. Called while an error is reported.
     *
     * @param  callable(): array<string, mixed>  $callback
     */
    public function addContext(callable $callback): void
    {
        $this->context[] = $callback;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromException(Throwable $exception): array
    {
        $frames = $this->frames($exception);

        // Group by the first frame in the application's own code, so a QueryException
        // thrown inside the framework is filed under the line that caused it.
        $origin = collect($frames)->first(fn (array $frame) => $frame['app']) ?? $frames[0];

        return [
            'exception' => get_class($exception),
            'message' => Str::limit($exception->getMessage(), 2000),
            'file' => $origin['file'],
            'line' => $origin['line'],
            'environment' => app()->environment(),
            'release' => $this->release(),
            'occurred_at' => now()->toIso8601String(),
            'trace' => $frames,
            'code' => $this->code($origin['file'], $origin['line']),
            'request' => $this->request(),
            'context' => $this->context(),
        ];
    }

    /**
     * A user's bug report, trimmed to what Watchpost accepts so one odd value
     * never gets the whole report refused. The platform it came from is added.
     *
     * @return array<string, mixed>
     */
    public function ticket(BugReport $report): array
    {
        return [
            'reference' => Str::limit($report->reference, 64, ''),
            'title' => Str::limit($report->title, 255, ''),
            'description' => Str::limit($report->description, 10000, ''),
            'priority' => $report->priority,
            'reporter' => [
                'name' => Str::limit($report->reporterName, 255, ''),
                'email' => filter_var($report->reporterEmail, FILTER_VALIDATE_EMAIL) ? $report->reporterEmail : null,
            ],
            'company' => $report->company !== null ? Str::limit($report->company, 255, '') : null,
            'page_url' => $report->pageUrl !== null ? Str::limit($this->redactUrl($report->pageUrl), 2000, '') : null,
            'trace_id' => $report->traceId !== null && Str::isUuid($report->traceId) ? $report->traceId : null,
            'error_id' => $report->errorId !== null ? Str::limit($report->errorId, 64, '') : null,
            'breadcrumbs' => $this->breadcrumbs($report->breadcrumbs),
            'user_agent' => $report->userAgent !== null ? Str::limit($report->userAgent, 500, '') : null,
            'source' => [
                'app' => Str::limit((string) config('app.name'), 120, ''),
                'url' => Str::limit((string) config('app.url'), 255, ''),
                'environment' => app()->environment(),
                'release' => $this->release() ?: null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(HealthChecks $health): array
    {
        return [
            'environment' => app()->environment(),
            'release' => $this->release(),
            'health' => $health->run(),
        ];
    }

    /**
     * @return list<array{file: string, line: int, function: string|null, app: bool}>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [[
            'file' => $this->relative($exception->getFile()),
            'line' => $exception->getLine(),
            'function' => null,
            'app' => $this->isApplicationFile($exception->getFile()),
        ]];

        foreach ($exception->getTrace() as $index => $frame) {
            if (! isset($frame['file'], $frame['line'])) {
                continue;
            }

            // The function shown on a frame is the one that frame was inside of.
            $caller = $exception->getTrace()[$index + 1] ?? null;

            $frames[] = [
                'file' => $this->relative($frame['file']),
                'line' => (int) $frame['line'],
                'function' => $caller ? ltrim(($caller['class'] ?? '').'::'.($caller['function'] ?? ''), ':') : null,
                'app' => $this->isApplicationFile($frame['file']),
            ];
        }

        // The top frame's function is the first trace entry's own function.
        $first = $exception->getTrace()[0] ?? null;
        $frames[0]['function'] = $first ? ltrim(($first['class'] ?? '').'::'.($first['function'] ?? ''), ':') : null;

        return array_slice($frames, 0, self::MAX_FRAMES);
    }

    /**
     * @return array{start: int, line: int, lines: list<string>}|null
     */
    private function code(string $relativeFile, int $line): ?array
    {
        $path = $this->basePath().DIRECTORY_SEPARATOR.$relativeFile;

        if ($line < 1 || ! is_file($path) || ! is_readable($path) || ! str_starts_with(realpath($path) ?: '', realpath($this->basePath()) ?: '')) {
            return null;
        }

        $source = @file($path, FILE_IGNORE_NEW_LINES);

        if ($source === false) {
            return null;
        }

        $start = max(1, $line - self::CONTEXT_LINES);
        $lines = array_slice($source, $start - 1, self::CONTEXT_LINES * 2 + 1);

        return [
            'start' => $start,
            'line' => $line,
            'lines' => array_map(fn (string $text) => Str::limit($text, 500, ''), $lines),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function request(): ?array
    {
        if (! static::inHttpRequest()) {
            return null;
        }

        try {
            $request = request();

            return [
                'method' => $request->method(),
                'url' => $this->redactUrl($request->fullUrl()),
                'route' => $request->route()?->getName(),
                'user' => ($id = $request->user()?->getAuthIdentifier()) !== null ? (string) $id : null,
                'ip' => $request->ip(),
                // Matches the browser's screenshot of this request, when there is one.
                'trace_id' => $request->attributes->get(InjectBrowserScript::TRACE_ATTRIBUTE),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True while handling a routed web request (not a queue job or command).
     */
    public static function inHttpRequest(): bool
    {
        try {
            return app()->bound('request') && request()->route() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The fragment is kept: a page opened in a dialog (#invoice/12) says where
     * the user was, and it never reaches the server anyway.
     */
    private function redactUrl(string $url): string
    {
        if (! str_contains($url, '?')) {
            return $url;
        }

        [$base, $rest] = explode('?', $url, 2);
        [$query, $fragment] = str_contains($rest, '#') ? explode('#', $rest, 2) : [$rest, null];
        parse_str($query, $params);

        foreach (array_keys($params) as $name) {
            if (Str::contains(Str::lower((string) $name), (array) config('watchpost.redact'))) {
                $params[$name] = '[disaring]';
            }
        }

        return $base.'?'.http_build_query($params).($fragment !== null ? '#'.$fragment : '');
    }

    /**
     * The website's own values for this error, as text. A callback that fails,
     * or a value that is not text or a number, is left out.
     *
     * @return array<string, string|null>|null
     */
    private function context(): ?array
    {
        $values = [];

        foreach ($this->context as $callback) {
            try {
                $values = [...$values, ...(array) $callback()];
            } catch (Throwable) {
                // A context callback must never cost the error report itself.
            }
        }

        $values = collect($values)
            ->filter(fn ($value) => $value === null || is_scalar($value))
            ->map(fn ($value) => match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                default => Str::limit((string) $value, 255, ''),
            })
            ->take(self::MAX_CONTEXT)
            ->all();

        return $values === [] ? null : $values;
    }

    /**
     * Steps from the browser's trail that Watchpost can read; anything else is dropped.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<array{type: string, label: string, at: string}>
     */
    private function breadcrumbs(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item)
                && in_array($item['type'] ?? null, self::BREADCRUMB_TYPES, true)
                && is_string($item['label'] ?? null)
                && is_string($item['at'] ?? null)
                && strtotime($item['at']) !== false)
            ->map(fn (array $item) => [
                'type' => $item['type'],
                'label' => Str::limit($item['label'], 200, ''),
                'at' => $item['at'],
            ])
            ->take(-50)
            ->values()
            ->all();
    }

    private function release(): ?string
    {
        if (filled(config('watchpost.release'))) {
            return (string) config('watchpost.release');
        }

        return self::$release ??= $this->gitCommit() ?? '';
    }

    private function gitCommit(): ?string
    {
        $head = @file_get_contents(base_path('.git/HEAD'));

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (str_starts_with($head, 'ref: ')) {
            $head = trim((string) @file_get_contents(base_path('.git/'.substr($head, 5))));
        }

        return preg_match('/^[0-9a-f]{7,40}$/', $head) ? substr($head, 0, 7) : null;
    }

    private function isApplicationFile(string $path): bool
    {
        $base = $this->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            && ! str_contains(substr($path, strlen($base)), 'vendor'.DIRECTORY_SEPARATOR);
    }

    private function relative(string $path): string
    {
        $base = $this->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function basePath(): string
    {
        return rtrim((string) (config('watchpost.base_path') ?? base_path()), DIRECTORY_SEPARATOR);
    }
}
