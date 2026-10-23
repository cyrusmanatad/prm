<?php

use App\Services\NotificationOutbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('notifications:dispatch', function (NotificationOutbox $outbox) {
    $this->info('Queued '.$outbox->dispatch().' pending notifications.');
})->purpose('Reconcile and dispatch committed business notification intent');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(2);
