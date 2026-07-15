<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->string('override_lgu_name')->nullable();
            $table->string('override_congressional_district')->nullable();
            $table->text('override_office_address')->nullable();
            $table->string('source_hash',64)->nullable()->index();
            $table->timestamp('source_seen_at')->nullable();
            $table->boolean('missing_from_source')->default(false)->index();
        });
        Schema::table('lgu_directory_officials', function (Blueprint $table): void {
            $table->string('override_name')->nullable();
            $table->string('override_position_designation')->nullable();
        });
        Schema::table('lgu_directory_contacts', function (Blueprint $table): void {
            $table->text('value')->nullable()->change();
            $table->text('override_value')->nullable();
            $table->unique(['lgu_directory_entry_id','owner_role','contact_type'],'lgu_contact_owner_type_unique');
        });
        Schema::create('lgu_directory_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status',30)->index();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::table('request_parties', function (Blueprint $table): void {
            $table->foreignId('lgu_directory_entry_id')->nullable()->after('directory_key')->constrained()->nullOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('request_parties', fn(Blueprint $table)=>$table->dropConstrainedForeignId('lgu_directory_entry_id'));
        Schema::dropIfExists('lgu_directory_sync_runs');
        Schema::table('lgu_directory_contacts', function(Blueprint $table){$table->dropUnique('lgu_contact_owner_type_unique');$table->dropColumn('override_value');});
        Schema::table('lgu_directory_officials', fn(Blueprint $table)=>$table->dropColumn(['override_name','override_position_designation']));
        Schema::table('lgu_directory_entries', fn(Blueprint $table)=>$table->dropColumn(['override_lgu_name','override_congressional_district','override_office_address','source_hash','source_seen_at','missing_from_source']));
    }
};
