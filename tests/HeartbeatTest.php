<?php

namespace Kisantra\Watchpost\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class HeartbeatTest extends TestCase
{
    public function test_the_heartbeat_carries_health_checks(): void
    {
        Http::fake();

        $this->artisan('watchpost:heartbeat')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://watchpost.test/api/ingest/heartbeat'
                && $request['release'] === 'a1f9c3e'
                && $request['health']['database']['ok'] === true
                && $request['health']['cache']['ok'] === true
                && array_key_exists('storage', $request['health'])
                && ! array_key_exists('queue', $request['health']);
        });
    }

    public function test_a_full_queue_is_reported_as_a_problem(): void
    {
        Http::fake();
        config(['queue.default' => 'database', 'watchpost.health.queue.limit' => 0]);
        $this->app['db']->connection()->getSchemaBuilder()->create('jobs', function ($table) {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->app['db']->table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        $this->artisan('watchpost:heartbeat')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request['health']['queue'] === ['ok' => false, 'pending' => 1, 'limit' => 0]);
    }

    public function test_an_unreachable_watchpost_does_not_fail_the_scheduler(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->artisan('watchpost:heartbeat')->assertSuccessful();
    }

    public function test_the_heartbeat_is_scheduled_every_minute(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'watchpost:heartbeat'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_the_setup_check_sends_a_heartbeat_and_a_test_error(): void
    {
        Http::fake([
            '*/api/ingest/heartbeat' => Http::response(null, 204),
            '*/api/ingest/errors' => Http::response(['group' => 1, 'status' => 'open'], 202),
        ]);

        $this->artisan('watchpost:test')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/ingest/errors')
            && $request['exception'] === 'Kisantra\Watchpost\Exceptions\TestException');
    }

    public function test_a_wrong_url_that_serves_a_normal_page_is_not_counted_as_success(): void
    {
        Http::fake(['*' => Http::response('<html>Website lain</html>', 200)]);

        $this->artisan('watchpost:test')
            ->expectsOutputToContain('Gagal')
            ->assertFailed();
    }

    public function test_the_setup_check_explains_a_missing_token(): void
    {
        config(['watchpost.token' => null]);

        $this->artisan('watchpost:test')
            ->expectsOutputToContain('WATCHPOST_TOKEN belum diisi')
            ->assertFailed();
    }
}
