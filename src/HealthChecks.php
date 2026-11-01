<?php

namespace Kisantra\Watchpost;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Quick checks sent with every heartbeat. Each one reports ok plus a number.
 */
class HealthChecks
{
    /**
     * @return array<string, array<string, bool|int|float|string>>
     */
    public function run(): array
    {
        $results = [];

        if (config('watchpost.health.database')) {
            $results['database'] = $this->timed(fn () => DB::connection()->select('select 1'));
        }

        if (config('watchpost.health.cache')) {
            $results['cache'] = $this->timed(function () {
                Cache::put('watchpost:health', 1, 10);

                if (Cache::get('watchpost:health') !== 1) {
                    throw new \RuntimeException('Cache tidak mengembalikan nilai yang disimpan.');
                }
            });
        }

        if (config('watchpost.health.queue.enabled') && config('queue.default') !== 'sync') {
            $results['queue'] = $this->queue();
        }

        if (config('watchpost.health.storage.enabled')) {
            $results['storage'] = $this->storage();
        }

        return $results;
    }

    /**
     * @return array<string, bool|int|float|string>
     */
    private function timed(callable $check): array
    {
        $started = hrtime(true);

        try {
            $check();

            return ['ok' => true, 'ms' => (int) round((hrtime(true) - $started) / 1_000_000)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function queue(): array
    {
        $limit = (int) config('watchpost.health.queue.limit', 100);

        try {
            $pending = Queue::connection(config('watchpost.health.queue.connection'))
                ->size(config('watchpost.health.queue.queue'));

            return ['ok' => $pending <= $limit, 'pending' => $pending, 'limit' => $limit];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }

    /**
     * @return array<string, bool|float|int>
     */
    private function storage(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if (! $free || ! $total) {
            return ['ok' => true];
        }

        $used = round(($total - $free) / $total * 100, 1);

        return ['ok' => $used < (int) config('watchpost.health.storage.limit', 90), 'used_percent' => $used];
    }
}
