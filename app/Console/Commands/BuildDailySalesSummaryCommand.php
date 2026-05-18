<?php

namespace App\Console\Commands;

use App\Jobs\BuildDailySalesSummaryJob;
use Illuminate\Console\Command;

class BuildDailySalesSummaryCommand extends Command
{
    protected $signature = 'sales:summarize-daily {date? : Date in YYYY-MM-DD format} {--sync : Run immediately instead of queueing}';

    protected $description = 'Build daily sales summary using chunked background processing.';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $job = new BuildDailySalesSummaryJob($date);

        if ($this->option('sync')) {
            $job->handle();
            $this->info("Daily sales summary built for {$date}.");

            return self::SUCCESS;
        }

        dispatch($job);
        $this->info("Daily sales summary queued for {$date}.");

        return self::SUCCESS;
    }
}
