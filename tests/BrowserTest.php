<?php

namespace Kisantra\Watchpost\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Kisantra\Watchpost\Http\BrowserScriptController;
use RuntimeException;

class BrowserTest extends TestCase
{
    private const TRACE = '6f1c2a5e-8d4b-4c3a-9e2f-1a2b3c4d5e6f';

    private const PAGE = '<!doctype html><html><head><title>Order</title></head><body><button>Simpan</button></body></html>';

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('orders', fn () => self::PAGE);
            Route::get('api-ish', fn () => ['ok' => true]);
            Route::post('orders', fn () => throw new RuntimeException('Gagal simpan order'));
            Route::get('broken', fn () => throw new RuntimeException('Halaman rusak'));
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sent(string $path): ?array
    {
        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), $path));

        return $request?->data();
    }

    public function test_html_pages_get_the_browser_script_and_other_responses_do_not(): void
    {
        $html = $this->get('/orders')->assertOk()->getContent();

        $this->assertStringContainsString('<script defer src="/watchpost/browser.js?v='.BrowserScriptController::version().'"', $html);
        $this->assertStringContainsString('data-endpoint="/watchpost/client"', $html);
        $this->assertStringContainsString('data-screenshots="1"', $html);
        $this->assertStringContainsString('data-breadcrumbs="10"', $html);
        $this->assertStringNotContainsString('data-failed-trace', $html);
        $this->assertSame(1, substr_count($html, '/watchpost/browser.js'));

        $this->assertStringNotContainsString('watchpost', $this->get('/api-ish')->getContent());
    }

    public function test_nothing_is_added_where_watchpost_does_not_report(): void
    {
        config(['watchpost.environments' => ['production']]);
        $this->assertStringNotContainsString('watchpost', $this->get('/orders')->getContent());

        config(['watchpost.environments' => ['testing'], 'watchpost.browser.enabled' => false]);
        $this->assertStringNotContainsString('watchpost', $this->get('/orders')->getContent());
    }

    public function test_the_browser_trace_id_is_attached_to_the_error_it_caused(): void
    {
        Http::fake();

        $this->post('/orders', [], ['X-Watchpost-Trace' => self::TRACE])->assertServerError();

        $this->assertSame(self::TRACE, $this->sent('/api/ingest/errors')['request']['trace_id']);
    }

    public function test_an_error_page_carries_its_own_trace_so_the_browser_can_send_the_trail(): void
    {
        Http::fake();

        $html = $this->get('/broken')->assertServerError()->getContent();
        $trace = $this->sent('/api/ingest/errors')['request']['trace_id'];

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $trace);
        $this->assertStringContainsString('data-failed-trace="'.$trace.'"', $html);
    }

    public function test_a_made_up_trace_header_is_replaced(): void
    {
        Http::fake();

        $this->post('/orders', [], ['X-Watchpost-Trace' => '<script>'])->assertServerError();

        $this->assertNotSame('<script>', $this->sent('/api/ingest/errors')['request']['trace_id']);
    }

    public function test_the_script_is_served_from_the_package_with_long_caching(): void
    {
        $response = $this->get('/watchpost/browser.js?v='.BrowserScriptController::version())->assertOk();

        $this->assertStringContainsString('application/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('modernScreenshot', $response->getContent());
        $this->assertStringContainsString('X-Watchpost-Trace', $response->getContent());
    }

    public function test_the_trail_and_screenshot_are_passed_on_to_watchpost_with_the_site_token(): void
    {
        Http::fake(['*/api/ingest/client' => Http::response(null, 204)]);

        $this->postJson('/watchpost/client', [
            'trace' => self::TRACE,
            'url' => '/orders',
            'page' => '/orders/create',
            'breadcrumbs' => [
                ['type' => 'page', 'label' => '/orders/create', 'at' => '2026-11-01T09:00:00Z'],
                ['type' => 'click', 'label' => 'Simpan', 'at' => '2026-11-01T09:00:05Z'],
            ],
            'screenshot' => 'data:image/jpeg;base64,'.base64_encode('jpeg'),
            'viewport' => '1440x900',
            'user_agent' => 'Mozilla/5.0',
        ])->assertNoContent();

        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/client'));

        $this->assertNotNull($request);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer wp_test_token'));
        $this->assertSame(self::TRACE, $request['trace_id']);
        $this->assertSame('Simpan', $request['breadcrumbs'][1]['label']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $request['screenshot']);
    }

    public function test_bad_reports_are_rejected_and_screenshots_can_be_switched_off(): void
    {
        Http::fake(['*/api/ingest/client' => Http::response(null, 204)]);

        $this->postJson('/watchpost/client', ['trace' => 'bukan-uuid', 'screenshot' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trace', 'screenshot']);

        config(['watchpost.browser.screenshots' => false]);

        $this->postJson('/watchpost/client', ['trace' => self::TRACE, 'screenshot' => 'data:image/jpeg;base64,AAAA'])->assertNoContent();

        $this->assertNull($this->sent('/api/ingest/client')['screenshot']);
    }
}
