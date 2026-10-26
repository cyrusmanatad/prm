<?php

use App\Services\BusinessSchedules;
use App\Services\NotificationOutbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('notifications:dispatch', function (NotificationOutbox $outbox) {
    $this->info('Queued '.$outbox->dispatch().' pending notifications.');
})->purpose('Reconcile and dispatch committed business notification intent');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping(2);

Artisan::command('automation:dispatch', function (BusinessSchedules $schedules) {
    $this->info('Queued '.$schedules->dispatch().' due schedules.');
})->purpose('Reconstruct due business schedules from PostgreSQL');

Schedule::command('automation:dispatch')->everyMinute()->withoutOverlapping(2);
