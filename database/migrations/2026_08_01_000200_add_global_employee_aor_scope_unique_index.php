<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::getConnection()->getSchemaBuilder();
        if ($schema->hasIndex('employee_area_of_responsibilities', 'aor_scope_global_unique')) {
            Schema::table('employee_area_of_responsibilities', function (Blueprint $table): void {
                $table->dropUnique('aor_scope_global_unique');
            });
        }
    }

    public function down(): void
    {
        // Hierarchical AOR ownership is enforced in the model layer instead of a
        // single global unique index, so a province can remain reusable when its
        // child districts or city/municipalities still have free coverage.
    }
};
