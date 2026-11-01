<?php

namespace Kisantra\Watchpost;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Kisantra\Watchpost\Console\HeartbeatCommand;
use Kisantra\Watchpost\Console\TestCommand;
use Throwable;

class WatchpostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchpost.php', 'watchpost');

        $this->app->singleton(Client::class);
        $this->app->singleton(PayloadFactory::class);
        $this->app->singleton(Reporter::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/watchpost.php' => config_path('watchpost.php'),
            ], 'watchpost-config');

            $this->commands([HeartbeatCommand::class, TestCommand::class]);
        }

        // Only exceptions Laravel itself would report reach this callback;
        // returning nothing lets Laravel keep logging them as usual.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $exception) {
                    $this->app->make(Reporter::class)->report($exception);
                });
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('watchpost.heartbeat')) {
                $schedule->command('watchpost:heartbeat')->everyMinute()->withoutOverlapping();
            }
        });
    }
}
