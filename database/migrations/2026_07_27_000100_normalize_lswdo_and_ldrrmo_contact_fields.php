<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->text('lswd_email')->nullable();
            $table->text('lswd_contact_number')->nullable();
            $table->text('lswd_alternate_email')->nullable();
            $table->text('lswd_facebook')->nullable();
        });

        Schema::create('lgu_directory_ldrrmo_officers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lgu_directory_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('office')->nullable();
            $table->string('name')->nullable();
            $table->string('designation')->nullable();
            $table->text('mobile_number')->nullable();
            $table->text('hotline_number')->nullable();
            $table->text('landline_number')->nullable();
            $table->text('email_address')->nullable();
            $table->text('alternate_email_address')->nullable();
            $table->text('vhf_radio_frequency')->nullable();
            $table->text('facebook')->nullable();
            $table->boolean('is_locally_updated')->default(false);
            $table->string('source_signature')->nullable();
            $table->timestamps();
            $table->index(['lgu_directory_entry_id', 'sort_order'], 'ldrrmo_entry_order_index');
        });

        DB::table('lgu_directory_entries')->orderBy('id')->each(function (object $entry): void {
            $contacts = DB::table('lgu_directory_contacts')
                ->where('lgu_directory_entry_id', $entry->id)
                ->get()
                ->keyBy(fn (object $contact): string => $contact->owner_role.'|'.$contact->contact_type);

            $contactValue = static fn (string $key): ?string => isset($contacts[$key])
                ? ($contacts[$key]->override_value ?: $contacts[$key]->value)
                : null;

            DB::table('lgu_directory_entries')->where('id', $entry->id)->update([
                'lswd_email' => $contactValue('lswd_officer|email'),
                'lswd_contact_number' => $contactValue('lswd_officer|phone'),
                'lswd_alternate_email' => $contactValue('lswd_officer|alternate_email')
                    ?: $contactValue('lswd_officer_alternate|email'),
                'lswd_facebook' => $contactValue('lswd_officer|facebook'),
            ]);

            $sourceValues = json_decode((string) ($entry->ldrrmo_payload ?? ''), true);
            $sourceValues = is_array($sourceValues) ? data_get($sourceValues, 'source_values', []) : [];

            if (! is_array($sourceValues) || $sourceValues === []) {
                $sourceValues = [[
                    'name' => $entry->ldrrmo_name ?? null,
                    'position' => $entry->ldrrmo_position ?? null,
                    'mobile_number' => $entry->ldrrmo_contact ?? null,
                    'email_address' => $entry->ldrrmo_email ?? null,
                    'facebook' => $contactValue('ldrrmo|facebook'),
                    'vhf_radio_frequency' => $contactValue('ldrrmo|vhf'),
                ]];
            }

            foreach (array_values($sourceValues) as $index => $officer) {
                if (! is_array($officer) || collect($officer)->filter(fn ($value) => filled($value))->isEmpty()) {
                    continue;
                }

                DB::table('lgu_directory_ldrrmo_officers')->insert([
                    'lgu_directory_entry_id' => $entry->id,
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                    'office' => $officer['office'] ?? null,
                    'name' => $officer['name'] ?? null,
                    'designation' => $officer['designation'] ?? $officer['position'] ?? null,
                    'mobile_number' => $officer['mobile_number'] ?? $officer['contact'] ?? null,
                    'hotline_number' => $officer['hotline_number'] ?? null,
                    'landline_number' => $officer['landline_number'] ?? null,
                    'email_address' => $officer['email_address'] ?? $officer['email'] ?? null,
                    'alternate_email_address' => $officer['alternate_email_address'] ?? $officer['alternate_email'] ?? null,
                    'vhf_radio_frequency' => $officer['vhf_radio_frequency'] ?? $officer['vhf'] ?? null,
                    'facebook' => $officer['facebook'] ?? ($index === 0 ? $contactValue('ldrrmo|facebook') : null),
                    'is_locally_updated' => false,
                    'source_signature' => md5(json_encode($officer, JSON_UNESCAPED_UNICODE)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_directory_ldrrmo_officers');

        Schema::table('lgu_directory_entries', function (Blueprint $table): void {
            $table->dropColumn([
                'lswd_email',
                'lswd_contact_number',
                'lswd_alternate_email',
                'lswd_facebook',
            ]);
        });
    }
};
