<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PreparednessEvacuationPhotos
{
    public function driveId(array $center): ?string
    {
        $urls = [...($center['photo_urls'] ?? []), $center['photo_url'] ?? '', ...($center['photo_open_urls'] ?? []), $center['photo_open_url'] ?? ''];
        foreach ($urls as $url) {
            if (! in_array(parse_url($url, PHP_URL_HOST), ['drive.google.com', 'lh3.googleusercontent.com'], true)) {
                continue;
            }
            if (preg_match('~(?:/d/|[?&]id=)([a-zA-Z0-9_-]+)~', $url, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    public function selected(Collection $centers): array
    {
        $candidates = $centers->filter(fn (array $center): bool =>
            ($center['photo_source'] ?? '') === 'lgu_geotag_sheet'
            && strcasecmp(trim($center['availability'] ?? ''), 'Permanent') === 0
            && is_numeric($center['lat'] ?? null) && abs((float) $center['lat']) <= 90
            && is_numeric($center['lng'] ?? null) && abs((float) $center['lng']) <= 180
            && $this->driveId($center) !== null
        )->sortBy(fn (array $center): string => implode('|', [$center['province'] ?? '', $center['municipality'] ?? '', $center['name'] ?? '']));

        $selected = collect();
        // Two distinct permanent ECs from each requested LGU, in a single photo strip.
        foreach (['Mainit', 'Alegria', 'Sison'] as $municipality) {
            $selected = $selected->concat($candidates
                ->filter(fn (array $center): bool => strcasecmp(trim($center['municipality'] ?? ''), $municipality) === 0)
                ->unique('id')->take(2));
        }

        return $selected->map(fn (array $center): array => [
            'id' => $center['id'],
            'name' => $center['name'],
            'municipality' => $center['municipality'] ?? '',
            'barangay' => $center['barangay'] ?? '',
            'province' => $center['province'] ?? '',
            'status' => $center['availability'] ?? 'Unclassified',
            'lat' => (float) $center['lat'],
            'lng' => (float) $center['lng'],
            'image_url' => route('preparedness-for-response.ec-photo', ['center' => $center['id']], false),
        ])->values()->all();
    }

    public function image(array $center): array
    {
        $id = $this->driveId($center);
        abort_unless($id, 404);
        return Cache::remember('preparedness-ec-photo-'.$id, now()->addDay(), function () use ($id): array {
            $response = Http::connectTimeout(5)->timeout(20)->get('https://lh3.googleusercontent.com/d/'.$id.'=w1200');
            abort_unless($response->successful(), 502, 'EC photo is temporarily unavailable.');
            $info = @getimagesizefromstring($response->body());
            abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 502);
            return ['body' => $response->body(), 'type' => $info['mime']];
        });
    }
}
