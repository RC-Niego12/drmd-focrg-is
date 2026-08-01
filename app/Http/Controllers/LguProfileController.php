<?php

namespace App\Http\Controllers;

use App\Models\LguDirectoryEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LguProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && ($user->hasRole('LGU') || filled($user->lgu_psgc_code) || filled($user->lgu_name)), 403);

        $validated = $request->validate([
            'lgu_name' => ['required', 'string', 'max:255'],
            'province_name' => ['nullable', 'string', 'max:255'],
            'managed_district_name' => ['nullable', 'string', 'max:255'],
            'lce_name' => ['nullable', 'string', 'max:255'],
            'lce_position' => ['nullable', 'string', 'max:255'],
            'lce_email' => ['nullable', 'string', 'max:500'],
            'lce_phone' => ['nullable', 'string', 'max:500'],
            'lswd_name' => ['nullable', 'string', 'max:255'],
            'lswd_position' => ['nullable', 'string', 'max:255'],
            'lswd_email' => ['nullable', 'string', 'max:500'],
            'lswd_phone' => ['nullable', 'string', 'max:500'],
            'lswd_facebook' => ['nullable', 'string', 'max:500'],
            'lswd_alt_name' => ['nullable', 'string', 'max:255'],
            'lswd_alt_position' => ['nullable', 'string', 'max:255'],
            'lswd_alt_email' => ['nullable', 'string', 'max:500'],
            'lswd_alt_phone' => ['nullable', 'string', 'max:500'],
            'lswd_alt_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_position' => ['nullable', 'string', 'max:255'],
            'ldrrmo_contact' => ['nullable', 'string', 'max:255'],
            'ldrrmo_email' => ['nullable', 'string', 'max:500'],
            'ldrrmo_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_vhf' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_alt_position' => ['nullable', 'string', 'max:255'],
            'ldrrmo_alt_contact' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_email' => ['nullable', 'string', 'max:500'],
            'ldrrmo_alt_facebook' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers' => ['nullable', 'array', 'max:10'],
            'ldrrmo_officers.*.id' => ['nullable', 'integer'],
            'ldrrmo_officers.*.office' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.name' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.designation' => ['nullable', 'string', 'max:255'],
            'ldrrmo_officers.*.mobile_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.hotline_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.landline_number' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.email_address' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.alternate_email_address' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.vhf_radio_frequency' => ['nullable', 'string', 'max:500'],
            'ldrrmo_officers.*.facebook' => ['nullable', 'string', 'max:500'],
            'lswdo_alternates' => ['nullable', 'array', 'max:10'],
            'lswdo_alternates.*.id' => ['nullable', 'integer'],
            'lswdo_alternates.*.name' => ['nullable', 'string', 'max:255'],
            'lswdo_alternates.*.position' => ['nullable', 'string', 'max:255'],
            'lswdo_alternates.*.contact_number' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'ldrrmc_logo' => ['nullable', 'image', 'max:2048'],
            'lce_photo' => ['nullable', 'image', 'max:2048'],
            'lswd_photo' => ['nullable', 'image', 'max:2048'],
            'ldrrmo_photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $directory = $this->directoryFor($user);

        abort_unless($directory, 404, 'LGU directory record was not found.');

        $logoPath = $directory->lgu_logo_path;
        $ldrrmcLogoPath = $directory->ldrrmc_logo_path;

        if ($request->hasFile('logo')) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            $logoPath = $request->file('logo')->store('lgu-logos', 'public');
        }
        if ($request->hasFile('ldrrmc_logo')) {
            if ($ldrrmcLogoPath) {
                Storage::disk('public')->delete($ldrrmcLogoPath);
            }

            $ldrrmcLogoPath = $request->file('ldrrmc_logo')->store('lgu-logos', 'public');
        }

        $lcePhotoPath = $this->replaceStoredImage($request, 'lce_photo', $directory->lce_photo_path, 'lgu-officials');
        $lswdPhotoPath = $this->replaceStoredImage($request, 'lswd_photo', $directory->lswd_photo_path, 'lgu-officials');
        $ldrrmoPhotoPath = $this->replaceStoredImage($request, 'ldrrmo_photo', $directory->ldrrmo_photo_path, 'lgu-officials');

        $directory->forceFill([
            'override_lgu_name' => $validated['lgu_name'],
            'managed_district_name' => $validated['managed_district_name'] ?: $directory->managed_district_name,
            'lce_photo_path' => $lcePhotoPath,
            'lswd_photo_path' => $lswdPhotoPath,
            'ldrrmo_photo_path' => $ldrrmoPhotoPath,
            'ldrrmo_name' => $validated['ldrrmo_name'] ?? null,
            'ldrrmo_position' => $validated['ldrrmo_position'] ?? null,
            'ldrrmo_contact' => $validated['ldrrmo_contact'] ?? null,
            'ldrrmo_email' => $validated['ldrrmo_email'] ?? null,
            'lswd_email' => $validated['lswd_email'] ?? null,
            'lswd_contact_number' => $validated['lswd_phone'] ?? null,
            'lswd_alternate_email' => $validated['lswd_alt_email'] ?? null,
            'lswd_facebook' => $validated['lswd_facebook'] ?? null,
            'lswd_alternate_name' => $validated['lswd_alt_name'] ?? null,
            'lswd_alternate_position' => $validated['lswd_alt_position'] ?? null,
            'lswd_alternate_contact_number' => $validated['lswd_alt_phone'] ?? null,
            'lgu_logo_path' => $logoPath,
            'ldrrmc_logo_path' => $ldrrmcLogoPath,
        ])->save();

        $this->syncLdrrmoOfficers($directory, $validated['ldrrmo_officers'] ?? []);
        $this->syncLswdoAlternates($directory, $validated['lswdo_alternates'] ?? []);

        $this->upsertOfficial($directory, 'lce', $validated['lce_name'] ?? null, $validated['lce_position'] ?? null);
        $this->upsertOfficial($directory, 'lswd_officer', $validated['lswd_name'] ?? null, $validated['lswd_position'] ?? null);
        $this->upsertOfficial($directory, 'lswd_officer_alternate', $validated['lswd_alt_name'] ?? null, $validated['lswd_alt_position'] ?? null);
        $this->upsertOfficial($directory, 'ldrrmo_alternate', $validated['ldrrmo_alt_name'] ?? null, $validated['ldrrmo_alt_position'] ?? null);
        $this->upsertContact($directory, 'lce', 'email', $validated['lce_email'] ?? null);
        $this->upsertContact($directory, 'lce', 'phone', $validated['lce_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'email', $validated['lswd_email'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'phone', $validated['lswd_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer', 'facebook', $validated['lswd_facebook'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'email', $validated['lswd_alt_email'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'phone', $validated['lswd_alt_phone'] ?? null);
        $this->upsertContact($directory, 'lswd_officer_alternate', 'facebook', $validated['lswd_alt_facebook'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'email', $validated['ldrrmo_email'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'contact', $validated['ldrrmo_contact'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'facebook', $validated['ldrrmo_facebook'] ?? null);
        $this->upsertContact($directory, 'ldrrmo', 'vhf', $validated['ldrrmo_vhf'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'email', $validated['ldrrmo_alt_email'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'contact', $validated['ldrrmo_alt_contact'] ?? null);
        $this->upsertContact($directory, 'ldrrmo_alternate', 'facebook', $validated['ldrrmo_alt_facebook'] ?? null);

        $user->forceFill([
            'lgu_name' => $validated['lgu_name'],
        ])->save();

        return back()->with('success', 'LGU profile updated.');
    }

    private function directoryFor($user): ?LguDirectoryEntry
    {
        if ($user->lgu_psgc_code) {
            $directory = LguDirectoryEntry::query()
                ->where('psgc_code', $user->lgu_psgc_code)
                ->first();

            if ($directory) {
                return $directory;
            }
        }

        if ($user->lgu_name) {
            return LguDirectoryEntry::query()
                ->where(function ($query) use ($user): void {
                    $query->where('lgu_name', $user->lgu_name)
                        ->orWhere('override_lgu_name', $user->lgu_name);
                })
                ->first();
        }

        return null;
    }

    private function upsertOfficial(LguDirectoryEntry $directory, string $role, ?string $name, ?string $position): void
    {
        if (! filled($name) && ! filled($position)) {
            return;
        }

        $directory->officials()->updateOrCreate(
            ['role' => $role],
            [
                'name' => $name ?: 'Not encoded',
                'position_designation' => $position,
                'override_name' => $name,
                'override_position_designation' => $position,
            ],
        );
    }

    private function upsertContact(LguDirectoryEntry $directory, string $owner, string $type, ?string $value): void
    {
        if (! filled($value)) {
            return;
        }

        $directory->contacts()->updateOrCreate(
            ['owner_role' => $owner, 'contact_type' => $type],
            ['value' => $value, 'override_value' => $value],
        );
    }

    private function replaceStoredImage(Request $request, string $field, ?string $currentPath, string $directory): ?string
    {
        if (! $request->hasFile($field)) {
            return $currentPath;
        }

        if ($currentPath) {
            Storage::disk('public')->delete($currentPath);
        }

        return $request->file($field)->store($directory, 'public');
    }

    private function syncLdrrmoOfficers(LguDirectoryEntry $directory, array $officers): void
    {
        foreach (array_values($officers) as $index => $officer) {
            $values = collect($officer)->only([
                'office',
                'name',
                'designation',
                'mobile_number',
                'hotline_number',
                'landline_number',
                'email_address',
                'alternate_email_address',
                'vhf_radio_frequency',
                'facebook',
            ])->all() + [
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'is_locally_updated' => true,
            ];

            $row = filled($officer['id'] ?? null)
                ? $directory->ldrrmoOfficers()->whereKey($officer['id'])->first()
                : null;

            $row ? $row->update($values) : $directory->ldrrmoOfficers()->create($values);
        }

        $primary = $officers[0] ?? null;
        if ($primary) {
            $contact = collect([
                $primary['mobile_number'] ?? null,
                $primary['hotline_number'] ?? null,
                $primary['landline_number'] ?? null,
            ])->filter(fn ($value) => filled($value))->implode(' / ');

            $directory->forceFill([
                'ldrrmo_name' => $primary['name'] ?? null,
                'ldrrmo_position' => $primary['designation'] ?? null,
                'ldrrmo_contact' => $contact ?: null,
                'ldrrmo_email' => $primary['email_address'] ?? null,
            ])->save();
            $this->upsertContact($directory, 'ldrrmo', 'facebook', $primary['facebook'] ?? null);
            $this->upsertContact($directory, 'ldrrmo', 'vhf', $primary['vhf_radio_frequency'] ?? null);
        }
    }

    private function syncLswdoAlternates(LguDirectoryEntry $directory, array $alternates): void
    {
        foreach (array_values($alternates) as $index => $alternate) {
            $values = collect($alternate)->only(['name', 'position', 'contact_number'])->all() + [
                'sort_order' => $index,
                'is_locally_updated' => true,
            ];
            $row = filled($alternate['id'] ?? null)
                ? $directory->lswdoAlternates()->whereKey($alternate['id'])->first()
                : null;
            $row ? $row->update($values) : $directory->lswdoAlternates()->create($values);
        }

        if ($primary = $alternates[0] ?? null) {
            $directory->forceFill([
                'lswd_alternate_name' => $primary['name'] ?? null,
                'lswd_alternate_position' => $primary['position'] ?? null,
                'lswd_alternate_contact_number' => $primary['contact_number'] ?? null,
            ])->save();
            $this->upsertOfficial($directory, 'lswd_officer_alternate', $primary['name'] ?? null, $primary['position'] ?? null);
            $this->upsertContact($directory, 'lswd_officer_alternate', 'phone', $primary['contact_number'] ?? null);
        }
    }
}
