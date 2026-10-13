<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

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

        // ONE run as soon as this is deployed (13 October 2026, at the user's
        // request: "run the live forecast now"), so the live site shows the
        // SARIMA-only model (caf7d28) before 1 November. The first scheduler
        // tick after the deploy claims the flag (Cache::add is atomic; the cache
        // is the shared database on Railway, so it fires once across restarts),
        // with its OWN mutex name so a lock left by an earlier run cannot skip
        // it. Only until 14 October 06:00. Safe to delete after.
        $schedule->command('forecast:generate --workers=1')
            ->everyMinute()
            // _b: started again by the 19:3x deploy that put the accuracy card
            // back, which restarted the container mid-run -- own flag and lock.
            ->when(fn () => now()->lt('2026-10-14 06:00:00')
                && Cache::add('forecast_rerun_2026_10_13_b', now()->toDateTimeString(), now()->addDays(30)))
            ->createMutexNameUsing('forecast-rerun-2026-10-13-b')
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
