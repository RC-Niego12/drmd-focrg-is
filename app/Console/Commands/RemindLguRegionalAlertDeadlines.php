<?php

namespace App\Console\Commands;

use App\Models\RegionalAlert;
use App\Services\RegionalAlertNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RemindLguRegionalAlertDeadlines extends Command
{
    protected $signature = 'lgu-dromic:remind-alert-deadlines {--hours=2}';

    protected $description = 'Notify LGUs when the current OCD Caraga reporting deadline is approaching.';

    public function handle(RegionalAlertNotificationService $notifications): int
    {
        $alert = $notifications->ensureDefaultWhite();

        $now = CarbonImmutable::now();
        $windowEnd = $now->addHours(max(1, (int) $this->option('hours')));
        $deadlineHours = $alert->alert_level === 'white' ? ['14:00'] : ['10:00', '22:00'];
        $count = 0;

        foreach ($deadlineHours as $time) {
            $deadline = CarbonImmutable::parse($now->toDateString().' '.$time);
            if ($deadline->betweenIncluded($now, $windowEnd)) {
                $count += $notifications->notifyUpcomingDeadline($alert, $deadline);
            }
        }

        $this->info("Issued {$count} LGU deadline notification(s).");
        return self::SUCCESS;
    }
}
