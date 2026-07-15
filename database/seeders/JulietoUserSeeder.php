<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class JulietoUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::find(4) ?? Role::where('name', 'DRRS')->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => 'jlompad@dswd.gov.ph'],
            [
                'name' => 'Julieto',
                'username' => 'jlompad',
                'id_number' => '16-10859',
                'office' => $role->name,
                'position' => 'System User',
                'password' => Hash::make('password'),
                'is_active' => true,
                'access_status' => 'approved',
                'access_approved_at' => now(),
            ]
        );

        $user->forceFill([
            'name' => 'Julieto',
            'username' => 'jlompad',
            'id_number' => '16-10859',
            'office' => $role->name,
            'is_active' => true,
            'access_status' => 'approved',
            'access_approved_at' => $user->access_approved_at ?: now(),
        ])->save();

        $user->syncRoles([$role]);
    }
}
