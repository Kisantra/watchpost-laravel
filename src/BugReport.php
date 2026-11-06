<?php

namespace Kisantra\Watchpost;

use InvalidArgumentException;

/**
 * A bug report a user of this website filed, to become a ticket in Watchpost.
 *
 * Only the reference, title, description and reporter are required. The rest
 * is context that helps the team: with a trace id from the error page, the
 * report joins the ticket of that error instead of opening a new one.
 */
class BugReport
{
    /** Watchpost's own priorities; map the website's terms onto these. */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /**
     * @param  string  $reference  this website's id for the report (unique here); sending it again is safe
     * @param  string|null  $company  the user's company, on websites that serve several
     * @param  string|null  $pageUrl  path, query and fragment of the page the user was on
     * @param  string|null  $traceId  from Watchpost::traceId() on the error page the user reported from
     * @param  string|null  $errorId  the error code the website showed the user, if it shows one
     * @param  list<array{type: string, label: string, at: string}>  $breadcrumbs  from window.Watchpost.trail()
     * @param  string|null  $attachmentPath  absolute path of a JPG, PNG or PDF of at most 5 MB
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $title,
        public readonly string $description,
        public readonly string $reporterName,
        public readonly ?string $reporterEmail = null,
        public readonly string $priority = 'normal',
        public readonly ?string $company = null,
        public readonly ?string $pageUrl = null,
        public readonly ?string $traceId = null,
        public readonly ?string $errorId = null,
        public readonly array $breadcrumbs = [],
        public readonly ?string $userAgent = null,
        public readonly ?string $attachmentPath = null,
        public readonly ?string $attachmentName = null,
    ) {
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                "Prioritas \"{$priority}\" tidak dikenal Watchpost. Pakai salah satu: ".implode(', ', self::PRIORITIES).'.'
            );
        }
    }
}
