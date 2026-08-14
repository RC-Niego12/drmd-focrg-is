<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $assignRisDrn = Permission::firstOrCreate([
            'name' => 'assign ris drn',
            'guard_name' => 'web',
        ]);
        Permission::firstOrCreate([
            'name' => 'manage dispatches',
            'guard_name' => 'web',
        ]);

        $rros = Role::query()->where('name', 'RROS')->where('guard_name', 'web')->first();
        if ($rros) {
            $rros->givePermissionTo(['assign ris drn', 'manage dispatches']);
        }

        $rrosAa = Role::query()->where('name', 'RROS AA')->where('guard_name', 'web')->first();
        if ($rrosAa) {
            $rrosAa->givePermissionTo(['assign ris drn', 'manage dispatches']);
        }

        Schema::create('stf_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger', 30);
            $table->string('status', 30)->index();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('items_synced')->default(0);
            $table->json('changes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('stf_sync_runs');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $rros = Role::query()->where('name', 'RROS')->where('guard_name', 'web')->first();
        if ($rros) {
            $rros->revokePermissionTo('assign ris drn');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
