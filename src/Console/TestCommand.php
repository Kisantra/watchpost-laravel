<?php

namespace Kisantra\Watchpost\Console;

use Illuminate\Console\Command;
use Kisantra\Watchpost\Client;
use Kisantra\Watchpost\Exceptions\TestException;
use Kisantra\Watchpost\HealthChecks;
use Kisantra\Watchpost\PayloadFactory;

class TestCommand extends Command
{
    protected $signature = 'watchpost:test';

    protected $description = 'Cek koneksi ke Watchpost dengan heartbeat dan satu error uji';

    public function handle(Client $client, PayloadFactory $payloads, HealthChecks $health): int
    {
        foreach (['url' => 'WATCHPOST_URL', 'token' => 'WATCHPOST_TOKEN'] as $key => $env) {
            if (blank(config("watchpost.{$key}"))) {
                $this->components->error("{$env} belum diisi di .env.");

                return self::FAILURE;
            }
        }

        if (! $client->enabled()) {
            $this->components->warn('Environment "'.app()->environment().'" tidak ada di WATCHPOST_ENVIRONMENTS; laporan otomatis tidak dikirim di sini. Uji tetap dijalankan.');
        }

        $heartbeat = $client->sendHeartbeat($payloads->heartbeat($health));
        $this->components->twoColumnDetail('Heartbeat + health check', $heartbeat ? '<fg=green>terkirim</>' : '<fg=red>gagal</>');

        $error = $client->sendError($payloads->fromException(new TestException('Pesan uji dari php artisan watchpost:test')));
        $this->components->twoColumnDetail('Error uji', $error ? '<fg=green>terkirim</>' : '<fg=red>gagal</>');

        if (! $heartbeat || ! $error) {
            $this->components->error('Gagal. Pastikan URL benar, token sesuai, dan website tidak dijeda di Watchpost.');

            return self::FAILURE;
        }

        $this->components->info('Berhasil. Error uji muncul di Watchpost; tandai selesai atau abaikan setelah dicek.');

        return self::SUCCESS;
    }
}
