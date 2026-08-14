<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epirma_signed_documents', function (Blueprint $table): void {
            $table->string('routing_status', 32)->default('pending')->index()->after('document_uuid');
            $table->json('signers')->nullable()->after('routing_status');
            $table->string('remote_document_url', 2048)->nullable()->after('signers');
            $table->string('remote_base_path', 2048)->nullable()->after('remote_document_url');
            $table->string('signature_reference')->nullable()->after('remote_base_path');
            $table->foreignId('initiated_by')->nullable()->after('signature_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('routed_at')->nullable()->index()->after('initiated_by');
            $table->timestamp('completed_at')->nullable()->after('routed_at');
            $table->timestamp('last_synced_at')->nullable()->after('completed_at');
            $table->string('handoff', 40)->default('document_routing')->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('epirma_signed_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('initiated_by');
            $table->dropColumn([
                'routing_status',
                'signers',
                'remote_document_url',
                'remote_base_path',
                'signature_reference',
                'routed_at',
                'completed_at',
                'last_synced_at',
                'handoff',
            ]);
        });
    }
};
