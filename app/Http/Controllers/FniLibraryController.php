<?php

namespace App\Http\Controllers;

use App\Models\FniLibraryItem;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\WarehouseSheetImport;
use App\Models\WarehouseLibraryValue;
use App\Models\OperationalLibraryValue;
use App\Models\LguDirectoryEntry;
use App\Models\LguDirectorySyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FniLibraryController extends Controller
{
    public function legacyRedirect(): RedirectResponse
    {
        return redirect()->route('fni-library.index', status: 301);
    }

    public function index(): Response
    {
        $inventoryScope = request()->user()?->can('manage inventory') ?? false;
        $userAdminScope = request()->user()?->can('manage users') ?? false;
        $isSuperAdmin = request()->user()?->hasRole('Super Admin') ?? false;
        
        $operationalQuery = OperationalLibraryValue::query();
        if (! $inventoryScope) {
            $libraryTypesToInclude = ['drrs_signatory', 'drn_prefix', 'response_letter_initials'];
            if ($isSuperAdmin) $libraryTypesToInclude[] = 'system_name';
            $operationalQuery->whereIn('library_type', $libraryTypesToInclude);
        }
        
        return Inertia::render('Inventory/FniLibrary', [
            'items' => $inventoryScope ? FniLibraryItem::query()->orderBy('item_category')->orderBy('item_name')->orderBy('brand_description')->get() : [],
            'warehouseLibraries' => $inventoryScope ? WarehouseLibraryValue::query()->orderBy('library_type')->orderBy('applicability')->orderBy('value')->get() : [],
            'warehouseLibraryTypes' => $inventoryScope ? WarehouseLibraryValue::TYPES : [],
            'operationalLibraries' => $operationalQuery->orderBy('library_type')->orderBy('value')->get(),
            'operationalLibraryTypes' => $inventoryScope ? OperationalLibraryValue::TYPES : collect(OperationalLibraryValue::TYPES)->only(array_filter(['drrs_signatory', 'drn_prefix', 'response_letter_initials', $isSuperAdmin ? 'system_name' : null]))->all(),
            'libraryScope' => $inventoryScope ? 'RROS' : ($isSuperAdmin ? 'Super Admin' : 'DRRS'),
            'lguDirectoryEntries' => $userAdminScope
                ? LguDirectoryEntry::with(['officials','contacts','ldrrmoOfficers','lswdoAlternates'])
                    ->where('is_active', true)
                    ->orderBy('source_sheet')
                    ->orderBy('lgu_name')
                    ->get()
                : [],
            'canManageLguDirectory' => $userAdminScope,
            'initialLibrary' => request()->string('library')->toString(),
            'lguSyncPreview' => session('lgu_sync_preview'),
            'lguSyncUnmatched' => session('lgu_sync_unmatched', []),
            'lguSyncRuns' => $userAdminScope ? LguDirectorySyncRun::query()->latest('started_at')->limit(5)->get() : [],
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function systemConfig()
    {
        $systemNames = OperationalLibraryValue::systemNameConfiguration();

        return response()->json([
            'system_name' => $systemNames['long_name'],
            'system_name_long' => $systemNames['long_name'],
            'system_name_short' => $systemNames['short_name'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        FniLibraryItem::create($this->validated($request));

        return back()->with('success', 'FNI library item added.');
    }

    public function update(Request $request, FniLibraryItem $fniLibraryItem): RedirectResponse
    {
        $fniLibraryItem->update($this->validated($request, $fniLibraryItem));

        return back()->with('success', 'FNI library item updated.');
    }

    public function destroy(FniLibraryItem $fniLibraryItem): RedirectResponse
    {
        $fniLibraryItem->delete();

        return back()->with('success', 'FNI library item removed.');
    }

    public function storeWarehouseValue(Request $request): RedirectResponse
    {
        WarehouseLibraryValue::create($this->validatedWarehouseValue($request));
        return back()->with('success', 'Warehouse library value added.');
    }

    public function updateWarehouseValue(Request $request, WarehouseLibraryValue $warehouseLibraryValue): RedirectResponse
    {
        $warehouseLibraryValue->update($this->validatedWarehouseValue($request, $warehouseLibraryValue));
        return back()->with('success', 'Warehouse library value updated.');
    }

    public function destroyWarehouseValue(WarehouseLibraryValue $warehouseLibraryValue): RedirectResponse
    {
        $warehouseLibraryValue->delete();
        return back()->with('success', 'Warehouse library value removed.');
    }

    private function validatedWarehouseValue(Request $request, ?WarehouseLibraryValue $row = null): array
    {
        $request->merge(['value' => $this->normalizeText($request->input('value'))]);
        $data = $request->validate([
            'library_type' => ['required', Rule::in(array_keys(WarehouseLibraryValue::TYPES))],
            'value' => ['required', 'string', 'max:255', Rule::unique('warehouse_library_values')->where(fn ($query) => $query->where('library_type', $request->input('library_type'))->where('applicability', $request->input('applicability')))->ignore($row?->id)],
            'applicability' => ['required', Rule::in(['all', 'prepositioning', 'other'])],
        ]);
        $this->rejectNormalizedDuplicate(WarehouseLibraryValue::query()->where('library_type', $data['library_type'])->where('applicability', $data['applicability']), $data['value'], $row?->id);
        return $data;
    }

    public function storeOperationalValue(Request $request): RedirectResponse
    {
        $value = OperationalLibraryValue::create($this->validatedOperationalValue($request));
        $this->activateExclusiveSystemName($value);
        return back()->with('success', 'Operational library value added.');
    }

    public function updateOperationalValue(Request $request, OperationalLibraryValue $operationalLibraryValue): RedirectResponse
    {
        $isSuperAdmin = $request->user()?->hasRole('Super Admin') ?? false;
        $allowedTypes = ['drrs_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin) $allowedTypes[] = 'system_name';
        abort_if(! $request->user()?->can('manage inventory') && ! in_array($operationalLibraryValue->library_type, $allowedTypes, true), 403);
        $operationalLibraryValue->update($this->validatedOperationalValue($request, $operationalLibraryValue));
        $this->activateExclusiveSystemName($operationalLibraryValue);
        return back()->with('success', 'Operational library value updated.');
    }

    public function destroyOperationalValue(OperationalLibraryValue $operationalLibraryValue): RedirectResponse
    {
        $isSuperAdmin = request()->user()?->hasRole('Super Admin') ?? false;
        $allowedTypes = ['drrs_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin) $allowedTypes[] = 'system_name';
        abort_if(! request()->user()?->can('manage inventory') && ! in_array($operationalLibraryValue->library_type, $allowedTypes, true), 403);
        $operationalLibraryValue->delete();
        return back()->with('success', 'Operational library value removed.');
    }

    private function validatedOperationalValue(Request $request, ?OperationalLibraryValue $row = null): array
    {
        $request->merge([
            'value' => $this->normalizeText($request->input('value')),
            'short_name' => $this->normalizeText($request->input('short_name')),
            'context' => $this->normalizeText($request->input('context', 'all')),
        ]);
        $isSuperAdmin = $request->user()?->hasRole('Super Admin') ?? false;
        $allowedTypes = $request->user()?->can('manage inventory') ? array_keys(OperationalLibraryValue::TYPES) : ['drrs_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin && ! $request->user()?->can('manage inventory')) {
            $allowedTypes[] = 'system_name';
        }
        $data = $request->validate([
            'library_type' => ['required', Rule::in($allowedTypes)],
            'value' => ['required', 'string', 'max:255', Rule::unique('operational_library_values')->where(fn ($query) => $query->where('library_type', $request->input('library_type'))->where('context', $request->input('context', 'all')))->ignore($row?->id)],
            'context' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
            'short_name' => ['nullable', 'required_if:library_type,system_name', 'string', 'max:120'],
        ]);
        $data['metadata'] = $data['library_type'] === 'system_name'
            ? ['short_name' => $data['short_name']]
            : ($row?->metadata ?? null);
        unset($data['short_name']);
        if ($data['library_type'] === 'drrs_signatory' && ! in_array($data['context'], ['prepared_by', 'reviewed_by', 'approved_by'], true)) {
            throw ValidationException::withMessages(['context' => 'Use prepared_by, reviewed_by, or approved_by as the signatory role.']);
        }
        if ($data['library_type'] === 'drn_prefix' && ! in_array($data['context'], ['assessment', 'response_letter'], true)) {
            throw ValidationException::withMessages(['context' => 'Use assessment or response_letter as the document context.']);
        }
        if ($data['library_type'] === 'response_letter_initials' && $data['context'] !== 'response_letter') {
            throw ValidationException::withMessages(['context' => 'Use response_letter as the document context.']);
        }
        $this->rejectNormalizedDuplicate(OperationalLibraryValue::query()->where('library_type', $data['library_type']), $data['value'], $row?->id);
        return $data;
    }

    private function activateExclusiveSystemName(OperationalLibraryValue $value): void
    {
        if ($value->library_type !== 'system_name' || ! $value->is_active) return;

        OperationalLibraryValue::query()
            ->where('library_type', 'system_name')
            ->whereKeyNot($value->id)
            ->update(['is_active' => false]);
    }

    private function validated(Request $request, ?FniLibraryItem $item = null): array
    {
        $request->merge(['item_category'=>$this->normalizeText($request->input('item_category')),'item_name'=>$this->normalizeText($request->input('item_name')),'brand_description'=>$this->normalizeText($request->input('brand_description', '')),'unit_of_measure'=>$this->normalizeText($request->input('unit_of_measure'))]);

        $data = $request->validate([
            'item_category' => ['required', 'string', 'max:120'],
            'item_name' => [
                'required', 'string', 'max:255',
                Rule::unique('fni_library_items')->where(fn ($query) => $query
                    ->where('item_category', $request->input('item_category'))
                    ->where('brand_description', $request->input('brand_description', ''))
                    ->where('unit_of_measure', $request->input('unit_of_measure'))
                )->ignore($item?->id),
            ],
            'brand_description' => ['nullable', 'string', 'max:255'],
            'unit_of_measure' => ['required', 'string', 'max:80'],
        ]);
        $targetKey = strtolower(implode('|', [$data['item_category'], $data['item_name'], $data['brand_description'] ?? '', $data['unit_of_measure']]));
        $duplicate = FniLibraryItem::query()
            ->when($item, fn ($query) => $query->whereKeyNot($item->id))
            ->get()
            ->contains(fn (FniLibraryItem $row): bool => strtolower(implode('|', [
                $this->normalizeText($row->item_category),
                $this->normalizeText($row->item_name),
                $this->normalizeText($row->brand_description),
                $this->normalizeText($row->unit_of_measure),
            ])) === $targetKey);
        if ($duplicate) throw ValidationException::withMessages(['item_name' => 'This item, brand, and UOM combination already exists.']);
        return $data;
    }

    private function rejectNormalizedDuplicate($query, string $value, ?int $exceptId): void
    {
        $duplicate = $query->when($exceptId, fn ($builder) => $builder->whereKeyNot($exceptId))->get()->contains(fn ($row): bool => strtolower($this->normalizeText($row->value)) === strtolower($value));
        if ($duplicate) throw ValidationException::withMessages(['value' => 'This library value already exists.']);
    }

    private function normalizeText(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }

    public function recoverFromInventory(): void
    {
        $imports = WarehouseSheetImport::query()
            ->where('import_status', 'imported')
            ->get(['raw_payload']);
        $imports->each(function (WarehouseSheetImport $import): void {
                $payload = $import->raw_payload ?? [];
                $this->storeInventoryReference(
                    $payload['item_category'] ?? null,
                    $payload['item'] ?? null,
                    $payload['brand_description'] ?? null,
                    $payload['uom'] ?? null,
                );
            });

        $imports->map(function (WarehouseSheetImport $import): array {
            $payload = $import->raw_payload ?? [];
            return ['category' => trim((string) ($payload['item_category'] ?? '')), 'item' => trim((string) ($payload['item'] ?? '')), 'brand' => trim((string) ($payload['brand_description'] ?? ''))];
        })->filter(fn (array $row): bool => $row['category'] !== '' && $row['item'] !== '')
            ->groupBy(fn (array $row): string => strtolower($row['item'].'|'.$row['brand']))
            ->each(function ($rows): void {
                $first = $rows->first();
                $validCategories = $rows->pluck('category')->unique()->all();
                FniLibraryItem::query()->where('item_name', $first['item'])->where('brand_description', $first['brand'])->whereNotIn('item_category', $validCategories)->delete();
            });

        InventoryItem::query()
            ->with('batches:id,inventory_item_id,brand_description')
            ->get()
            ->each(function (InventoryItem $item): void {
                if ($item->batches->isEmpty()) {
                    if (FniLibraryItem::where('item_name', $item->name)->exists()) return;
                    $this->storeInventoryReference($this->displayCategory($item->category), $item->name, $item->description, $item->unit);
                    return;
                }

                $item->batches->each(function (InventoryBatch $batch) use ($item): void {
                    if (FniLibraryItem::where('item_name', $item->name)->where('brand_description', trim((string) $batch->brand_description))->exists()) return;
                    $this->storeInventoryReference($this->displayCategory($item->category), $item->name, $batch->brand_description, $item->unit);
                });
            });
    }

    private function storeInventoryReference(mixed $category, mixed $name, mixed $brand, mixed $unit): void
    {
        $category = trim((string) $category);
        $name = trim((string) $name);
        $brand = trim((string) $brand);
        $unit = $this->correctUnit($name);

        if (strtolower($unit) === 'unit') {
            return;
        }

        if (str_contains(strtolower($name), 'family food pack')) {
            $category = 'Family Food Packs';
        }

        if ($category === '' || $name === '') {
            return;
        }

        $existing = FniLibraryItem::query()->where([
            'item_category' => $category,
            'item_name' => $name,
            'brand_description' => $brand,
        ])->first();
        if ($existing && $existing->unit_of_measure === 'unit' && $unit !== 'unit') {
            $existing->update(['unit_of_measure' => $unit]);
            return;
        }
        FniLibraryItem::firstOrCreate(['item_category' => $category, 'item_name' => $name, 'brand_description' => $brand, 'unit_of_measure' => $unit]);
    }

    private function displayCategory(?string $category): string
    {
        return $category === 'food' ? 'Food Items' : ($category === 'non_food' ? 'Non Food Items' : trim((string) $category));
    }

    private function correctUnit(string $name): string
    {
        $name = strtolower(trim($name));
        if (str_contains($name, 'family food pack') || str_contains($name, 'faced form') || str_contains($name, 'ready to eat')) return 'box';
        if (str_contains($name, 'kit')) return 'kit';
        if (str_contains($name, 'tent')) return 'set';
        if (str_contains($name, 'water')) return 'bottle';
        if ($name === 'rice') return 'sack';
        if (str_contains($name, 'twine') || str_contains($name, 'tarpaulin') || str_contains($name, 'packaging tape')) return 'roll';
        return 'piece';
    }
}
