<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Release stock held by unpaid orders, and tidy abandoned guest carts.
Schedule::command('orders:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('carts:prune')->daily();
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
