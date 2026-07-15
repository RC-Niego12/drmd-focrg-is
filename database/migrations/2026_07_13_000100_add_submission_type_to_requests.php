<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->string('submission_type', 30)->default('fni_request')->after('reference_number')->index();
            $table->string('proposal_type', 20)->nullable()->after('submission_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('requests', fn (Blueprint $table) => $table->dropColumn(['submission_type', 'proposal_type']));
    }
};
