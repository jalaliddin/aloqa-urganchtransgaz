<?php

use Illuminate\Support\Facades\Schedule;

// Run by the `scheduler` container (php artisan schedule:work)
Schedule::command('app:scan-missing-documents')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
