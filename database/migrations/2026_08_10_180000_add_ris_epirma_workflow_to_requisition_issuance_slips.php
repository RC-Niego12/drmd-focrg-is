<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $t): void {
            $t->string('approval_routing_mode', 20)->nullable();
            $t->string('ris_epirma_status', 40)->nullable();
            $t->foreignId('ris_epirma_forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('ris_epirma_forwarded_at')->nullable();
            $t->uuid('ris_epirma_transaction_id')->nullable();
            $t->string('ris_epirma_callback_token', 64)->nullable();
            $t->string('ris_epirma_signature_reference')->nullable();
            $t->timestamp('ris_epirma_routed_at')->nullable();
            $t->timestamp('ris_epirma_signed_at')->nullable();
            $t->string('ris_epirma_signed_path')->nullable();
            $t->string('ris_epirma_remote_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requisition_issuance_slips', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('ris_epirma_forwarded_by');
            $t->dropColumn(['approval_routing_mode', 'ris_epirma_status', 'ris_epirma_forwarded_at', 'ris_epirma_transaction_id', 'ris_epirma_callback_token', 'ris_epirma_signature_reference', 'ris_epirma_routed_at', 'ris_epirma_signed_at', 'ris_epirma_signed_path', 'ris_epirma_remote_url']);
        });
    }
};
