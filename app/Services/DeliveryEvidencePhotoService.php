<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DeliveryEvidencePhotoService
{
    public function store(UploadedFile $photo, string $directory, array $metadata): string
    {
        $source = $this->loadImage($photo->getRealPath(), (string) $photo->getMimeType());
        if (! $source) return $photo->store($directory, 'local');
        $source = $this->orientImage($source, $photo->getRealPath(), (string) $photo->getMimeType());
        $width = imagesx($source); $height = imagesy($source);
        $scale = min(1, 2400 / max($width, $height));
        if ($scale < 1) {
            $resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            imagedestroy($source); $source = $resized;
        }
        $this->drawStamp($source, $metadata);
        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
        ob_start(); imagejpeg($source, null, 90); $contents = (string) ob_get_clean(); imagedestroy($source);
        Storage::disk('local')->put($path, $contents);
        return $path;
    }

    private function loadImage(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path), 'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path), default => false,
        };
    }

    private function orientImage(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) return $image;
        $rotated = match ((int) (@exif_read_data($path)['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0), 6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0), default => false,
        };
        if ($rotated !== false) { imagedestroy($image); return $rotated; }
        return $image;
    }

    private function drawStamp(\GdImage $image, array $metadata): void
    {
        $width = imagesx($image); $height = imagesy($image);
        $outer = max(16, (int) ($width * .018));
        $panelWidth = $width - ($outer * 2);
        $padding = max(18, (int) ($width * .017));

        // Size the overlay around its actual content. The old fixed 34% height
        // left a large empty dark area on portrait photos.
        $mapTarget = min((int) ($panelWidth * .29), 390);
        $panelHeight = max(240, $mapTarget + ($padding * 2));
        $panelHeight = min($panelHeight, max(240, $height - ($outer * 2)));
        $panelX = $outer; $panelY = $height - $panelHeight - $outer;
        imagefilledrectangle($image, $panelX + 8, $panelY + 9, $panelX + $panelWidth + 8, $panelY + $panelHeight + 9, imagecolorallocatealpha($image, 0, 0, 0, 75));
        for ($row = 0; $row < $panelHeight; $row++) {
            $shade = imagecolorallocatealpha($image, 7, 20, 37, 24 + (int) (($row / max(1, $panelHeight)) * 11));
            imageline($image, $panelX, $panelY + $row, $panelX + $panelWidth, $panelY + $row, $shade);
        }
        $mapSize = min($panelHeight - ($padding * 2), (int) ($panelWidth * .29), 390);
        $mapX = $panelX + $panelWidth - $padding - $mapSize; $mapY = $panelY + $padding;
        $provider = $this->drawMapInset($image, $mapX, $mapY, $mapSize, (float) ($metadata['latitude'] ?? 0), (float) ($metadata['longitude'] ?? 0));
        $textX = $panelX + $padding + max(10, (int) ($width * .012)); $textWidth = max(160, $mapX - $textX - $padding);
        $font = $this->fontPath(); $fontSize = max(20, min(42, (int) ($width / 44)));
        $smallSize = max(15, (int) ($fontSize * .72)); $labelSize = max(12, (int) ($smallSize * .76));
        $white = imagecolorallocate($image, 255, 255, 255); $muted = imagecolorallocate($image, 220, 228, 238);
        [$r, $g, $b] = $this->stageColor((string) ($metadata['stage'] ?? '')); $accent = imagecolorallocate($image, $r, $g, $b);
        $stage = Str::headline((string) ($metadata['stage'] ?? 'Delivery update'));
        $occurredAt = Carbon::parse($metadata['occurred_at'] ?? now())->timezone(config('app.timezone'))->format('M d, Y | h:i A');
        $location = trim((string) ($metadata['location'] ?? 'Location unavailable'));
        $latitude = number_format((float) ($metadata['latitude'] ?? 0), 7);
        $longitude = number_format((float) ($metadata['longitude'] ?? 0), 7);
        imagefilledrectangle($image, $panelX, $panelY, $panelX + max(7, (int) ($width * .006)), $panelY + $panelHeight, $accent);
        $this->text($image, 'FIELD EVIDENCE  /  '.strtoupper($provider), $textX, $panelY + $padding + $labelSize, $labelSize, $muted, $font, $textWidth, true);
        $stageText = strtoupper($stage);
        $stageFontSize = $this->singleLineFontSize($stageText, $fontSize, $font, $textWidth);
        $badgeY = $panelY + $padding + $labelSize + 15; $badgeHeight = (int) ($stageFontSize * 1.4);
        // Stage names such as "UNLOADING STARTED" must remain a single visual
        // label. Fit the font to the available column instead of wrapping it.
        $this->text($image, $stageText, $textX, $badgeY + $stageFontSize + 4, $stageFontSize, $white, $font, $textWidth, true);
        $y = $badgeY + $badgeHeight + $smallSize + 18;
        $this->drawClockIcon($image, $textX + 9, $y - 7, $accent);
        $y = $this->text($image, $occurredAt, $textX + 28, $y, $smallSize, $white, $font, $textWidth - 28, true) + 10;
        $this->drawLocationIcon($image, $textX + 9, $y - 8, $accent);
        $y = $this->text($image, $location, $textX + 28, $y, $smallSize, $white, $font, $textWidth - 28) + 6;
        $y = $this->text($image, 'LATITUDE: '.$latitude, $textX + 28, $y, $labelSize, $muted, $font, $textWidth - 28, true) + 2;
        $this->text($image, 'LONGITUDE: '.$longitude, $textX + 28, $y, $labelSize, $muted, $font, $textWidth - 28, true);
    }

    private function drawMapInset(\GdImage $target, int $x, int $y, int $size, float $latitude, float $longitude): string
    {
        $zoom = 16; $n = 2 ** $zoom;
        $tileXFloat = ($longitude + 180) / 360 * $n; $tileX = (int) floor($tileXFloat);
        $latRad = deg2rad(max(-85.0511, min(85.0511, $latitude)));
        $tileYFloat = (1 - log(tan($latRad) + (1 / cos($latRad))) / pi()) / 2 * $n; $tileY = (int) floor($tileYFloat);
        [$mosaic, $provider, $attribution] = $this->mapMosaic($zoom, $tileX, $tileY);
        $centerX = 256 + (($tileXFloat - $tileX) * 256); $centerY = 256 + (($tileYFloat - $tileY) * 256);
        $viewport = 512; $sourceX = max(0, min(256, (int) round($centerX - 256))); $sourceY = max(0, min(256, (int) round($centerY - 256)));
        $map = imagecreatetruecolor($viewport, $viewport); imagecopy($map, $mosaic, 0, 0, $sourceX, $sourceY, $viewport, $viewport); imagedestroy($mosaic);
        $pinX = (int) round($centerX - $sourceX); $pinY = (int) round($centerY - $sourceY);
        imagefilledellipse($map, $pinX + 4, $pinY + 8, 58, 26, imagecolorallocatealpha($map, 0, 0, 0, 70));
        $red = imagecolorallocate($map, 233, 45, 62); $darkRed = imagecolorallocate($map, 145, 17, 31);
        imagefilledpolygon($map, [$pinX - 18, $pinY + 13, $pinX + 18, $pinY + 13, $pinX, $pinY + 52], 3, $darkRed);
        imagefilledellipse($map, $pinX, $pinY, 52, 52, $darkRed); imagefilledellipse($map, $pinX, $pinY - 2, 43, 43, $red);
        imagefilledellipse($map, $pinX, $pinY - 2, 14, 14, imagecolorallocate($map, 255, 255, 255));
        imagefilledrectangle($map, 0, 485, 512, 512, imagecolorallocatealpha($map, 255, 255, 255, 22));
        imagestring($map, 2, 8, 491, $attribution, imagecolorallocate($map, 34, 44, 56));
        imagefilledrectangle($target, $x + 7, $y + 8, $x + $size + 7, $y + $size + 8, imagecolorallocatealpha($target, 0, 0, 0, 65));
        imagecopyresampled($target, $map, $x, $y, 0, 0, $size, $size, 512, 512); imagedestroy($map);
        imagesetthickness($target, max(3, (int) ($size / 90))); imagerectangle($target, $x, $y, $x + $size, $y + $size, imagecolorallocate($target, 255, 255, 255));
        return $provider;
    }

    private function mapMosaic(int $zoom, int $centerX, int $centerY): array
    {
        $providers = [
            ['OpenStreetMap', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'OpenStreetMap contributors'],
            ['CARTO', 'https://a.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', 'OpenStreetMap / CARTO'],
            ['Humanitarian', 'https://b.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', 'OpenStreetMap / HOT'],
        ];
        foreach ($providers as [$provider, $template, $attribution]) {
            $mosaic = imagecreatetruecolor(768, 768); $complete = true;
            for ($oy = -1; $oy <= 1 && $complete; $oy++) for ($ox = -1; $ox <= 1; $ox++) {
                $tile = $this->mapTile($provider, $template, $zoom, $centerX + $ox, $centerY + $oy);
                if (! $tile) { $complete = false; break; }
                imagecopyresampled($mosaic, $tile, ($ox + 1) * 256, ($oy + 1) * 256, 0, 0, 256, 256, imagesx($tile), imagesy($tile)); imagedestroy($tile);
            }
            if ($complete) return [$mosaic, $provider, $attribution];
            imagedestroy($mosaic);
        }
        throw new \RuntimeException('Map imagery could not be loaded. Check the connection and try uploading the evidence photo again.');
    }

    private function mapTile(string $provider, string $template, int $zoom, int $x, int $y): \GdImage|false
    {
        $key = 'delivery-evidence-map-tile:v2:'.Str::slug($provider).":{$zoom}:{$x}:{$y}";
        $url = str_replace(['{z}', '{x}', '{y}'], [$zoom, $x, $y], $template);
        $bytes = Cache::remember($key, now()->addDays(7), function () use ($url) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'DROMIS-FO-Caraga/1.0 (delivery evidence)'])->connectTimeout(2)->timeout(5)->retry(2, 150)->get($url);
                return $response->successful() ? $response->body() : null;
            } catch (\Throwable) { return null; }
        });
        return $bytes ? @imagecreatefromstring($bytes) : false;
    }

    private function stageColor(string $stage): array
    {
        return match ($stage) {
            'departed' => [72, 137, 255], 'checkpoint' => [36, 205, 178], 'arrived' => [42, 190, 112],
            'unloading_started' => [246, 174, 45], 'unloading_completed' => [25, 202, 211],
            'delay', 'incident' => [242, 78, 91], default => [103, 232, 205],
        };
    }

    private function drawClockIcon(\GdImage $image, int $x, int $y, int $color): void
    { imageellipse($image, $x, $y, 17, 17, $color); imageline($image, $x, $y, $x, $y - 5, $color); imageline($image, $x, $y, $x + 5, $y + 3, $color); }

    private function drawLocationIcon(\GdImage $image, int $x, int $y, int $color): void
    { imageellipse($image, $x, $y - 2, 16, 16, $color); imagefilledpolygon($image, [$x - 7, $y + 3, $x + 7, $y + 3, $x, $y + 14], 3, $color); imagefilledellipse($image, $x, $y - 2, 5, 5, imagecolorallocate($image, 7, 20, 37)); }

    private function text(\GdImage $image, string $text, int $x, int $y, int $size, int $color, ?string $font, int $maxWidth, bool $bold = false): int
    {
        if (! $font || ! function_exists('imagettftext')) { imagestring($image, 5, $x, $y, $text, $color); return $y + 22; }
        $words = preg_split('/\s+/', trim($text)) ?: []; $lines = [''];
        foreach ($words as $word) {
            $candidate = trim(end($lines).' '.$word); $box = imagettfbbox($size, 0, $font, $candidate);
            if ($box && ($box[2] - $box[0]) > $maxWidth && end($lines) !== '') $lines[] = $word; else $lines[array_key_last($lines)] = $candidate;
        }
        $lineHeight = (int) ($size * 1.28);
        foreach ($lines as $line) { imagettftext($image, $size, 0, $x, $y, $color, $font, $line); if ($bold) imagettftext($image, $size, 0, $x + 1, $y, $color, $font, $line); $y += $lineHeight; }
        return $y;
    }

    private function singleLineFontSize(string $text, int $preferredSize, ?string $font, int $maxWidth): int
    {
        if (! $font || ! function_exists('imagettfbbox')) return $preferredSize;
        for ($size = $preferredSize; $size >= 14; $size--) {
            $box = imagettfbbox($size, 0, $font, $text);
            if ($box && ($box[2] - $box[0]) <= $maxWidth) return $size;
        }
        return 14;
    }

    private function fontPath(): ?string
    {
        foreach (['C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'] as $path) if (is_file($path)) return $path;
        return null;
    }
}
