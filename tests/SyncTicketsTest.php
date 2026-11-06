<?php

namespace Kisantra\Watchpost\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Kisantra\Watchpost\Console\SyncTicketsCommand;
use Kisantra\Watchpost\Events\TicketUpdated;
use RuntimeException;

class SyncTicketsTest extends TestCase
{
    /** @var list<TicketUpdated> */
    private array $heard = [];

    private function listen(): void
    {
        Event::listen(TicketUpdated::class, function (TicketUpdated $event) {
            $this->heard[] = $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(int $id, string $status, array $references): array
    {
        return [
            'ticket' => $id,
            'number' => '#'.$id,
            'url' => "https://watchpost.test/tickets/{$id}",
            'status' => $status,
            'resolved_at' => $status === 'resolved' ? '2026-11-06T10:30:00+00:00' : null,
            'updated_at' => '2026-11-06T10:30:00+00:00',
            'references' => $references,
        ];
    }

    /**
     * @return list<string|null>
     */
    private function sinceAsked(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/api/ingest/tickets/updates'))
            ->map(fn (Request $request) => $request->data()['since'] ?? null)
            ->values()
            ->all();
    }

    public function test_each_changed_ticket_becomes_an_event_and_the_cursor_moves_on(): void
    {
        $this->listen();
        Http::fakeSequence('watchpost.test/*')
            ->push(['tickets' => [$this->ticket(12, 'resolved', ['feedback-41', 'feedback-42'])], 'next_since' => '2026-11-06T10:31:00+00:00'])
            ->push(['tickets' => [], 'next_since' => '2026-11-06T10:32:00+00:00']);

        $this->artisan('watchpost:sync-tickets')->expectsOutputToContain('1 tiket diperbarui.')->assertSuccessful();
        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        $this->assertEquals([new TicketUpdated(12, '#12', 'https://watchpost.test/tickets/12', 'resolved', ['feedback-41', 'feedback-42'], '2026-11-06T10:30:00+00:00')], $this->heard);
        $this->assertSame([null, '2026-11-06T10:31:00+00:00'], $this->sinceAsked());
        $this->assertSame('2026-11-06T10:32:00+00:00', Cache::get(SyncTicketsCommand::CURSOR_KEY));

        $request = collect(Http::recorded())->first()[0];
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer wp_test_token'));
    }

    public function test_a_full_page_is_followed_by_the_next_one_in_the_same_run(): void
    {
        $this->listen();
        $page = array_map(fn (int $id) => $this->ticket($id, 'open', ["feedback-{$id}"]), range(1, 200));
        Http::fakeSequence('watchpost.test/*')
            ->push(['tickets' => $page, 'next_since' => '2026-11-06T10:30:00+00:00'])
            ->push(['tickets' => [$this->ticket(201, 'open', ['feedback-201'])], 'next_since' => '2026-11-06T10:31:00+00:00']);

        $this->artisan('watchpost:sync-tickets')->expectsOutputToContain('201 tiket diperbarui.')->assertSuccessful();

        $this->assertSame([null, '2026-11-06T10:30:00+00:00'], $this->sinceAsked());
    }

    public function test_watchpost_is_not_asked_when_the_website_does_not_listen(): void
    {
        Http::fake();

        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_watchpost_is_not_asked_where_bug_reports_are_not_sent(): void
    {
        $this->listen();
        Http::fake();

        config(['watchpost.environments' => ['production']]);
        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        config(['watchpost.environments' => ['testing'], 'watchpost.tickets' => false]);
        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_watchpost_keeps_the_cursor_for_the_next_minute(): void
    {
        $this->listen();
        Cache::forever(SyncTicketsCommand::CURSOR_KEY, '2026-11-06T10:00:00+00:00');
        Http::fakeSequence('watchpost.test/*')
            ->push(['message' => 'Server error'], 500)
            ->push('<html>bukan Watchpost</html>', 200);

        $this->artisan('watchpost:sync-tickets')->expectsOutputToContain('Dicoba lagi menit berikutnya.')->assertSuccessful();
        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        $this->assertSame([], $this->heard);
        $this->assertSame('2026-11-06T10:00:00+00:00', Cache::get(SyncTicketsCommand::CURSOR_KEY));
    }

    public function test_a_failing_listener_is_reported_and_the_other_tickets_still_update(): void
    {
        $this->listen();
        Event::listen(TicketUpdated::class, function (TicketUpdated $event) {
            if ($event->ticketId === 1) {
                throw new RuntimeException('Feedback rusak');
            }
        });
        Http::fake([
            'watchpost.test/api/ingest/tickets/updates*' => Http::response([
                'tickets' => [$this->ticket(1, 'resolved', ['feedback-1']), $this->ticket(2, 'resolved', ['feedback-2'])],
                'next_since' => '2026-11-06T10:31:00+00:00',
            ]),
            'watchpost.test/*' => Http::response(['group' => 1, 'status' => 'open'], 202),
        ]);

        $this->artisan('watchpost:sync-tickets')->assertSuccessful();

        $this->assertSame([1, 2], array_map(fn (TicketUpdated $event) => $event->ticketId, $this->heard));
        $this->assertSame('2026-11-06T10:31:00+00:00', Cache::get(SyncTicketsCommand::CURSOR_KEY));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/errors')
            && $request['message'] === 'Feedback rusak');
    }

    public function test_the_sync_runs_every_minute_from_the_scheduler(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())->map(fn ($event) => $event->command);

        $this->assertTrue($commands->contains(fn ($command) => str_contains((string) $command, 'watchpost:sync-tickets')));
    }
}
