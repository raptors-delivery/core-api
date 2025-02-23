<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Traits\PurgeCommand;
use Illuminate\Console\Command;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

class PurgeScheduledTaskLogs extends Command
{
    use PurgeCommand;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:scheduled-task-logs 
                            {--days=30 : The number of days to preserve logs (default: 30)} {--force : Force the command to run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purges Activity logs older than the specified number of days, with an option to back up records before deletion.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Determine the number of days to preserve
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $this->error('The number of days must be a positive integer.');

            return;
        }

        $this->info("Purging Scheduled Task logs older than {$days} days...");

        // Calculate the cutoff date
        $cutoffDate = now()->subDays($days);

        // Backup and purge logs
        $this->backupAndDelete(MonitoredScheduledTaskLogItem::class, 'monitored_scheduled_task_log_items', $cutoffDate, 'backups/scheduled-task-logs');

        $this->info('Purge completed successfully.');
    }
}
