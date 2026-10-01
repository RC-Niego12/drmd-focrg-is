<?php

use App\Console\Commands\DiagnoseEpirma;
use App\Console\Commands\ImportWarehouseSheet;
use App\Console\Commands\ReconcileInventoryBalances;
use App\Console\Commands\RegisterEpirmaClient;
use App\Console\Commands\RemindLguDromicSignedCopies;
use App\Console\Commands\RemindLguRegionalAlertDeadlines;
use App\Console\Commands\SeedLguRoleAccounts;
use App\Console\Commands\SyncDispatchContacts;
use App\Console\Commands\SyncRisData;
use App\Console\Commands\TestMyPortalConnection;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ImportWarehouseSheet::class,
        ReconcileInventoryBalances::class,
        DiagnoseEpirma::class,
        RegisterEpirmaClient::class,
        RemindLguDromicSignedCopies::class,
        RemindLguRegionalAlertDeadlines::class,
        TestMyPortalConnection::class,
        SyncRisData::class,
        SyncDispatchContacts::class,
        SeedLguRoleAccounts::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'integrations/epirma/*',
        ]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
