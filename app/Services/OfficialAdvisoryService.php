<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OfficialAdvisoryService
{
    private const PAGASA_WEATHER_URL = 'https://www.pagasa.dost.gov.ph/weather';

    private const PHIVOLCS_EARTHQUAKE_URL = 'https://earthquake.phivolcs.dost.gov.ph/EQLatest.html';

    public function lookup(array $context): array
    {
        $incidentText = Str::lower(implode(' ', array_filter([
            $context['incident_type'] ?? null,
            $context['incident_name'] ?? null,
            $context['incident_details'] ?? null,
        ])));
        $incidentDate = $this->date($context['incident_date'] ?? null);
        $location = trim(implode(', ', array_filter([
            $context['municipality'] ?? null,
            $context['province'] ?? null,
        ])));
        $recommendations = $this->recommendations($incidentText);
        $candidates = [];

        if ($incidentDate && $this->withinDaysOfToday($incidentDate, 2) && $this->hasAutomaticAgency($recommendations, 'PAGASA')) {
            $candidate = $this->pagasaCandidate($incidentDate, $location);
            if ($candidate) {
                $candidates[] = $candidate;
            }
        }

        if ($incidentDate && $this->withinDaysOfToday($incidentDate, 45) && $this->hasAutomaticAgency($recommendations, 'DOST-PHIVOLCS')) {
            $candidates = [...$candidates, ...$this->phivolcsCandidates($incidentDate, $location)];
        }

        return [
            'recommendations' => $recommendations,
            'candidates' => $candidates,
            'incident_date' => $incidentDate?->toDateString(),
            'location' => $location,
            'notice' => 'Official-source candidates are reference leads only. The LGU encoder must open the source, confirm that its date and coverage apply to the incident and locality, and review the excerpt before including it in the Situation Overview.',
        ];
    }

    public function importFromUrl(string $url, array $context = []): array
    {
        $sourceUrl = $this->normalizeAndValidateSourceUrl($url);
        $response = $this->fetchSource($sourceUrl);
        $contentType = Str::lower((string) $response->header('Content-Type'));
        $isFacebook = $this->isFacebookHost((string) parse_url($sourceUrl, PHP_URL_HOST));
        $title = '';
        $summary = '';
        $issuedAt = '';

        if (Str::contains($contentType, ['text/html', 'application/xhtml+xml']) || Str::contains(Str::lower($response->body()), '<html')) {
            $page = $this->extractPageContent($response->body());
            $title = $page['title'];
            $summary = $page['summary'];
            $issuedAt = $page['issued_at'];
        } elseif (Str::contains($contentType, 'application/pdf')) {
            $title = Str::headline(pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_FILENAME));
        }

        $contentForAgency = implode(' ', [$sourceUrl, $title, $summary]);
        $agency = $this->agencyFromSource($sourceUrl, $contentForAgency, (string) ($context['incident_type'] ?? ''));
        $readable = filled($summary);
        $location = trim(implode(', ', array_filter([
            $context['municipality'] ?? null,
            $context['province'] ?? null,
        ])));

        return [
            'source' => [
                'agency' => $agency,
                'advisory_title' => $title ?: $this->defaultSourceTitle($agency, $isFacebook),
                'issued_at' => $issuedAt,
                'covered_location' => $location,
                'summary' => $summary,
                'source_url' => $sourceUrl,
                'match_basis' => $readable
                    ? 'Page text was extracted from the supplied link. The LGU must verify the page owner, issuance date, locality, and excerpt before generating the report.'
                    : 'The link was saved as a reference, but its contents could not be read automatically. Groq will not infer or quote the post unless a verified excerpt is supplied.',
                'content_status' => $readable ? 'extracted_for_review' : 'reference_only',
            ],
            'notice' => $readable
                ? 'The source was imported. Review the extracted excerpt and confirm that the page or account is official before using it.'
                : ($isFacebook
                    ? 'The Facebook link was saved, but Meta did not expose readable post text. Verify the official page and paste the relevant post text if you want Groq to use its contents.'
                    : 'The official link was saved as a reference. Its contents could not be read automatically, so Groq will not infer information from the URL alone.'),
        ];
    }

    private function recommendations(string $incidentText): array
    {
        $items = [];
        $weather = $this->containsAny($incidentText, [
            'typhoon', 'tropical cyclone', 'tropical depression', 'tropical storm', 'storm',
            'low pressure', 'lpa', 'monsoon', 'habagat', 'amihan', 'rain', 'flood',
            'flash flood', 'thunderstorm', 'tornado', 'storm surge', 'gale', 'drought',
            'el niño', 'la niña', 'weather',
        ]);
        $earthquake = $this->containsAny($incidentText, ['earthquake', 'seismic', 'ground shaking']);
        $volcanic = $this->containsAny($incidentText, ['volcano', 'volcanic', 'eruption', 'ashfall', 'lahar']);
        $tsunami = $this->containsAny($incidentText, ['tsunami']);
        $landslide = $this->containsAny($incidentText, ['landslide', 'debris flow', 'sinkhole', 'ground subsidence']);
        $fire = $this->containsAny($incidentText, ['fire', 'conflagration']);
        $health = $this->containsAny($incidentText, ['disease', 'outbreak', 'epidemic', 'health', 'dengue', 'cholera', 'measles']);
        $maritime = $this->containsAny($incidentText, ['maritime', 'sea incident', 'vessel', 'boat', 'ship', 'drowning']);
        $environmental = $this->containsAny($incidentText, ['oil spill', 'chemical spill', 'pollution', 'environmental']);

        if ($weather) {
            $items[] = $this->recommendation(
                'PAGASA',
                'Weather forecast, tropical cyclone, rainfall, flood, gale, or storm-surge bulletin',
                self::PAGASA_WEATHER_URL,
                'Use the PAGASA issuance whose validity period and forecast area cover the incident date and LGU location.',
                true,
            );
        }
        if ($earthquake || $tsunami) {
            $items[] = $this->recommendation(
                'DOST-PHIVOLCS',
                $tsunami ? 'Tsunami advisory and earthquake information' : 'Earthquake information bulletin',
                self::PHIVOLCS_EARTHQUAKE_URL,
                'Match the event date, epicentral location, magnitude, and reported or instrumental intensity applicable to the LGU.',
                true,
            );
        } elseif ($volcanic) {
            $items[] = $this->recommendation(
                'DOST-PHIVOLCS',
                'Volcano bulletin',
                'https://www.phivolcs.dost.gov.ph/',
                'Use the bulletin for the affected volcano and applicable alert level; do not infer local effects from the alert level alone.',
            );
        }
        if ($landslide || $this->containsAny($incidentText, ['flood', 'flash flood'])) {
            $items[] = $this->recommendation(
                'DENR-MGB Region XIII',
                'Caraga geohazard advisory or susceptibility assessment',
                'https://caraga.mgb.gov.ph/',
                'Use MGB information for landslide, debris-flow, subsidence, and flood susceptibility; keep observed LGU impacts separate from hazard susceptibility.',
            );
        }
        if ($fire) {
            $items[] = $this->recommendation(
                'Bureau of Fire Protection',
                'BFP spot, progress, or final fire investigation report',
                'https://caraga.bfp.gov.ph/',
                'Encode only details confirmed by the responding fire station, such as alarm, fire-out declaration, structures affected, casualties, and investigation status.',
            );
        }
        if ($health) {
            $items[] = $this->recommendation(
                'DOH Center for Health Development Caraga',
                'Health advisory, surveillance update, or outbreak report',
                'https://caraga.doh.gov.ph/',
                'Use confirmed case definitions and surveillance figures from DOH or the authorized local health office.',
            );
        }
        if ($maritime) {
            $items[] = $this->recommendation(
                'Philippine Coast Guard',
                'Maritime incident, search-and-rescue, or sea-travel advisory',
                'https://www.coastguard.gov.ph/',
                'Use the advisory or incident update from the PCG district or station with jurisdiction over the location.',
            );
        }
        if ($environmental) {
            $items[] = $this->recommendation(
                'DENR-EMB Region XIII',
                'Environmental incident or pollution advisory',
                'https://caraga.emb.gov.ph/',
                'Use verified monitoring results or official incident updates; distinguish measured environmental conditions from community observations.',
            );
        }
        if ($items === []) {
            $items[] = $this->recommendation(
                'OCD/NDRRMC and responsible lead agency',
                'Official incident update or situational report',
                'https://ndrrmc.gov.ph/20-incidents-monitored.html',
                'Identify the lead technical agency for the incident and attach its verified report. If none is publicly available, use validated BDRRMC/LDRRMO, police, health, engineering, or responder records.',
            );
        }

        return $items;
    }

    private function pagasaCandidate(CarbonImmutable $incidentDate, string $location): ?array
    {
        try {
            $response = Http::timeout(12)->retry(1, 300)->accept('text/html')->get(self::PAGASA_WEATHER_URL);
            if (! $response->successful()) {
                return null;
            }

            $document = $this->document($response->body());
            $xpath = new DOMXPath($document);
            $text = $this->cleanText($document->textContent);
            preg_match('/Issued at:\s*(.+?)\s+Synopsis/is', $text, $issuedMatch);
            preg_match('/Synopsis\s+(.+?)\s+Forecast Weather Conditions/is', $text, $synopsisMatch);
            $forecast = null;

            foreach ($xpath->query('//tr') ?: [] as $row) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                    $cells[] = $this->cleanText($cell->textContent);
                }
                if (count($cells) < 4 || ! $this->forecastAreaMatches($cells[0], $location)) {
                    continue;
                }
                $forecast = [
                    'place' => $cells[0],
                    'weather' => $cells[1],
                    'caused_by' => $cells[2],
                    'impacts' => $cells[3],
                ];
                break;
            }

            $synopsis = trim($synopsisMatch[1] ?? '');
            if ($synopsis === '' && ! $forecast) {
                return null;
            }

            $summary = collect([
                $synopsis !== '' ? 'PAGASA synopsis: '.$synopsis : null,
                $forecast ? "Forecast for {$forecast['place']}: {$forecast['weather']}. Caused by {$forecast['caused_by']}. {$forecast['impacts']}" : null,
            ])->filter()->implode(' ');

            return [
                'agency' => 'PAGASA',
                'advisory_title' => 'PAGASA Daily Weather Forecast',
                'issued_at' => trim($issuedMatch[1] ?? $incidentDate->format('j F Y')),
                'covered_location' => $forecast['place'] ?? $location,
                'summary' => Str::limit($summary, 1800, ''),
                'source_url' => self::PAGASA_WEATHER_URL,
                'match_basis' => 'Current official PAGASA issuance; verify its validity period against the incident date before use.',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function phivolcsCandidates(CarbonImmutable $incidentDate, string $location): array
    {
        try {
            $response = Http::timeout(12)->retry(1, 300)->accept('text/html')->get(self::PHIVOLCS_EARTHQUAKE_URL);
            if (! $response->successful()) {
                return [];
            }

            $document = $this->document($response->body());
            $xpath = new DOMXPath($document);
            $candidates = [];

            foreach ($xpath->query('//tr') ?: [] as $row) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                    $cells[] = $this->cleanText($cell->textContent);
                }
                if (count($cells) < 6) {
                    continue;
                }

                $eventDate = $this->date($cells[0]);
                if (! $eventDate || abs($eventDate->diffInDays($incidentDate, false)) > 1) {
                    continue;
                }
                if (! $this->locationMatches($cells[5], $location)) {
                    continue;
                }

                $anchor = $xpath->query('.//a[@href]', $row)?->item(0);
                $sourceUrl = $anchor?->attributes?->getNamedItem('href')?->nodeValue;
                if ($sourceUrl && ! Str::startsWith($sourceUrl, ['http://', 'https://'])) {
                    $sourceUrl = 'https://earthquake.phivolcs.dost.gov.ph/'.ltrim($sourceUrl, '/');
                }

                $candidates[] = [
                    'agency' => 'DOST-PHIVOLCS',
                    'advisory_title' => 'PHIVOLCS Earthquake Information',
                    'issued_at' => $cells[0],
                    'covered_location' => $cells[5],
                    'summary' => "PHIVOLCS listed a magnitude {$cells[4]} earthquake at {$cells[5]}, with a depth of focus of {$cells[3]} km.",
                    'source_url' => $sourceUrl ?: self::PHIVOLCS_EARTHQUAKE_URL,
                    'match_basis' => 'Matched against the encoded incident date and LGU municipality/province. Open the bulletin to verify reported intensity for the LGU.',
                ];

                if (count($candidates) >= 5) {
                    break;
                }
            }

            return $candidates;
        } catch (\Throwable) {
            return [];
        }
    }

    private function recommendation(string $agency, string $product, string $url, string $reason, bool $automatic = false): array
    {
        return compact('agency', 'product', 'url', 'reason', 'automatic');
    }

    private function hasAutomaticAgency(array $recommendations, string $agency): bool
    {
        return collect($recommendations)->contains(
            fn (array $item): bool => $item['agency'] === $agency && ($item['automatic'] ?? false),
        );
    }

    private function containsAny(string $value, array $needles): bool
    {
        return collect($needles)->contains(fn (string $needle): bool => Str::contains($value, $needle));
    }

    private function fetchSource(string $url): Response
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $response = Http::timeout(15)
                ->retry(1, 300)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/pdf;q=0.9',
                    'User-Agent' => 'Mozilla/5.0 (compatible; DRIMS-Official-Source-Importer/1.0)',
                ])
                ->withOptions(['allow_redirects' => false])
                ->get($currentUrl);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! $location || $redirects === 3) {
                    throw new InvalidArgumentException('The source redirected too many times.');
                }
                $currentUrl = $this->normalizeAndValidateSourceUrl($this->resolveRedirectUrl($currentUrl, $location));

                continue;
            }

            if (! $response->successful()) {
                throw new InvalidArgumentException("The source could not be opened (HTTP {$response->status()}).");
            }

            return $response;
        }

        throw new InvalidArgumentException('The source could not be opened.');
    }

    private function normalizeAndValidateSourceUrl(string $url): string
    {
        $normalized = trim($url);
        if (! preg_match('#^https?://#i', $normalized)) {
            $normalized = 'https://'.$normalized;
        }

        if (! filter_var($normalized, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Enter a valid official website or Facebook post link.');
        }

        $scheme = Str::lower((string) parse_url($normalized, PHP_URL_SCHEME));
        $host = Str::lower(rtrim((string) parse_url($normalized, PHP_URL_HOST), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isSupportedSourceHost($host)) {
            throw new InvalidArgumentException('For weather and earthquake sources, use an official PAGASA/PHIVOLCS website link or a Facebook post link.');
        }

        if (parse_url($normalized, PHP_URL_USER) !== null || parse_url($normalized, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException('Source links containing embedded credentials are not allowed.');
        }

        return $normalized;
    }

    private function isSupportedSourceHost(string $host): bool
    {
        return $this->hostIsOrEndsWith($host, 'pagasa.dost.gov.ph')
            || $this->hostIsOrEndsWith($host, 'phivolcs.dost.gov.ph')
            || $this->isFacebookHost($host);
    }

    private function isFacebookHost(string $host): bool
    {
        $host = Str::lower(rtrim($host, '.'));

        return $this->hostIsOrEndsWith($host, 'facebook.com')
            || $host === 'fb.watch';
    }

    private function hostIsOrEndsWith(string $host, string $trustedHost): bool
    {
        return $host === $trustedHost || Str::endsWith($host, '.'.$trustedHost);
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');

        if (Str::startsWith($location, '//')) {
            return $scheme.':'.$location;
        }
        if (Str::startsWith($location, '/')) {
            return $origin.$location;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin.($directory ? '/'.ltrim($directory, '/') : '').'/'.$location;
    }

    private function extractPageContent(string $html): array
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $meta = function (string $query) use ($xpath): string {
            $node = $xpath->query($query)?->item(0);

            return $this->cleanText($node?->attributes?->getNamedItem('content')?->nodeValue);
        };
        $title = $meta('//meta[@property="og:title"]')
            ?: $meta('//meta[@name="twitter:title"]')
            ?: $this->cleanText($xpath->query('//title')?->item(0)?->textContent);
        $description = $meta('//meta[@property="og:description"]')
            ?: $meta('//meta[@name="description"]')
            ?: $meta('//meta[@name="twitter:description"]');

        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $contentNode = $xpath->query('//article')?->item(0)
            ?: $xpath->query('//main')?->item(0)
            ?: $xpath->query('//body')?->item(0);
        $bodyText = $this->cleanText($contentNode?->textContent);
        $summary = $description;
        if (mb_strlen($summary) < 80 && mb_strlen($bodyText) >= 80) {
            $summary = $bodyText;
        }
        $summary = Str::limit($summary, 2400, '');

        $dateText = implode(' ', [$title, $description, Str::limit($bodyText, 800, '')]);
        preg_match(
            '/(?:Issued(?:\s+at)?|Posted(?:\s+on)?|Updated(?:\s+on)?|As of)\s*:?\s*([A-Za-z0-9,:\-\s]+?(?:AM|PM|\d{4}))/iu',
            $dateText,
            $issuedMatch,
        );

        return [
            'title' => Str::limit($title, 255, ''),
            'summary' => $summary,
            'issued_at' => Str::limit($this->cleanText($issuedMatch[1] ?? ''), 120, ''),
        ];
    }

    private function agencyFromSource(string $url, string $content, string $incidentType): string
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        if ($this->hostIsOrEndsWith($host, 'pagasa.dost.gov.ph')) {
            return 'PAGASA';
        }
        if ($this->hostIsOrEndsWith($host, 'phivolcs.dost.gov.ph')) {
            return 'DOST-PHIVOLCS';
        }

        $haystack = Str::lower($content);
        if (Str::contains($haystack, ['pagasa', 'dost_pagasa'])) {
            return 'PAGASA';
        }
        if (Str::contains($haystack, ['phivolcs', 'dost-phivolcs', 'dost_phivolcs'])) {
            return 'DOST-PHIVOLCS';
        }

        return Str::contains(Str::lower($incidentType), ['earthquake', 'seismic', 'tsunami'])
            ? 'Facebook source — verify DOST-PHIVOLCS page owner'
            : 'Facebook source — verify PAGASA page owner';
    }

    private function defaultSourceTitle(string $agency, bool $isFacebook): string
    {
        return $isFacebook
            ? $agency.' post'
            : $agency.' official advisory';
    }

    private function withinDaysOfToday(CarbonImmutable $date, int $days): bool
    {
        return abs($date->diffInDays(CarbonImmutable::now('Asia/Manila')->startOfDay(), false)) <= $days;
    }

    private function forecastAreaMatches(string $forecastArea, string $location): bool
    {
        $haystack = Str::lower($forecastArea);
        if (Str::contains($haystack, ['mindanao', 'caraga'])) {
            return true;
        }

        return $this->locationMatches($forecastArea, $location);
    }

    private function locationMatches(string $sourceLocation, string $lguLocation): bool
    {
        $source = Str::lower($sourceLocation);
        $tokens = collect(preg_split('/[^a-z0-9]+/i', Str::lower($lguLocation)) ?: [])
            ->filter(fn (string $token): bool => strlen($token) >= 4)
            ->reject(fn (string $token): bool => in_array($token, ['city', 'municipality', 'province', 'agusan', 'surigao', 'norte', 'north', 'south', 'islands'], true))
            ->values();

        return $tokens->isEmpty() || $tokens->contains(fn (string $token): bool => Str::contains($source, $token));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            // PHIVOLCS displays timestamps as "27 July 2026 - 10:15 AM".
            // Normalize that visual separator before handing the value to Carbon.
            $normalized = preg_replace(
                '/\s+[-–—]\s+(?=\d{1,2}:\d{2}\s*(?:AM|PM)\b)/iu',
                ' ',
                trim((string) $value),
            );

            return CarbonImmutable::parse($normalized ?: (string) $value, 'Asia/Manila')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function cleanText(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
