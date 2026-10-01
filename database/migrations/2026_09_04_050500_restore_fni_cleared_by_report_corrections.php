<?php

use App\Models\AssistanceRequest;
use App\Models\FniLibraryItem;
use App\Models\LguDromicRequestedItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AssistanceRequest::query()
            ->where('submission_type', 'lgu_dromic_relief_request')
            ->whereNotNull('lgu_correction_of_id')
            ->where(function ($query): void {
                $query->whereNull('lgu_correction_target')
                    ->orWhere('lgu_correction_target', 'report');
            })
            ->orderBy('id')
            ->each(function (AssistanceRequest $row): void {
                $payload = (array) ($row->lgu_dromic_payload ?? []);
                $currentItems = collect(data_get($payload, 'requested_fni_items', []))
                    ->filter(fn ($item): bool => is_array($item) && (int) data_get($item, 'fni_library_item_id') > 0)
                    ->values();

                if ($currentItems->isNotEmpty()) {
                    return;
                }

                $sourceItems = $this->nearestSourceFniItems((int) $row->lgu_correction_of_id);
                if ($sourceItems->isEmpty()) {
                    return;
                }

                $payload['requested_fni_items'] = $sourceItems->all();
                $assessment = (array) ($row->assessment_form_data ?? []);
                $assessment['requested_fni_items'] = $sourceItems->all();

                $row->forceFill([
                    'lgu_dromic_payload' => $payload,
                    'assessment_form_data' => $assessment,
                ])->save();

                $this->syncRequestedItems($row, $sourceItems);
            });
    }

    public function down(): void
    {
        // Irreversible data repair.
    }

    private function nearestSourceFniItems(int $sourceId): \Illuminate\Support\Collection
    {
        $cursorId = $sourceId;
        $guard = 0;

        while ($cursorId && $guard < 20) {
            $guard++;
            $source = AssistanceRequest::query()->find($cursorId);
            if (! $source) {
                break;
            }

            $items = collect(data_get($source->lgu_dromic_payload, 'requested_fni_items', []))
                ->filter(fn ($item): bool => is_array($item) && (int) data_get($item, 'fni_library_item_id') > 0)
                ->values();

            if ($items->isNotEmpty()) {
                return $items;
            }

            $cursorId = (int) ($source->lgu_correction_of_id ?: 0);
        }

        return collect();
    }

    private function syncRequestedItems(AssistanceRequest $request, \Illuminate\Support\Collection $rows): void
    {
        $library = FniLibraryItem::query()
            ->whereIn('id', $rows->pluck('fni_library_item_id')->filter())
            ->get()
            ->keyBy('id');
        $selectedIds = [];

        foreach ($rows as $row) {
            $item = $library->get((int) data_get($row, 'fni_library_item_id'));
            if (! $item) {
                continue;
            }
            $selectedIds[] = $item->id;
            LguDromicRequestedItem::query()->updateOrCreate(
                [
                    'request_id' => $request->id,
                    'fni_library_item_id' => $item->id,
                ],
                [
                    'requested_quantity' => data_get($row, 'requested_quantity'),
                ],
            );
        }

        LguDromicRequestedItem::query()
            ->where('request_id', $request->id)
            ->when($selectedIds !== [], fn ($query) => $query->whereNotIn('fni_library_item_id', $selectedIds))
            ->delete();
    }
};
