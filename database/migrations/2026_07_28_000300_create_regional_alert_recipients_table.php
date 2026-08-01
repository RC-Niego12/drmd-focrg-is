<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regional_alert_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regional_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_category', 20);
            $table->string('recipient_name');
            $table->string('recipient_role')->nullable();
            $table->string('office')->nullable();
            $table->string('lgu_name')->nullable();
            $table->timestamp('notified_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('acknowledgement_method', 40)->nullable();
            $table->timestamps();

            $table->unique(['regional_alert_id', 'user_id']);
            $table->index(['regional_alert_id', 'acknowledged_at']);
            $table->index(['user_id', 'acknowledged_at', 'superseded_at'], 'regional_alert_recipient_pending_idx');
        });

        $permission = Permission::firstOrCreate([
            'name' => 'view regional alert acknowledgements',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['Super Admin', 'OCD Caraga', 'DRMD Chief', 'DRMD AA', 'DRRS', 'DRIMS', 'RROS', 'DRMD Financial Analyst', 'QRT', 'Quick Response Team'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_alert_recipients');
        Permission::where('name', 'view regional alert acknowledgements')->delete();
    }
};
