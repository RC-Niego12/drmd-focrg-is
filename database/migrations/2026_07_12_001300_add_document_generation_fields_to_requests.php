<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('requests', function (Blueprint $table): void {
        $table->date('date_received_by_drmd')->nullable(); $table->string('request_drn')->nullable()->index();
        $table->string('office_agency_details')->nullable(); $table->boolean('endorsed_to_drrs')->default(false); $table->date('date_endorsed_to_drrs')->nullable();
        $table->string('incident_details')->nullable(); $table->unsignedInteger('incident_count')->nullable();
        $table->string('response_drn')->nullable()->index(); $table->string('assessment_drn')->nullable()->index();
        $table->text('source_document_url')->nullable(); $table->text('response_letter_url')->nullable();
        $table->boolean('coordinated_with_rros')->default(false); $table->date('date_coordinated_with_rros')->nullable();
        $table->string('requester_position')->nullable(); $table->string('requester_address')->nullable(); $table->string('contact_number')->nullable();
        $table->unsignedInteger('affected_families')->nullable(); $table->string('assigned_social_worker')->nullable();
    }); }
    public function down(): void { Schema::table('requests', fn (Blueprint $table) => $table->dropColumn(['date_received_by_drmd','request_drn','office_agency_details','endorsed_to_drrs','date_endorsed_to_drrs','incident_details','incident_count','response_drn','assessment_drn','source_document_url','response_letter_url','coordinated_with_rros','date_coordinated_with_rros','requester_position','requester_address','contact_number','affected_families','assigned_social_worker'])); }
};
