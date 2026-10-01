<?php

namespace App\Http\Controllers;

use App\Models\PreparednessBriefingIntro;
use App\Models\PreparednessActionPage;
use App\Models\PreparednessQrtCoverageArea;
use App\Models\PreparednessQrtSpecialization;
use App\Models\PreparednessResponseAsset;
use App\Models\PreparednessReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PreparednessDataController extends Controller
{
    public function storeActionPage(Request $request, PreparednessReport $report): RedirectResponse
    {
        $this->authorizeDraft($request, $report);
        PreparednessActionPage::query()->create([
            'preparedness_report_id' => $report->id,
            'actions' => ['', '', ''],
            'captions' => ['', '', ''],
            'sort_order' => ((int) PreparednessActionPage::query()->where('preparedness_report_id', $report->id)->max('sort_order')) + 1,
        ]);

        return back()->with('success', 'Actions Taken page added.');
    }

    public function updateActionPage(Request $request, PreparednessReport $report, PreparednessActionPage $actionPage): RedirectResponse
    {
        $this->authorizeDraft($request, $report);
        abort_unless((int) $actionPage->preparedness_report_id === $report->id, 404);
        $data = $request->validate([
            'actions' => ['required', 'array', 'min:2', 'max:3'],
            'actions.*' => ['nullable', 'string', 'max:2000'],
            'captions' => ['required', 'array', 'min:2', 'max:3'],
            'captions.*' => ['nullable', 'string', 'max:500'],
            'existing_image_paths' => ['nullable', 'array', 'max:3'],
            'existing_image_paths.*' => ['nullable', 'string', 'max:500'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:15360'],
        ]);
        $actionCount = count($data['actions']);
        $attributes = [
            'actions' => array_values(array_slice($data['actions'], 0, $actionCount)),
            'captions' => array_values(array_slice($data['captions'], 0, $actionCount)),
        ];
        $imagePaths = array_pad(array_slice($data['existing_image_paths'] ?? $actionPage->image_paths ?? [$actionPage->image_path], 0, $actionCount), $actionCount, null);
        foreach ($request->file('images', []) as $index => $image) {
            if ($image) {
                $imagePaths[(int) $index] = '/storage/'.$image->store('preparedness-actions', 'public');
            }
        }
        $attributes['image_paths'] = array_values(array_slice($imagePaths, 0, $actionCount));
        $actionPage->update($attributes);

        return back()->with('success', 'Actions Taken page updated.');
    }

    public function destroyActionPage(Request $request, PreparednessReport $report, PreparednessActionPage $actionPage): RedirectResponse
    {
        $this->authorizeDraft($request, $report);
        abort_unless((int) $actionPage->preparedness_report_id === $report->id, 404);
        abort_if(PreparednessActionPage::query()->where('preparedness_report_id', $report->id)->count() <= 2, 422, 'At least two Actions Taken pages are required.');
        $actionPage->delete();

        return back()->with('success', 'Actions Taken page removed.');
    }

    public function update(Request $request, PreparednessReport $report, string $section): RedirectResponse
    {
        $this->authorizeDraft($request, $report);

        if ($section === 'briefing-intro') {
            $data = $request->validate([
                'content' => ['required', 'array'],
                'content.*' => ['nullable', 'string', 'max:10000'],
                'synopsis_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:15360'],
                'dashboard_snapshot' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:15360'],
            ]);
            $intro = PreparednessBriefingIntro::query()->firstOrCreate(['preparedness_report_id' => $report->id], ['content' => []]);
            $attributes = ['content' => $data['content']];
            if ($request->hasFile('synopsis_image')) {
                $attributes['synopsis_image_path'] = '/storage/'.$request->file('synopsis_image')->store('preparedness-intro', 'public');
            }
            if ($request->hasFile('dashboard_snapshot')) {
                $attributes['dashboard_snapshot_path'] = '/storage/'.$request->file('dashboard_snapshot')->store('preparedness-intro', 'public');
            }
            $intro->update($attributes);
            if (filled($data['content']['title_event'] ?? null)) {
                $report->update(['title' => $data['content']['title_event']]);
            }

            return back()->with('success', 'Briefing introductory pages updated.');
        }

        [$model, $identity, $rules, $message] = match ($section) {
            'response-assets' => [
                PreparednessResponseAsset::class,
                'label',
                [
                    'rows.*.label' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
                    'rows.*.quantity' => ['required', 'integer', 'min:0'],
                    'rows.*.status' => ['required', Rule::in(['On Standby', 'Deployed', 'Demobilized'])],
                    'rows.*.image_path' => ['nullable', 'string', 'max:255'],
                    'rows.*.image_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
                ],
                'Mobile response vehicles and equipment updated.',
            ],
            'qrt-coverage' => [
                PreparednessQrtCoverageArea::class,
                'area',
                [
                    'rows.*.area' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
                    'rows.*.coverage_type' => ['required', 'string', 'max:100'],
                    'rows.*.members' => ['required', 'integer', 'min:0'],
                ],
                'QRT coverage data updated.',
            ],
            'qrt-specializations' => [
                PreparednessQrtSpecialization::class,
                'specialization',
                [
                    'rows.*.specialization' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
                    'rows.*.members' => ['required', 'integer', 'min:0'],
                ],
                'QRT specializations updated.',
            ],
            default => abort(404),
        };

        $data = $request->validate([
            'rows' => ['present', 'array', 'max:100'],
            'rows.*.id' => ['nullable', 'integer'],
            ...$rules,
        ]);

        if ($model === PreparednessResponseAsset::class) {
            $missingUploads = collect($data['rows'])
                ->keys()
                ->filter(fn (int $index): bool => blank($data['rows'][$index]['id'] ?? null)
                    && ! isset($data['rows'][$index]['image_file']));

            if ($missingUploads->isNotEmpty()) {
                throw ValidationException::withMessages(
                    $missingUploads->mapWithKeys(fn (int $index): array => [
                        "rows.{$index}.image_file" => 'Upload an image for each new item or equipment row.',
                    ])->all()
                );
            }
        }

        DB::transaction(function () use ($data, $model, $identity, $report): void {
            $keptIds = collect();

            foreach ($data['rows'] as $index => $row) {
                $isExistingSubmission = filled($row['id'] ?? null);
                $attributes = collect($row)
                    ->except(['id', 'image_file'])
                    ->put('sort_order', $index + 1)
                    ->all();
                $record = filled($row['id'] ?? null)
                    ? $model::query()->where('preparedness_report_id', $report->id)->findOrFail($row['id'])
                    : $model::withTrashed()->where('preparedness_report_id', $report->id)->where($identity, $row[$identity])->first();

                if ($record) {
                    if ($model === PreparednessResponseAsset::class) {
                        unset($attributes['image_path']);
                        if (! $isExistingSubmission) {
                            $attributes['image_path'] = '/storage/'.$row['image_file']->store('preparedness-assets', 'public');
                        }
                    }
                    if (method_exists($record, 'trashed') && $record->trashed()) {
                        $record->restore();
                    }
                    $record->update($attributes);
                } else {
                    if ($model === PreparednessResponseAsset::class) {
                        $attributes['image_path'] = isset($row['image_file'])
                            ? '/storage/'.$row['image_file']->store('preparedness-assets', 'public')
                            : null;
                    }
                    /** @var Model $record */
                    $record = $model::query()->create([...$attributes, 'preparedness_report_id' => $report->id]);
                }

                $keptIds->push($record->getKey());
            }

            $deleteQuery = $model::query()->where('preparedness_report_id', $report->id);
            if ($keptIds->isNotEmpty()) {
                $deleteQuery->whereNotIn('id', $keptIds);
            }
            $deleteQuery->delete();
        });

        return back()->with('success', $message);
    }

    private function authorizeDraft(Request $request, PreparednessReport $report): void
    {
        abort_unless($request->user()?->hasRole('DRIMS'), 403);
        abort_unless($report->isDraft(), 422, 'Finalized reports are read-only.');
        abort_unless($report->canBeRevised(), 422, 'The reporting schedule has passed. This report can no longer be revised.');
    }
}
