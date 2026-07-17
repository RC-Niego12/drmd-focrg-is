<?php

use App\Models\User;
use App\Notifications\AccessRequestedNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $superAdminIds = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Super Admin')
            ->where('users.is_active', true)
            ->pluck('users.id');

        $pendingUsers = DB::table('users')
            ->where('access_status', 'pending')
            ->whereNotNull('requested_role')
            ->get(['id', 'name', 'requested_role', 'access_requested_at']);

        foreach ($superAdminIds as $adminId) {
            foreach ($pendingUsers as $pendingUser) {
                $existingId = DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $adminId)
                    ->where('type', AccessRequestedNotification::class)
                    ->where('data', 'like', '%"access_user_id":'.$pendingUser->id.'%')
                    ->latest('created_at')
                    ->value('id');

                if ($existingId) {
                    DB::table('notifications')->where('id', $existingId)->update([
                        'read_at' => null,
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => AccessRequestedNotification::class,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $adminId,
                    'data' => json_encode([
                        'kind' => 'access_requested',
                        'title' => 'Pending system access request',
                        'message' => $pendingUser->name.' requested '.$pendingUser->requested_role.' access.',
                        'access_user_id' => $pendingUser->id,
                        'requested_role' => $pendingUser->requested_role,
                        'url' => '/access-management',
                    ]),
                    'read_at' => null,
                    'created_at' => $pendingUser->access_requested_at ?: now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Historical access alerts are retained as audit-friendly user notifications.
    }
};
