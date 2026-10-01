<?php

use App\Models\User;
use App\Services\SSOAuthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('parses MyPortal employee search payloads that nest matches under data.results', function (): void {
    config()->set('services.cc_idp.myportal_employee_search_url', 'https://caraga-connect.example.test/api/v1/staff/portal/employee/search');
    config()->set('services.cc_idp.myportal_access_token', 'directory-token');
    config()->set('services.cc_idp.verify_ssl', false);

    Http::fake([
        'https://caraga-connect.example.test/api/v1/staff/portal/employee/search*' => Http::response([
            'status' => 'success',
            'data' => [
                'count' => 2,
                'results' => [
                    [
                        'id_number' => '16-12491',
                        'fullname' => 'Ricardo A. Buladaco',
                        'first_name' => 'RICARDO',
                        'last_name' => 'BULADACO',
                        'position' => 'Administrative Aide IV',
                        'contact' => '09098116653',
                        'division' => 'Administrative Division',
                        'section' => 'General Services Section',
                        'status' => 'Active',
                    ],
                    [
                        'id_number' => '16-12619',
                        'fullname' => 'Shiloh Mae B. Catubig',
                        'first_name' => 'Shiloh Mae',
                        'middle_name' => 'Buladaco',
                        'last_name' => 'Catubig',
                        'position' => 'Project Development Officer II',
                        'contact' => '09709184831',
                        'status' => 'Active',
                    ],
                ],
            ],
        ], 200),
    ]);

    $employees = app(SSOAuthService::class)->searchMyPortalEmployees('BULADACO');

    expect($employees)->toHaveCount(2)
        ->and($employees[0]['name'])->toBe('Ricardo A. Buladaco')
        ->and($employees[0]['id_number'])->toBe('16-12491')
        ->and($employees[0]['contact_number'])->toBe('09098116653')
        ->and($employees[1]['name'])->toBe('Shiloh Mae B. Catubig');
});

it('returns nested MyPortal directory matches through the employee search endpoint', function (): void {
    $this->seed(DatabaseSeeder::class);
    config()->set('services.cc_idp.myportal_employee_search_url', 'https://caraga-connect.example.test/api/v1/staff/portal/employee/search');
    config()->set('services.cc_idp.myportal_access_token', 'directory-token');
    config()->set('services.cc_idp.verify_ssl', false);

    Http::fake([
        'https://caraga-connect.example.test/api/v1/staff/portal/employee/search*' => Http::response([
            'status' => 'success',
            'data' => [
                'count' => 1,
                'results' => [[
                    'id_number' => '16-12491',
                    'fullname' => 'Ricardo A. Buladaco',
                    'position' => 'Administrative Aide IV',
                    'contact' => '09098116653',
                    'status' => 'Active',
                ]],
            ],
        ], 200),
    ]);

    $role = Role::firstOrCreate(['name' => 'RROS', 'guard_name' => 'web']);
    $user = User::create([
        'name' => 'RROS Searcher',
        'email' => 'rros-myportal-search@example.test',
        'password' => Hash::make('password'),
        'access_status' => 'approved',
        'access_approved_at' => now(),
        'is_active' => true,
        'office' => 'RROS',
        'position' => 'Staff',
    ]);
    $user->syncRoles([$role]);

    $this->actingAs($user)
        ->getJson(route('myportal-employees', ['search' => 'BULADACO']))
        ->assertOk()
        ->assertJsonPath('employees.0.value', 'Ricardo A. Buladaco')
        ->assertJsonPath('employees.0.id_number', '16-12491')
        ->assertJsonPath('employees.0.contact_number', '09098116653')
        ->assertJsonPath('directory_error', null);
});
