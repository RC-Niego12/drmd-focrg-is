<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $incidentIds = [];

        DB::table('requests')->select(['id', 'incident_id', 'purpose', 'assessment_form_data', 'recommendations'])
            ->orderBy('id')
            ->each(function (object $request) use (&$incidentIds): void {
                $meta = json_decode((string) ($request->assessment_form_data ?? ''), true) ?: [];
                $purpose = $meta['response_purpose'] ?? $request->purpose;
                $updates = [];

                if ($purpose === 'Preparedness for Response') {
                    if ($request->incident_id) $incidentIds[] = $request->incident_id;
                    $meta['request_type'] = null;
                    $updates['incident_id'] = null;
                    $updates['incident_details'] = null;
                    $updates['assessment_form_data'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $lines = preg_split('/\R/u', trim((string) ($request->recommendations ?? ''))) ?: [];
                $narrative = trim(implode("\n", array_filter($lines, fn (string $line): bool => preg_match('/^(Signature|Date)\s*:\s*_+\s*$/iu', trim($line)) !== 1)));
                if ($narrative !== (string) ($request->recommendations ?? '')) $updates['recommendations'] = $narrative;

                if ($updates !== []) DB::table('requests')->where('id', $request->id)->update($updates);
            });

        foreach (array_unique($incidentIds) as $incidentId) {
            if (! DB::table('requests')->where('incident_id', $incidentId)->exists()) {
                DB::table('incidents')->where('id', $incidentId)->where('name', 'Prepositioning')->delete();
            }
        }
    }

    public function down(): void
    {
        // The former pseudo-incident cannot be restored without reintroducing invalid data.
    }
};
