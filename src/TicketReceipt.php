<?php

namespace Kisantra\Watchpost;

/**
 * Where a bug report landed in Watchpost.
 */
class TicketReceipt
{
    public function __construct(
        public readonly int $id,
        // "#12", the way the ticket is named in Watchpost and on Telegram.
        public readonly string $number,
        public readonly string $url,
        // True when the report joined the ticket of the error it was sent from.
        public readonly bool $linkedToError,
    ) {}
}
