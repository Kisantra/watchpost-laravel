<?php

namespace Kisantra\Watchpost;

use Kisantra\Watchpost\Http\InjectBrowserScript;
use Throwable;

/**
 * What a website can ask of Watchpost itself, beyond the automatic error
 * reports and heartbeats: forward a user's bug report, read the current
 * request's trace id, and add its own values to every error.
 */
class Watchpost
{
    public function __construct(
        private Client $client,
        private PayloadFactory $payloads,
    ) {}

    /**
     * Whether bug reports are sent from here: Watchpost is set up for this
     * environment and WATCHPOST_TICKETS is not switched off. Check it before
     * queueing a report, so a local or staging copy does not keep retrying.
     */
    public function reportsBugs(): bool
    {
        return $this->client->enabled() && (bool) config('watchpost.tickets', true);
    }

    /**
     * Sends a bug report and returns the ticket it landed on, or null when it
     * was not delivered (Watchpost unreachable, the payload refused, or
     * reporting switched off). Never throws.
     *
     * The upload can take a few seconds, so call it from a queued job and
     * retry on null. Sending the same reference again is safe: Watchpost
     * returns the ticket it already made.
     */
    public function reportBug(BugReport $report): ?TicketReceipt
    {
        if (! $this->reportsBugs()) {
            return null;
        }

        $data = $this->client->sendTicket($this->payloads->ticket($report), $report->attachmentPath, $report->attachmentName);

        return $data === null ? null : new TicketReceipt(
            $data['ticket'],
            $data['number'],
            $data['url'],
            $data['linked_to_error'],
        );
    }

    /**
     * The current web request's trace id, the one its error and screenshot
     * carry in Watchpost. Put it on an error page's "report" button so the
     * report joins that error. Null outside a web request.
     */
    public function traceId(): ?string
    {
        try {
            $trace = app()->bound('request') ? request()->attributes->get(InjectBrowserScript::TRACE_ATTRIBUTE) : null;

            return is_string($trace) ? $trace : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Adds values to every error this website reports, such as the error code
     * its error page shows. At most 20 values, as text of 255 characters.
     *
     * @param  callable(): array<string, mixed>  $callback
     */
    public function context(callable $callback): void
    {
        $this->payloads->addContext($callback);
    }
}
