<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'manage regional alerts',
            'guard_name' => 'web',
        ]);
        $role = Role::firstOrCreate(['name' => 'OCD Caraga', 'guard_name' => 'web']);
        $role->syncPermissions([$permission]);

        Schema::create('agency_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('agency_name');
            $table->string('acronym', 40)->nullable();
            $table->string('office_address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_designation')->nullable();
            $table->string('email')->nullable();
            $table->string('alternate_email')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('hotline_number')->nullable();
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('regional_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('set_by')->constrained('users');
            $table->enum('alert_level', ['white', 'blue', 'red'])->index();
            $table->string('incident_name')->nullable();
            $table->string('coverage')->default('Caraga Region');
            $table->text('reason');
            $table->timestamp('effective_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        $user = User::withTrashed()->firstOrNew(['username' => 'ocd-caraga']);
        if ($user->trashed()) {
            $user->restore();
        }
        $user->fill([
            'name' => 'OCD Caraga',
            'email' => 'ocd-caraga@dromis.local',
            'username' => 'ocd-caraga',
            'password' => Hash::make('password'),
            'office' => 'Office of Civil Defense Caraga',
            'position' => 'Regional Alert Administrator',
            'is_active' => true,
            'access_status' => 'approved',
            'access_approved_at' => now(),
        ])->save();
        $user->syncRoles([$role]);

        $user->agencyProfile()->create([
            'agency_name' => 'Office of Civil Defense Caraga',
            'acronym' => 'OCD Caraga',
            'email' => 'ocd-caraga@dromis.local',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('regional_alerts');
        Schema::dropIfExists('agency_profiles');

        User::withTrashed()->where('username', 'ocd-caraga')->forceDelete();
        Role::where('name', 'OCD Caraga')->delete();
        Permission::where('name', 'manage regional alerts')->delete();
    }
};
