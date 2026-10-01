<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DswdDromicSectionEditor
{
    public function version(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot));
    }

    public function apply(array $snapshot, string $section, array $input): array
    {
        $changes = [];
        if ($section === 'overview') {
            $data = Validator::make($input, ['overview' => 'required|string|max:30000', 'response_actions' => 'present|nullable|string|max:30000'])->validate();
            foreach (['overview', 'response_actions'] as $key) {
                $before = $snapshot['metadata'][$key] ?? ($key === 'response_actions' ? $this->actions($snapshot) : '');
                $after = $data[$key] ?? '';
                if ($before !== $after) {
                    $changes[] = ['location' => 'Report', 'field' => $key, 'before' => $before, 'after' => $after];
                    $snapshot['metadata'][$key] = $after;
                }
            }
        } else {
            $table = $section === 'cccm' ? ($snapshot['cccm'] ?? null) : ($snapshot['tables'][$section] ?? null);
            if (! $table || $section === 'displaced') {
                throw ValidationException::withMessages(['section' => 'Choose an editable section. Total displacement is calculated from inside and outside EC figures.']);
            }
            $columns = array_keys($table['columns']);
            if (in_array($section, ['houses', 'assistance'])) {
                $columns = array_values(array_diff($columns, ['total']));
            }
            $editableLevels = in_array($section, ['age_sex', 'sectoral'], true) ? ['category'] : ['municipality'];
            $rows = collect($table['rows'])->filter(fn ($r) => in_array($r['level'], $editableLevels, true));
            $rules = ['rows' => ['required', 'array', 'size:'.$rows->count()], 'rows.*.index' => ['required', 'integer', 'distinct'], 'rows.*.values' => ['required', 'array:'.implode(',', $columns)]];
            foreach ($columns as $column) {
                $rules['rows.*.values.'.$column] = ['present', 'nullable', $section === 'assistance' ? 'numeric' : 'integer', 'min:0', 'max:'.($section === 'assistance' ? '999999999999.99' : '2147483647')];
            }
            $data = Validator::make($input, $rules)->validate();
            foreach ($data['rows'] as $row) {
                $index = $row['index'];
                if (! $rows->has($index)) {
                    throw ValidationException::withMessages(['rows' => 'Only rows from the selected review section can be edited.']);
                }
                foreach ($columns as $column) {
                    $before = $table['rows'][$index]['values'][$column];
                    $after = $row['values'][$column] === null ? null : ($section === 'assistance' ? round((float) $row['values'][$column], 2) : (int) $row['values'][$column]);
                    if (($before === null) !== ($after === null) || ($before !== null && (float) $before !== (float) $after)) {
                        $location = trim(($rows[$index]['province'] ?? '').' / '.$rows[$index]['label'], ' /');
                        $changes[] = ['location' => $location, 'field' => $column, 'before' => $before, 'after' => $after];
                    }
                    $table['rows'][$index]['values'][$column] = $after;
                }
                $values = $table['rows'][$index]['values'];
                foreach ($columns as $column) {
                    $cum = str_replace('_now', '_cum', $column);
                    if (str_ends_with($column, '_now') && $values[$column] !== null && ($values[$cum] ?? null) !== null && $values[$column] > $values[$cum]) {
                        throw ValidationException::withMessages(['rows' => 'NOW cannot exceed CUM for '.$rows[$index]['label'].' ('.$table['columns'][$column].').']);
                    }
                }
                if (in_array($section, ['houses', 'assistance'])) {
                    $table['rows'][$index]['values']['total'] = $this->sum(array_map(fn ($c) => $values[$c], $columns));
                }
            }
            if (! in_array($section, ['age_sex', 'sectoral'], true)) {
                $table = $this->rollup($table);
            }
            if ($section === 'cccm') {
                $snapshot['cccm'] = $table;
            } else {
                $snapshot['tables'][$section] = $table;
            }
            if (in_array($section, ['inside', 'outside'])) {
                foreach ($snapshot['tables']['displaced']['rows'] as $i => $row) {
                    foreach (array_keys($row['values']) as $column) {
                        $snapshot['tables']['displaced']['rows'][$i]['values'][$column] = $this->sum([
                            $snapshot['tables']['inside']['rows'][$i]['values'][$column],
                            $snapshot['tables']['outside']['rows'][$i]['values'][$column],
                        ]);
                    }
                }
            }
        }
        if (! $changes) {
            throw ValidationException::withMessages(['section' => 'No values changed. Update a value or cancel the edit.']);
        }

        return ['snapshot' => $snapshot, 'changes' => $changes];
    }

    public function actions(array $snapshot): string
    {
        return collect($snapshot['sources'])->flatMap(fn ($s) => array_map(fn ($a) => $s['municipality'].', '.$s['province'].' — '.$a['office'].': '.$a['action'], $s['actions']))->implode("\n\n");
    }

    private function sum(array $values): ?float
    {
        return ! $values || in_array(null, $values, true) ? null : array_sum($values);
    }

    private function rollup(array $table): array
    {
        $cities = collect($table['rows'])->where('level', 'municipality');
        foreach ($table['rows'] as $index => $row) {
            if ($row['level'] === 'municipality') {
                continue;
            }
            $group = $row['level'] === 'region' ? $cities : $cities->where('province', $row['province']);
            foreach (array_keys($table['columns']) as $column) {
                $table['rows'][$index]['values'][$column] = $this->sum($group->pluck('values.'.$column)->all());
            }
        }

        return $table;
    }
}
