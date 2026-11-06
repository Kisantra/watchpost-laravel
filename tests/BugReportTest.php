<?php

namespace Kisantra\Watchpost\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Kisantra\Watchpost\BugReport;
use Kisantra\Watchpost\Facades\Watchpost;
use Kisantra\Watchpost\TicketReceipt;

class BugReportTest extends TestCase
{
    private const TRACE = '6f1c2a5e-8d4b-4c3a-9e2f-1a2b3c4d5e6f';

    private const RECEIPT = ['ticket' => 12, 'number' => '#12', 'url' => 'https://watchpost.test/tickets/12', 'linked_to_error' => true];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.name', 'Finance Management');
        $app['config']->set('app.url', 'https://finance.example.test');
    }

    private function report(array $overrides = []): BugReport
    {
        return new BugReport(...[
            'reference' => 'utama:41',
            'title' => 'Tombol simpan tidak merespons',
            'description' => 'Klik Simpan, tidak terjadi apa-apa.',
            'reporterName' => 'Budi Santoso',
            'reporterEmail' => 'budi@example.test',
            'priority' => 'high',
            'company' => 'PT Maju',
            'pageUrl' => '/c/utama/invoices?month=2026-11&token=rahasia#invoice/12',
            'traceId' => self::TRACE,
            'errorId' => 'ERR-AB12CD34EF',
            'breadcrumbs' => [
                ['type' => 'page', 'label' => '/c/utama/invoices', 'at' => '2026-11-06T09:00:00+07:00'],
                ['type' => 'click', 'label' => 'Simpan', 'at' => '2026-11-06T09:00:05+07:00'],
            ],
            'userAgent' => 'Mozilla/5.0 Chrome/141.0',
            ...$overrides,
        ]);
    }

    private function sent(): ?Request
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->first(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/tickets'));
    }

    public function test_a_report_is_sent_with_the_platform_it_came_from_and_returns_its_ticket(): void
    {
        Http::fake(['watchpost.test/*' => Http::response(self::RECEIPT, 201)]);

        $receipt = Watchpost::reportBug($this->report());

        $this->assertEquals(new TicketReceipt(12, '#12', 'https://watchpost.test/tickets/12', true), $receipt);

        $request = $this->sent();
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer wp_test_token'));
        $this->assertTrue($request->isJson());
        $this->assertSame('utama:41', $request['reference']);
        $this->assertSame('high', $request['priority']);
        $this->assertSame(['name' => 'Budi Santoso', 'email' => 'budi@example.test'], $request['reporter']);
        $this->assertSame('PT Maju', $request['company']);
        $this->assertSame(self::TRACE, $request['trace_id']);
        $this->assertSame('ERR-AB12CD34EF', $request['error_id']);
        $this->assertCount(2, $request['breadcrumbs']);
        $this->assertSame([
            'app' => 'Finance Management',
            'url' => 'https://finance.example.test',
            'environment' => 'testing',
            'release' => 'a1f9c3e',
        ], $request['source']);
        // Secrets in the query never leave the server; the dialog in the fragment does.
        $this->assertSame('/c/utama/invoices?month=2026-11&token=%5Bdisaring%5D#invoice/12', $request['page_url']);
    }

    public function test_a_report_already_in_watchpost_returns_the_same_ticket(): void
    {
        Http::fake(['watchpost.test/*' => Http::response([...self::RECEIPT, 'linked_to_error' => false], 200)]);

        $this->assertSame('#12', Watchpost::reportBug($this->report())?->number);
    }

    public function test_an_attachment_is_uploaded_with_the_report_as_form_fields(): void
    {
        Http::fake(['watchpost.test/*' => Http::response(self::RECEIPT, 201)]);
        $path = tempnam(sys_get_temp_dir(), 'wp');
        file_put_contents($path, 'isi-gambar');

        try {
            Watchpost::reportBug($this->report(['attachmentPath' => $path, 'attachmentName' => 'layar.png']));
        } finally {
            unlink($path);
        }

        $request = $this->sent();
        $fields = collect($request->data())->pluck('contents', 'name');

        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('attachment', 'isi-gambar', 'layar.png'));
        $this->assertSame('Budi Santoso', $fields['reporter[name]']);
        $this->assertSame('click', $fields['breadcrumbs[1][type]']);
        $this->assertSame('Finance Management', $fields['source[app]']);
        $this->assertFalse($fields->has('attachmentPath'));
    }

    public function test_a_missing_attachment_does_not_stop_the_report(): void
    {
        Http::fake(['watchpost.test/*' => Http::response(self::RECEIPT, 201)]);

        $this->assertNotNull(Watchpost::reportBug($this->report(['attachmentPath' => '/tidak/ada.png'])));
        $this->assertTrue($this->sent()->isJson());
    }

    public function test_an_undelivered_report_returns_null_so_the_website_can_retry(): void
    {
        Http::fakeSequence('watchpost.test/*')
            ->push(['message' => 'Server error'], 500)
            ->push(['message' => 'The given data was invalid.'], 422)
            ->push('<html>bukan Watchpost</html>', 201);

        foreach (range(1, 3) as $attempt) {
            $this->assertNull(Watchpost::reportBug($this->report()), "Percobaan {$attempt}");
        }
    }

    public function test_nothing_is_sent_where_watchpost_does_not_report_or_reports_are_off(): void
    {
        Http::fake();

        config(['watchpost.environments' => ['production']]);
        $this->assertFalse(Watchpost::reportsBugs());
        $this->assertNull(Watchpost::reportBug($this->report()));

        config(['watchpost.environments' => ['testing'], 'watchpost.tickets' => false]);
        $this->assertFalse(Watchpost::reportsBugs());
        $this->assertNull(Watchpost::reportBug($this->report()));

        Http::assertNothingSent();
    }

    public function test_values_watchpost_would_refuse_are_dropped_instead_of_losing_the_report(): void
    {
        Http::fake(['watchpost.test/*' => Http::response(self::RECEIPT, 201)]);

        Watchpost::reportBug($this->report([
            'reporterEmail' => 'bukan-email',
            'traceId' => 'bukan-uuid',
            'title' => str_repeat('a', 400),
            'breadcrumbs' => [
                ['type' => 'page', 'label' => '/ok', 'at' => '2026-11-06T09:00:00+07:00'],
                ['type' => 'scroll', 'label' => 'x', 'at' => '2026-11-06T09:00:00+07:00'],
                ['type' => 'click', 'label' => ['bukan teks'], 'at' => '2026-11-06T09:00:00+07:00'],
                'bukan langkah',
            ],
        ]));

        $request = $this->sent();
        $this->assertNull($request['reporter']['email']);
        $this->assertNull($request['trace_id']);
        $this->assertSame(255, mb_strlen($request['title']));
        $this->assertSame([['type' => 'page', 'label' => '/ok', 'at' => '2026-11-06T09:00:00+07:00']], $request['breadcrumbs']);
    }

    public function test_an_unknown_priority_is_a_mistake_in_the_website(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pakai salah satu: low, normal, high, urgent.');

        $this->report(['priority' => 'medium']);
    }

    public function test_the_trace_id_of_a_web_request_is_available_to_its_error_page(): void
    {
        $this->assertNull(Watchpost::traceId());

        Route::middleware('web')->get('report-button', fn () => ['trace' => Watchpost::traceId()]);

        $this->get('/report-button', ['X-Watchpost-Trace' => self::TRACE])->assertExactJson(['trace' => self::TRACE]);
    }

    public function test_a_connection_error_never_escapes(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertNull(Watchpost::reportBug($this->report()));
    }
}
