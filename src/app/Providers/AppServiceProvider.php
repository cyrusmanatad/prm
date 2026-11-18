<?php

namespace App\Providers;

use App\Queue\SanitizedFailedJobProvider;
use App\Services\MailBudget;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(MessageSending::class, fn (MessageSending $event) => app(MailBudget::class)->sending($event));
        $this->app->extend('queue.failer', fn ($provider, $app) => config('queue.failed.driver') === 'database-uuids'
            ? new SanitizedFailedJobProvider($app['db'], config('queue.failed.database'), config('queue.failed.table'))
            : $provider);
    }
}
