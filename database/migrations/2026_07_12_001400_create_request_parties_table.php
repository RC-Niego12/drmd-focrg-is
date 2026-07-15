<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_parties', function (Blueprint $table): void {
            $table->id();
            $table->string('directory_key', 64)->unique();
            $table->string('office_agency_details')->nullable();
            $table->string('requesting_party');
            $table->string('lgu_level', 50)->nullable();
            $table->string('office_head')->nullable();
            $table->string('source')->default('WIT Data Entry');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'requesting_party']);
        });

        Schema::table('requests', function (Blueprint $table): void {
            $table->foreignId('request_party_id')->nullable()->after('encoded_by')->constrained('request_parties')->nullOnDelete();
            $table->string('lgu_level', 50)->nullable()->after('lgu');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('request_party_id');
            $table->dropColumn('lgu_level');
        });
        Schema::dropIfExists('request_parties');
    }
};
