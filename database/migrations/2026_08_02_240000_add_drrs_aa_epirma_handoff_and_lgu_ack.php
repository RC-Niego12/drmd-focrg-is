<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'epirma_forwarded_to_drrs_aa_at')) {
                $table->timestamp('epirma_forwarded_to_drrs_aa_at')->nullable()->after('epirma_signed_at');
            }
            if (! Schema::hasColumn('requests', 'epirma_forwarded_by')) {
                $table->foreignId('epirma_forwarded_by')->nullable()->after('epirma_forwarded_to_drrs_aa_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'epirma_aa_status')) {
                $table->string('epirma_aa_status', 32)->nullable()->after('epirma_forwarded_by')->index();
            }
            if (! Schema::hasColumn('requests', 'epirma_assessment_signed_at')) {
                $table->timestamp('epirma_assessment_signed_at')->nullable()->after('epirma_aa_status');
            }
            if (! Schema::hasColumn('requests', 'epirma_response_letter_signed_at')) {
                $table->timestamp('epirma_response_letter_signed_at')->nullable()->after('epirma_assessment_signed_at');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_sent_at')) {
                $table->timestamp('lgu_response_letter_sent_at')->nullable()->after('epirma_response_letter_signed_at');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_acked_at')) {
                $table->timestamp('lgu_response_letter_acked_at')->nullable()->after('lgu_response_letter_sent_at');
            }
            if (! Schema::hasColumn('requests', 'lgu_response_letter_acked_by')) {
                $table->foreignId('lgu_response_letter_acked_by')->nullable()->after('lgu_response_letter_acked_at')->constrained('users')->nullOnDelete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'view dashboards', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'view regional alert acknowledgements', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'route epirma documents', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'DRRS AA', 'guard_name' => 'web']);
        $role->syncPermissions(['view dashboards', 'route epirma documents', 'view regional alert acknowledgements']);

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin && ! $superAdmin->hasPermissionTo('route epirma documents')) {
            $superAdmin->givePermissionTo($permission);
        }

        $user = User::firstOrCreate(
            ['email' => 'drrs-aa@example.test'],
            [
                'name' => 'DRRS AA Officer',
                'office' => 'DRRS AA',
                'position' => 'Administrative Aide',
                'password' => Hash::make('password'),
                'is_active' => true,
                'access_status' => 'approved',
                'access_approved_at' => now(),
            ]
        );
        $user->forceFill([
            'access_status' => 'approved',
            'access_approved_at' => $user->access_approved_at ?: now(),
            'is_active' => true,
        ])->save();
        $user->syncRoles([$role]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            foreach ([
                'lgu_response_letter_acked_by',
                'epirma_forwarded_by',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach ([
                'epirma_forwarded_to_drrs_aa_at',
                'epirma_aa_status',
                'epirma_assessment_signed_at',
                'epirma_response_letter_signed_at',
                'lgu_response_letter_sent_at',
                'lgu_response_letter_acked_at',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::where('name', 'DRRS AA')->first();
        $role?->syncPermissions([]);
        $role?->delete();
        Permission::where('name', 'route epirma documents')->delete();
    }
};
