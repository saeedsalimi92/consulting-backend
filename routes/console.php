<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('inspire', fn () => $this->comment('Keep going!'));
\Illuminate\Support\Facades\Schedule::command('booking:maintain')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('booking:schedule-sms-reminders')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('auth:prune-otp-challenges')->hourly()->withoutOverlapping();
