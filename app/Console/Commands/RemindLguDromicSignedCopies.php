<?php

namespace App\Console\Commands;

use App\Models\AssistanceRequest;
use App\Services\WorkflowNotificationService;
use Illuminate\Console\Command;

class RemindLguDromicSignedCopies extends Command
{
    protected $signature = 'lgu-dromic:remind-signed-copies {--days=3 : Minimum days between reminders}';

    protected $description = 'Remind LGUs about signed copies still required for advance DROMIC submissions';

    public function handle(WorkflowNotificationService $notifications): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = 0;

        AssistanceRequest::query()
            ->with(['encoder', 'lguSubmitter'])
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->where('lgu_report_status', 'advance_submitted')
            ->where(function ($query) use ($days): void {
                $query->whereNull('lgu_signed_copy_reminder_sent_at')
                    ->orWhere('lgu_signed_copy_reminder_sent_at', '<=', now()->subDays($days));
            })
            ->chunkById(100, function ($requests) use ($notifications, &$count): void {
                foreach ($requests as $request) {
                    $notifications->notifyLguSignedCopiesRequired($request);
                    $notifications->notifyDromicSignedCopiesStillPending($request);
                    $request->update(['lgu_signed_copy_reminder_sent_at' => now()]);
                    $count++;
                }
            });

        $this->info("Sent {$count} signed-copy reminder(s).");

        return self::SUCCESS;
    }
}
