<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
protected function schedule(Schedule $schedule): void
    {
        // --workers defaults to "all cores but one", read from nproc -- which
        // on Railway reports the HOST's core count (48), not this container's
        // actual cgroup allocation (~1GB RAM total). 47 parallel pandas/
        // statsmodels processes blew that budget and OOM-killed the container
        // mid-run. Sequential fitting is slower but the only thing this
        // container's memory can sustain unattended overnight.
        $schedule->command('forecast:generate --workers=1')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
