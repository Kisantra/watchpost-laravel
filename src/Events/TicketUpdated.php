<?php

namespace Kisantra\Watchpost\Events;

/**
 * A ticket holding this website's bug reports changed in Watchpost (status,
 * assignment, a comment). Fired by watchpost:sync-tickets; listen to it to
 * update the reports and tell the people who sent them.
 *
 * The same ticket can arrive more than once with the same status: compare
 * before acting.
 */
class TicketUpdated
{
    /**
     * @param  string  $status  open, in_progress, resolved, or ignored
     * @param  list<string>  $references  this website's references (BugReport::$reference) on the ticket
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly string $number,
        public readonly string $url,
        public readonly string $status,
        public readonly array $references,
        public readonly ?string $resolvedAt = null,
    ) {}
}
