<?php

namespace Kisantra\Watchpost\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Kisantra\Watchpost\Facades\Watchpost;
use RuntimeException;

class ReporterTest extends TestCase
{
    private function report(\Throwable $exception): void
    {
        $this->app->make(ExceptionHandler::class)->report($exception);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentError(): array
    {
        $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/errors'));

        $this->assertNotNull($request, 'No error was sent to Watchpost.');
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer wp_test_token'));

        return $request->data();
    }

    public function test_an_exception_is_sent_with_its_location_trace_and_code(): void
    {
        Http::fake();

        $this->report(new RuntimeException('Stok tidak cukup')); $line = __LINE__;

        $payload = $this->sentError();

        $this->assertSame(RuntimeException::class, $payload['exception']);
        $this->assertSame('Stok tidak cukup', $payload['message']);
        $this->assertSame('tests/ReporterTest.php', $payload['file']);
        $this->assertSame($line, $payload['line']);
        $this->assertSame('testing', $payload['environment']);
        $this->assertSame('a1f9c3e', $payload['release']);
        $this->assertTrue($payload['trace'][0]['app']);
        $this->assertSame($line, $payload['code']['line']);
        $this->assertStringContainsString("new RuntimeException('Stok tidak cukup')", implode("\n", $payload['code']['lines']));
        $this->assertNull($payload['request']);
    }

    public function test_an_exception_thrown_inside_the_framework_is_filed_under_the_app_line_that_caused_it(): void
    {
        Http::fake();

        $line = __LINE__ + 2;
        try {
            collect()->firstOrFail();
        } catch (\Throwable $exception) {
            $this->report($exception);
        }

        $payload = $this->sentError();

        $this->assertStringContainsString('vendor/', $payload['trace'][0]['file']);
        $this->assertFalse($payload['trace'][0]['app']);
        $this->assertSame('tests/ReporterTest.php', $payload['file']);
        $this->assertSame($line, $payload['line']);
    }

    public function test_a_web_request_sends_the_route_and_hides_sensitive_query_values(): void
    {
        Http::fake();
        Route::get('checkout', fn () => throw new RuntimeException('Gagal checkout'))->name('checkout');

        $this->get('/checkout?id=5&token=rahasia&password=abc')->assertServerError();

        $request = $this->sentError()['request'];

        $this->assertSame('GET', $request['method']);
        $this->assertSame('checkout', $request['route']);
        $this->assertStringContainsString('id=5', $request['url']);
        $this->assertStringNotContainsString('rahasia', $request['url']);
        $this->assertStringNotContainsString('abc', $request['url']);
    }

    public function test_the_same_error_twice_within_ten_seconds_is_sent_once(): void
    {
        Http::fake();

        foreach ([1, 2] as $attempt) {
            $this->report(new RuntimeException('Sama'));
        }

        Http::assertSentCount(1);
    }

    public function test_exceptions_laravel_does_not_report_are_not_sent(): void
    {
        Http::fake();

        $this->report(ValidationException::withMessages(['email' => 'salah']));

        Http::assertNothingSent();
    }

    public function test_other_environments_stay_quiet(): void
    {
        Http::fake();
        config(['watchpost.environments' => ['production']]);

        $this->report(new RuntimeException('Lokal'));

        Http::assertNothingSent();
    }

    public function test_an_unreachable_watchpost_never_breaks_the_website(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->report(new RuntimeException('Tetap jalan'));

        $this->assertTrue(true);
    }

    public function test_the_website_adds_its_own_values_to_every_error(): void
    {
        Http::fake();
        Watchpost::context(fn () => ['error_id' => 'ERR-AB12CD34EF', 'company' => 'utama']);
        Watchpost::context(fn () => ['attempt' => 2, 'retried' => true, 'items' => ['tidak', 'teks'], 'long' => str_repeat('a', 300)]);

        $this->report(new RuntimeException('Stok tidak cukup'));

        $context = $this->sentError()['context'];

        $this->assertSame(['error_id', 'company', 'attempt', 'retried', 'long'], array_keys($context));
        $this->assertSame('ERR-AB12CD34EF', $context['error_id']);
        $this->assertSame('2', $context['attempt']);
        $this->assertSame('true', $context['retried']);
        $this->assertSame(255, strlen($context['long']));
    }

    public function test_a_failing_context_callback_never_costs_the_error_report(): void
    {
        Http::fake();
        Watchpost::context(fn () => throw new RuntimeException('Konteks rusak'));
        Watchpost::context(fn () => ['error_id' => 'ERR-AB12CD34EF']);

        $this->report(new RuntimeException('Stok tidak cukup'));

        $this->assertSame(['error_id' => 'ERR-AB12CD34EF'], $this->sentError()['context']);
    }

    public function test_an_error_without_website_values_has_no_context(): void
    {
        Http::fake();

        $this->report(new RuntimeException('Stok tidak cukup'));

        $this->assertNull($this->sentError()['context']);
    }
}
