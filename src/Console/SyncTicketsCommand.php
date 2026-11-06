<?php

namespace Kisantra\Watchpost\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Kisantra\Watchpost\Client;
use Kisantra\Watchpost\Events\TicketUpdated;
use Kisantra\Watchpost\Watchpost;
use Throwable;

/**
 * Asks Watchpost which tickets of this website's bug reports changed, and
 * fires TicketUpdated for each. Runs every minute from the scheduler, and
 * only calls Watchpost when the website listens to that event.
 */
class SyncTicketsCommand extends Command
{
    public const CURSOR_KEY = 'watchpost:tickets:since';

    /** Watchpost answers with at most this many tickets per call. */
    private const PAGE_SIZE = 200;

    /** Pages per run; anything left waits for the next minute. */
    private const MAX_PAGES = 10;

    protected $signature = 'watchpost:sync-tickets';

    protected $description = 'Ambil perubahan status tiket laporan bug dari Watchpost';

    public function handle(Client $client, Watchpost $watchpost): int
    {
        if (! $watchpost->reportsBugs()) {
            $this->components->info('Laporan bug tidak dikirim dari environment ini.');

            return self::SUCCESS;
        }

        if (! Event::hasListeners(TicketUpdated::class)) {
            $this->components->info('Tidak ada listener TicketUpdated di website ini; Watchpost tidak ditanya.');

            return self::SUCCESS;
        }

        $updated = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $client->fetchTicketUpdates(Cache::get(self::CURSOR_KEY));

            if ($answer === null) {
                $this->components->warn('Watchpost tidak bisa ditanya. Dicoba lagi menit berikutnya.');

                // Never fail the scheduler because monitoring is unreachable.
                return self::SUCCESS;
            }

            foreach ($answer['tickets'] as $ticket) {
                $this->dispatch($ticket);
                $updated++;
            }

            // Only after the listeners ran: a crash before this repeats the page, not skips it.
            Cache::forever(self::CURSOR_KEY, $answer['next_since']);

            if (count($answer['tickets']) < self::PAGE_SIZE) {
                break;
            }
        }

        $this->components->info("{$updated} tiket diperbarui.");

        return self::SUCCESS;
    }

    /**
     * A listener that fails is reported like any other error and the rest go
     * on: one broken report must not stop every other one from updating.
     *
     * @param  array{ticket: int, number: string, url: string, status: string, resolved_at: string|null, references: list<string>}  $ticket
     */
    private function dispatch(array $ticket): void
    {
        try {
            event(new TicketUpdated(
                $ticket['ticket'],
                $ticket['number'],
                $ticket['url'],
                $ticket['status'],
                $ticket['references'],
                $ticket['resolved_at'],
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
