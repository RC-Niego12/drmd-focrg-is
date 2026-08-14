<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epirma_signed_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('epirma_signed_documents', 'document_type')) {
                $table->string('document_type', 32)->default('assessment')->after('assistance_request_id')->index();
            }
            if (! Schema::hasColumn('epirma_signed_documents', 'action')) {
                $table->string('action', 16)->default('route')->after('document_type')->index();
            }
        });

        // Omit legacy single-flow routings; users restart under the new Sign/Route model.
        $paths = DB::table('epirma_signed_documents')->pluck('document_path');
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
        DB::table('epirma_signed_documents')->delete();

        if (Schema::hasTable('requests')) {
            DB::table('requests')->update([
                'epirma_status' => null,
                'epirma_transaction_id' => null,
                'epirma_callback_token' => null,
                'epirma_signature_reference' => null,
                'epirma_signed_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('epirma_signed_documents', function (Blueprint $table): void {
            $table->dropColumn(['document_type', 'action']);
        });
    }
};
