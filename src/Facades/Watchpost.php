<?php

namespace Kisantra\Watchpost\Facades;

use Illuminate\Support\Facades\Facade;
use Kisantra\Watchpost\BugReport;
use Kisantra\Watchpost\TicketReceipt;

/**
 * @method static bool reportsBugs()
 * @method static TicketReceipt|null reportBug(BugReport $report)
 * @method static string|null traceId()
 * @method static void context(callable $callback)
 *
 * @see \Kisantra\Watchpost\Watchpost
 */
class Watchpost extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kisantra\Watchpost\Watchpost::class;
    }
}
