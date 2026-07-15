<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', fn (Blueprint $table) => $table->foreignId('fni_library_item_id')->nullable()->after('inventory_item_id')->constrained('fni_library_items')->nullOnDelete());

        $now = now();
        DB::table('operational_library_values')->upsert([
            ['library_type' => 'drrs_signatory', 'value' => 'JHON CARLO B. ROXAS | Social Welfare Officer II', 'context' => 'prepared_by', 'metadata' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['library_type' => 'drrs_signatory', 'value' => 'ALDIE MAE A. ANDOY | OIC- DRMD Chief', 'context' => 'reviewed_by', 'metadata' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['library_type' => 'drrs_signatory', 'value' => 'JEAN PAUL S. PARAJES, RSW, MSSW | Assistant Regional Director for Operations', 'context' => 'approved_by', 'metadata' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['library_type', 'value', 'context'], ['is_active', 'updated_at']);
    }

    public function down(): void
    {
        Schema::table('request_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('fni_library_item_id'));
        DB::table('operational_library_values')->where('library_type', 'drrs_signatory')->delete();
    }
};
