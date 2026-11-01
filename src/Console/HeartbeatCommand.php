<?php

namespace Kisantra\Watchpost\Console;

use Illuminate\Console\Command;
use Kisantra\Watchpost\Client;
use Kisantra\Watchpost\HealthChecks;
use Kisantra\Watchpost\PayloadFactory;

class HeartbeatCommand extends Command
{
    protected $signature = 'watchpost:heartbeat';

    protected $description = 'Kirim heartbeat dan health check ke Watchpost';

    public function handle(Client $client, PayloadFactory $payloads, HealthChecks $health): int
    {
        if (! $client->enabled()) {
            $this->components->info('Watchpost tidak aktif di environment ini.');

            return self::SUCCESS;
        }

        $client->sendHeartbeat($payloads->heartbeat($health))
            ? $this->components->info('Heartbeat terkirim.')
            : $this->components->warn('Heartbeat gagal terkirim. Cek WATCHPOST_URL dan WATCHPOST_TOKEN.');

        // Never fail the scheduler because monitoring is unreachable.
        return self::SUCCESS;
    }
}
