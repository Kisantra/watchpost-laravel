<?php

namespace Kisantra\Watchpost\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the screenshot library and the Watchpost browser script as one file,
 * from the package itself, so websites need no CDN and no build step.
 */
class BrowserScriptController
{
    private const FILES = [
        __DIR__.'/../../resources/dist/modern-screenshot.js',
        __DIR__.'/../../resources/dist/watchpost.js',
    ];

    public function __invoke(Request $request): Response
    {
        $version = static::version();
        $body = collect(self::FILES)->map(fn (string $file) => (string) file_get_contents($file))->implode("\n");

        return response($body, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            // The tag asks for ?v=<version>, which changes with the files.
            'Cache-Control' => $request->query('v') === $version
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function version(): string
    {
        return substr(md5(collect(self::FILES)->map(fn (string $file) => (string) @filemtime($file))->implode('|')), 0, 10);
    }
}
