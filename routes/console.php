<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:expire-reservations')->everyMinute()->withoutOverlapping();

Schedule::command('inventory:flash-prime')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('outbox:prune')->daily();
Schedule::command('rabbitmq:prune-processed')->daily();

foreach (array_keys((array) config('rabbitmq.consumers', [])) as $queue) {
    Schedule::command('rabbitmq:dlq', ['status', $queue])->everyFiveMinutes();
}
Schedule::command('outbox:failed')->everyTenMinutes();
