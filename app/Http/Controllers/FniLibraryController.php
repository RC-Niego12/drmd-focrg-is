<?php

namespace App\Http\Controllers;

use App\Models\FniLibraryItem;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\LguDirectoryEntry;
use App\Models\LguDirectorySyncRun;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use App\Models\WarehouseLibraryValue;
use App\Models\WarehouseSheetImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $user = request()->user();
        $inventoryScope = $this->canManageInventory($user);
        $userAdminScope = $user
            ? ($user->hasRole('Super Admin') || $user->can('manage users'))
            : false;
        $isSuperAdmin = $user?->hasRole('Super Admin') ?? false;

        $operationalQuery = OperationalLibraryValue::query();
        if (! $isSuperAdmin) {
            $operationalQuery->where('library_type', '!=', 'drims_signatory');
        }
        if (! $inventoryScope) {
            $libraryTypesToInclude = ['drrs_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory', 'drn_prefix', 'response_letter_initials'];
            if ($isSuperAdmin) {
                $libraryTypesToInclude[] = 'system_name';
                $libraryTypesToInclude[] = 'drims_signatory';
            }
            $operationalQuery->whereIn('library_type', $libraryTypesToInclude);
        }

        OperationalLibraryValue::backfillMissingSignatoryOffices();

        return Inertia::render('Inventory/FniLibrary', [
            'items' => $inventoryScope ? FniLibraryItem::query()->orderBy('item_category')->orderBy('item_name')->orderBy('brand_description')->get() : [],
            'warehouseLibraries' => $inventoryScope ? WarehouseLibraryValue::query()->orderBy('library_type')->orderBy('applicability')->orderBy('value')->get() : [],
            'warehouseLibraryTypes' => $inventoryScope ? WarehouseLibraryValue::TYPES : [],
            'operationalLibraries' => $operationalQuery->orderBy('library_type')->orderBy('value')->get(),
            'operationalLibraryTypes' => $inventoryScope
                ? collect(OperationalLibraryValue::TYPES)->when(! $isSuperAdmin, fn ($types) => $types->except('drims_signatory'))->all()
                : collect(OperationalLibraryValue::TYPES)->only(array_filter(['drrs_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory', 'drn_prefix', 'response_letter_initials', $isSuperAdmin ? 'system_name' : null, $isSuperAdmin ? 'drims_signatory' : null]))->all(),
            'libraryScope' => $inventoryScope ? 'RROS' : ($isSuperAdmin ? 'Super Admin' : 'DRRS'),
            'lguDirectoryEntries' => $userAdminScope
                ? LguDirectoryEntry::with(['officials', 'contacts', 'ldrrmoOfficers', 'lswdoAlternates'])
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

    public function storeRrosSignatories(Request $request): RedirectResponse
    {
        $contexts = [
            'rros_ris_signatory' => ['requested_by', 'approved_by', 'issued_by'],
            'rros_dr_signatory' => ['issuance_approved_by', 'released_by'],
            'rros_stf_signatory' => ['requested_by', 'approved_by', 'issued_by'],
            'drims_signatory' => ['recommended_by', 'approved_by'],
        ];
        if ($request->input('library_type') === 'drims_signatory') {
            abort_unless($request->user()?->hasRole('Super Admin'), 403);
        }
        $data = $request->validate([
            'library_type' => ['required', Rule::in(array_keys($contexts))],
            'signatories' => ['required', 'array'],
            'signatories.*.name' => ['required', 'string', 'max:180'],
            'signatories.*.position' => ['required', 'string', 'max:180'],
            'signatories.*.suffix' => ['nullable', 'string', 'max:80'],
            'signatories.*.designation' => ['required', 'string', 'max:180'],
            'signatories.*.office' => ['nullable', 'string', 'max:255'],
        ]);
        $expected = $contexts[$data['library_type']];
        foreach ($expected as $context) {
            if (blank(data_get($data, "signatories.{$context}.name")) || blank(data_get($data, "signatories.{$context}.position")) || blank(data_get($data, "signatories.{$context}.designation"))) {
                throw ValidationException::withMessages(["signatories.{$context}" => 'Select a MyPortal employee and complete the designation.']);
            }
        }

        DB::transaction(function () use ($data, $expected): void {
            foreach ($expected as $context) {
                $name = $this->normalizeText(data_get($data, "signatories.{$context}.name"));
                $position = $this->normalizeText(data_get($data, "signatories.{$context}.position"));
                $suffix = $this->normalizeText(data_get($data, "signatories.{$context}.suffix"));
                $designation = $this->normalizeText(data_get($data, "signatories.{$context}.designation"));
                $office = OperationalLibraryValue::resolveSignatoryOffice($name, data_get($data, "signatories.{$context}.office"));
                $rows = OperationalLibraryValue::query()->where('library_type', $data['library_type'])->where('context', $context)->orderBy('id')->get();
                $entry = $rows->shift() ?: new OperationalLibraryValue(['library_type' => $data['library_type'], 'context' => $context]);
                $previousName = $entry->exists
                    ? (data_get($entry->metadata, 'employee_name') ?: explode('|', (string) $entry->value, 2)[0] ?? '')
                    : $name;
                if ($office === '' && $entry->exists) {
                    $office = $this->normalizeText(data_get($entry->metadata, 'office'));
                }
                $displayName = $name.($suffix !== '' ? ", {$suffix}" : '');
                $entry->fill(['value' => "{$displayName} | {$designation}", 'metadata' => array_filter(['employee_name' => $name, 'position' => $position, 'suffix' => $suffix ?: null, 'designation' => $designation, 'office' => $office ?: null]), 'is_active' => true])->save();
                if ($rows->isNotEmpty()) {
                    OperationalLibraryValue::query()->whereKey($rows->pluck('id'))->delete();
                }
                OperationalLibraryValue::syncRelatedSignatoryPersonDetails(
                    [$previousName, $name],
                    ['name' => $name, 'position' => $position, 'suffix' => $suffix, 'designation' => $designation, 'office' => $office],
                    $entry->id,
                );
            }
        });

        return back()->with(
            'success',
            $data['library_type'] === 'drims_signatory'
                ? 'The DRIMS DROMIC report signatories were saved.'
                : 'The complete RROS document signatory set was saved.'
        );
    }

    public function storeDrrsSignatories(Request $request): RedirectResponse
    {
        $contextsByDocument = [
            'assessment' => ['reviewed_by', 'approved_by'],
            'response_letter' => ['approved_by'],
        ];
        $data = $request->validate([
            'document_type' => ['required', Rule::in(['assessment', 'response_letter'])],
            'signatories' => ['required', 'array'],
            'signatories.*.name' => ['required', 'string', 'max:180'],
            'signatories.*.position' => ['required', 'string', 'max:180'],
            'signatories.*.suffix' => ['nullable', 'string', 'max:80'],
            'signatories.*.designation' => ['required', 'string', 'max:180'],
            'signatories.*.office' => ['nullable', 'string', 'max:255'],
            'signatories.*.initials' => ['required', 'string', 'max:40'],
        ]);
        $contexts = $contextsByDocument[$data['document_type']];
        foreach ($contexts as $context) {
            foreach (['name', 'position', 'designation', 'initials'] as $field) {
                if (blank(data_get($data, "signatories.{$context}.{$field}"))) {
                    throw ValidationException::withMessages(["signatories.{$context}.{$field}" => 'Complete all DRRS signatory details.']);
                }
            }
        }
        DB::transaction(function () use ($data, $contexts): void {
            OperationalLibraryValue::query()
                ->where('library_type', 'drrs_signatory')
                ->whereNotIn('context', $contexts)
                ->where(function ($query) use ($data): void {
                    $query->where('metadata->document_type', $data['document_type']);
                    if ($data['document_type'] === 'assessment') {
                        $query->orWhereNull('metadata->document_type');
                    }
                })
                ->delete();
            foreach ($contexts as $context) {
                $details = collect(data_get($data, "signatories.{$context}"))->map(fn ($value) => $this->normalizeText($value));
                $office = OperationalLibraryValue::resolveSignatoryOffice($details['name'], $details->get('office'));
                $rows = OperationalLibraryValue::query()->where('library_type', 'drrs_signatory')->where('context', $context)
                    ->where(function ($query) use ($data): void {
                        $query->where('metadata->document_type', $data['document_type']);
                        if ($data['document_type'] === 'assessment') {
                            $query->orWhereNull('metadata->document_type');
                        }
                    })->orderBy('id')->get();
                $entry = $rows->shift() ?: new OperationalLibraryValue(['library_type' => 'drrs_signatory', 'context' => $context]);
                $previousName = $entry->exists
                    ? (data_get($entry->metadata, 'employee_name') ?: explode('|', (string) $entry->value, 2)[0] ?? '')
                    : $details['name'];
                if ($office === '' && $entry->exists) {
                    $office = $this->normalizeText(data_get($entry->metadata, 'office'));
                }
                $entry->fill([
                    'value' => $details['name'].(filled($details->get('suffix')) ? ', '.$details->get('suffix') : '').' | '.$details['designation'],
                    'metadata' => array_filter([
                        'document_type' => $data['document_type'],
                        'employee_name' => $details['name'],
                        ...$details->only(['position', 'suffix', 'designation', 'initials'])->all(),
                        'office' => $office ?: null,
                    ]),
                    'is_active' => true,
                ])->save();
                if ($rows->isNotEmpty()) {
                    OperationalLibraryValue::query()->whereKey($rows->pluck('id'))->delete();
                }
                OperationalLibraryValue::syncRelatedSignatoryPersonDetails(
                    [$previousName, $details['name']],
                    [
                        'name' => $details['name'],
                        'position' => $details['position'],
                        'suffix' => $details->get('suffix', ''),
                        'designation' => $details['designation'],
                        'office' => $office,
                        'initials' => $details->get('initials', ''),
                    ],
                    $entry->id,
                );
            }
        });

        return back()->with('success', 'The complete DRRS signatory set was saved.');
    }

    public function updateOperationalValue(Request $request, OperationalLibraryValue $operationalLibraryValue): RedirectResponse
    {
        $isSuperAdmin = $request->user()?->hasRole('Super Admin') ?? false;
        $allowedTypes = ['drrs_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin) {
            $allowedTypes[] = 'system_name';
            $allowedTypes[] = 'drims_signatory';
        }
        abort_if(! $this->canManageInventory($request->user()) && ! in_array($operationalLibraryValue->library_type, $allowedTypes, true), 403);
        $previousEmployeeName = data_get($operationalLibraryValue->metadata, 'employee_name')
            ?: trim(explode('|', (string) $operationalLibraryValue->value, 2)[0] ?? '');
        $validated = $this->validatedOperationalValue($request, $operationalLibraryValue);
        $operationalLibraryValue->update($validated);
        $this->activateExclusiveSystemName($operationalLibraryValue);

        if (in_array($operationalLibraryValue->library_type, OperationalLibraryValue::signatoryLibraryTypes(), true)) {
            $metadata = $operationalLibraryValue->metadata ?? [];
            OperationalLibraryValue::syncRelatedSignatoryPersonDetails(
                [$previousEmployeeName, data_get($metadata, 'employee_name')],
                [
                    'name' => data_get($metadata, 'employee_name', ''),
                    'position' => data_get($metadata, 'position', ''),
                    'suffix' => data_get($metadata, 'suffix', ''),
                    'designation' => data_get($metadata, 'designation', ''),
                    'office' => data_get($metadata, 'office', ''),
                    'initials' => data_get($metadata, 'initials', ''),
                ],
                $operationalLibraryValue->id,
            );
        }

        return back()->with('success', 'Operational library value updated.');
    }

    public function destroyOperationalValue(OperationalLibraryValue $operationalLibraryValue): RedirectResponse
    {
        $isSuperAdmin = request()->user()?->hasRole('Super Admin') ?? false;
        $allowedTypes = ['drrs_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin) {
            $allowedTypes[] = 'system_name';
            $allowedTypes[] = 'drims_signatory';
        }
        abort_if(! $this->canManageInventory(request()->user()) && ! in_array($operationalLibraryValue->library_type, $allowedTypes, true), 403);
        $operationalLibraryValue->delete();

        return back()->with('success', 'Operational library value removed.');
    }

    private function validatedOperationalValue(Request $request, ?OperationalLibraryValue $row = null): array
    {
        $signatoryTypes = ['drrs_signatory', 'drims_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory'];
        $isSignatory = in_array($request->input('library_type'), $signatoryTypes, true);
        $employeeName = $this->normalizeText($request->input('value'));
        $position = $this->normalizeText($request->input('position'));
        $designation = $this->normalizeText($request->input('designation'));
        $suffix = $this->normalizeText($request->input('suffix'));
        $initials = $this->normalizeText($request->input('initials'));
        $office = $isSignatory
            ? OperationalLibraryValue::resolveSignatoryOffice($employeeName, $request->input('office') ?: data_get($row?->metadata, 'office'))
            : (in_array($request->input('library_type'), ['dispatch_received_by', 'dispatch_driver'], true)
                ? $this->normalizeText($request->input('office'))
                : '');
        $contactNumber = $request->input('library_type') === 'dispatch_driver'
            ? $this->normalizeText($request->input('contact_number'))
            : '';
        $idNumber = in_array($request->input('library_type'), ['dispatch_driver', 'dispatch_received_by'], true)
            ? $this->normalizeText($request->input('id_number'))
            : '';
        $documentType = $request->input('library_type') === 'drrs_signatory'
            ? ($request->input('document_type') ?: data_get($row?->metadata, 'document_type', 'assessment'))
            : null;
        $request->merge([
            'value' => $isSignatory && $designation !== '' ? $employeeName.($suffix !== '' ? ", {$suffix}" : '')." | {$designation}" : $employeeName,
            'designation' => $designation,
            'position' => $position,
            'initials' => $initials,
            'office' => $office,
            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
            'id_number' => $idNumber !== '' ? $idNumber : null,
            'document_type' => $documentType,
            'suffix' => $suffix,
            'short_name' => $this->normalizeText($request->input('short_name')),
            'context' => $this->normalizeText($request->input('context', 'all')),
        ]);
        $isSuperAdmin = $request->user()?->hasRole('Super Admin') ?? false;
        $canManageInventory = $this->canManageInventory($request->user());
        $allowedTypes = $canManageInventory ? array_keys(OperationalLibraryValue::TYPES) : ['drrs_signatory', 'rros_ris_signatory', 'rros_dr_signatory', 'rros_stf_signatory', 'drn_prefix', 'response_letter_initials'];
        if ($isSuperAdmin && ! $canManageInventory) {
            $allowedTypes[] = 'system_name';
            $allowedTypes[] = 'drims_signatory';
        }
        $data = $request->validate([
            'library_type' => ['required', Rule::in($allowedTypes)],
            'value' => ['required', 'string', 'max:255', Rule::unique('operational_library_values')->where(fn ($query) => $query->where('library_type', $request->input('library_type'))->where('context', $request->input('context', 'all')))->ignore($row?->id)],
            'context' => ['required', 'string', 'max:80'],
            'is_active' => ['required', 'boolean'],
            'short_name' => ['nullable', 'required_if:library_type,system_name', 'string', 'max:120'],
            'designation' => [Rule::requiredIf($isSignatory), 'nullable', 'string', 'max:255'],
            'position' => [Rule::requiredIf($isSignatory), 'nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'contact_number' => [Rule::requiredIf($request->input('library_type') === 'dispatch_driver'), 'nullable', 'string', 'max:80'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'initials' => [Rule::requiredIf($request->input('library_type') === 'drrs_signatory'), 'nullable', 'string', 'max:40'],
            'suffix' => ['nullable', 'string', 'max:80'],
            'document_type' => [Rule::requiredIf($request->input('library_type') === 'drrs_signatory'), 'nullable', Rule::in(['assessment', 'response_letter'])],
        ]);
        $data['metadata'] = $data['library_type'] === 'system_name'
            ? ['short_name' => $data['short_name']]
            : ($isSignatory
                ? array_filter(['document_type' => $data['document_type'] ?: null, 'employee_name' => $employeeName, 'position' => $data['position'], 'suffix' => $data['suffix'] ?: null, 'designation' => $data['designation'], 'office' => $data['office'] ?: null, 'initials' => $data['initials'] ?: null])
                : ($data['library_type'] === 'dispatch_driver'
                    ? array_filter([
                        'contact_number' => $data['contact_number'] ?? null,
                        'position' => $data['position'] ?? null,
                        'office' => $data['office'] ?? null,
                        'id_number' => $data['id_number'] ?? null,
                    ], fn ($value) => filled($value))
                    : ($data['library_type'] === 'dispatch_received_by'
                        ? array_filter([
                            'position' => $data['position'] ?? '',
                            'office' => $data['office'] ?? '',
                            'id_number' => $data['id_number'] ?? null,
                        ], fn ($value) => $value !== null && $value !== '')
                        : ($row?->metadata ?? null))));
        unset($data['short_name'], $data['document_type'], $data['position'], $data['suffix'], $data['designation'], $data['office'], $data['initials'], $data['contact_number'], $data['id_number']);
        if ($data['library_type'] === 'drrs_signatory' && ! in_array($data['context'], ['reviewed_by', 'approved_by'], true)) {
            throw ValidationException::withMessages(['context' => 'Use reviewed_by or approved_by as the signatory role.']);
        }
        $rrosContexts = [
            'rros_ris_signatory' => ['requested_by', 'approved_by', 'issued_by'],
            'rros_dr_signatory' => ['issuance_approved_by', 'released_by'],
            'rros_stf_signatory' => ['requested_by', 'approved_by', 'issued_by'],
            'drims_signatory' => ['recommended_by', 'approved_by'],
        ];
        if (isset($rrosContexts[$data['library_type']]) && ! in_array($data['context'], $rrosContexts[$data['library_type']], true)) {
            throw ValidationException::withMessages(['context' => 'Select a valid signatory role for this document.']);
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
        if ($value->library_type !== 'system_name' || ! $value->is_active) {
            return;
        }

        OperationalLibraryValue::query()
            ->where('library_type', 'system_name')
            ->whereKeyNot($value->id)
            ->update(['is_active' => false]);
    }

    private function validated(Request $request, ?FniLibraryItem $item = null): array
    {
        $request->merge(['item_category' => $this->normalizeText($request->input('item_category')), 'item_name' => $this->normalizeText($request->input('item_name')), 'brand_description' => $this->normalizeText($request->input('brand_description', '')), 'unit_of_measure' => $this->normalizeText($request->input('unit_of_measure'))]);

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
        if ($duplicate) {
            throw ValidationException::withMessages(['item_name' => 'This item, brand, and UOM combination already exists.']);
        }

        return $data;
    }

    private function rejectNormalizedDuplicate($query, string $value, ?int $exceptId): void
    {
        $duplicate = $query->when($exceptId, fn ($builder) => $builder->whereKeyNot($exceptId))->get()->contains(fn ($row): bool => strtolower($this->normalizeText($row->value)) === strtolower($value));
        if ($duplicate) {
            throw ValidationException::withMessages(['value' => 'This library value already exists.']);
        }
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
                    if (FniLibraryItem::where('item_name', $item->name)->exists()) {
                        return;
                    }
                    $this->storeInventoryReference($this->displayCategory($item->category), $item->name, $item->description, $item->unit);

                    return;
                }

                $item->batches->each(function (InventoryBatch $batch) use ($item): void {
                    if (FniLibraryItem::where('item_name', $item->name)->where('brand_description', trim((string) $batch->brand_description))->exists()) {
                        return;
                    }
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
        if (str_contains($name, 'family food pack') || str_contains($name, 'faced form') || str_contains($name, 'ready to eat')) {
            return 'box';
        }
        if (str_contains($name, 'kit')) {
            return 'kit';
        }
        if (str_contains($name, 'tent')) {
            return 'set';
        }
        if (str_contains($name, 'water')) {
            return 'bottle';
        }
        if ($name === 'rice') {
            return 'sack';
        }
        if (str_contains($name, 'twine') || str_contains($name, 'tarpaulin') || str_contains($name, 'packaging tape')) {
            return 'roll';
        }

        return 'piece';
    }

    private function canManageInventory(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['Super Admin', 'RROS', 'RROS AA'])
            || $user->can('manage inventory');
    }
}
