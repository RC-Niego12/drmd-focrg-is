<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('Super Admin') ? true : null;
        });

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        try {
            $busyTimeout = (int) config('database.connections.sqlite.busy_timeout', 10000);
            $journalMode = (string) config('database.connections.sqlite.journal_mode', 'WAL');
            DB::statement('PRAGMA busy_timeout = '.$busyTimeout);
            if ($journalMode !== '') {
                DB::statement('PRAGMA journal_mode = '.$journalMode);
            }
        } catch (Throwable) {
            // Ignore pragma failures on read-only/temp sqlite connections.
        }
    }
}
