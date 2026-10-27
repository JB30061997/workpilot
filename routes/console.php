<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('action-plans:rollover')
    ->dailyAt('00:10')
    ->timezone('Africa/Casablanca')
    ->withoutOverlapping()
    ->onOneServer();
