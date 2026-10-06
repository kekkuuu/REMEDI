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
        // --workers=1 is also this command's own default now (see
        // GenerateDemandForecast::handle()'s docblock) -- kept explicit here
        // because this is the one invocation nobody is present to notice fail.
        // The command used to default to "auto", read from os.cpu_count() --
        // which on Railway reports the HOST's core count (48), not this
        // container's actual cgroup allocation (~1GB RAM total). 47 parallel
        // pandas/statsmodels processes blew that budget and OOM-killed the
        // container mid-run. Sequential fitting is slower but the only thing
        // this container's memory can sustain unattended overnight.
        //
        // MONTHLY, on the 1st (2026-10-06, at the user's request; was nightly).
        // The models train on COMPLETE months only (monthly_series() drops the
        // month in progress), so the data they see changes once a month -- on
        // the 1st, when last month closes. A nightly run refitted the same data.
        $schedule->command('forecast:generate --workers=1')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->runInBackground();

        // The sales (units + revenue) forecast, nightly as of 2026-10-03 at the
        // user's request -- it used to be manual, so the live sales forecast
        // only moved when someone ran it by hand on the container. 04:30 leaves
        // the sequential demand run above room to finish first: the two must
        // not fit side by side in this container's ~1GB. --workers=1 for the
        // same reason as above.
        // Monthly too, on the 1st after the demand run (2026-10-06).
        $schedule->command('sales-forecast:generate --workers=1')
            ->monthlyOn(1, '04:30')
            ->withoutOverlapping()
            ->runInBackground();

        // ONE extra run, 7 October 2026 (at the user's request): the forecast
        // fallback (caabef4) went live after that night's last nightly run, and
        // the schedule is monthly now, so without this the live site would keep
        // the old model's forecasts until 1 November. Same times and limits as
        // above; the year check stops it repeating in 2027. Safe to delete after.
        $once = fn () => now()->year === 2026;
        $schedule->command('forecast:generate --workers=1')
            ->cron('0 2 7 10 *')
            ->when($once)
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('sales-forecast:generate --workers=1')
            ->cron('30 4 7 10 *')
            ->when($once)
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
