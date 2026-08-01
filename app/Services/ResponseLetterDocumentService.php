<?php

namespace App\Services;

use App\Models\AssistanceRequest;
use App\Models\LguDirectoryEntry;
use App\Models\OperationalLibraryValue;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class ResponseLetterDocumentService
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function generate(AssistanceRequest $request): array
    {
        $template = $this->templatePath();
        $request->loadMissing(['requestParty.lguDirectoryEntry.officials', 'requestParty.lguDirectoryEntry.contacts', 'drmdAssignedUser', 'assessmentActor', 'incident']);

        $directory = storage_path('app/generated-documents');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the generated-document directory.');
        }

        $filename = 'Response-Letter-'.$request->reference_number.'.docx';
        $path = $directory.'/'.uniqid('response-', true).'.docx';
        $this->copyTemplate($template, $path);

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the response letter Word template.');
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if ($documentXml === false) {
            $zip->close();
            throw new RuntimeException('The Word template has no main document body.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->loadXML($documentXml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);

        $meta = $request->assessment_form_data ?? [];
        $directory = $request->requestParty?->lguDirectoryEntry
            ?: LguDirectoryEntry::query()
                ->with(['officials', 'contacts'])
                ->when(
                    filled($request->lgu_psgc_code),
                    fn ($query) => $query->where('psgc_code', $request->lgu_psgc_code),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->first();
        if (! $directory && filled($request->municipality)) {
            $sourceSheet = match (strtolower(trim((string) $request->province))) {
                'agusan del norte' => 'ADN',
                'agusan del sur' => 'ADS',
                'surigao del norte' => 'SDN',
                'surigao del sur' => 'SDS',
                'province of dinagat islands', 'dinagat islands' => 'PDI',
                default => null,
            };
            $directory = LguDirectoryEntry::query()
                ->with(['officials', 'contacts'])
                ->where(function ($query) use ($request): void {
                    $query->where('lgu_name', 'like', '%'.trim((string) $request->municipality).'%')
                        ->orWhere('override_lgu_name', 'like', '%'.trim((string) $request->municipality).'%');
                })
                ->when($sourceSheet, fn ($query) => $query->where('source_sheet', $sourceSheet))
                ->first();
        }
        $lce = $directory?->officials->firstWhere('role', 'lce');
        $lswd = $directory?->officials->firstWhere('role', 'lswd_officer');
        $recipient = $this->capitalizedName($lce?->override_name ?: $lce?->name ?: $request->requester ?: $request->requesting_agency);
        $recipientPosition = trim((string) ($lce?->override_position_designation ?: $lce?->position_designation ?: $request->requester_position ?: $request->office_agency_details));
        $provinceName = match (strtoupper((string) $directory?->source_sheet)) {
            'ADN' => 'Agusan del Norte',
            'ADS' => 'Agusan del Sur',
            'SDN' => 'Surigao del Norte',
            'SDS' => 'Surigao del Sur',
            'PDI' => 'Province of Dinagat Islands',
            default => $request->province,
        };
        $lguName = trim((string) ($directory?->override_lgu_name
            ?: $directory?->lgu_name
            ?: $request->municipality
            ?: $request->lgu
            ?: $request->requesting_agency));
        $recipientAddress = $this->formattedAddress(collect([
            $lguName,
            filled($provinceName) && ! str_contains(mb_strtolower($lguName), mb_strtolower((string) $provinceName))
                ? $provinceName
                : null,
        ])->filter()->implode(', '));
        $attentionName = $this->capitalizedName($lswd?->override_name ?: $lswd?->name ?: $request->requester);
        $attentionPosition = trim((string) ($lswd?->override_position_designation ?: $lswd?->position_designation ?: $request->office_agency_details));
        $salutation = $this->salutation($recipient, $recipientPosition);
        $responsePurpose = $meta['response_purpose'] ?? $request->purpose;
        $isReliefAugmentation = $responsePurpose === 'Relief Augmentation'
            || (blank($responsePurpose) && ($meta['provide_augmentation'] ?? false) === true);
        $incident = $isReliefAugmentation ? ($request->incident?->name ?: 'the reported incident') : null;
        $incidentDate = $request->incident?->incident_date?->format('F j, Y');
        $areas = collect($meta['affected_areas'] ?? [])->filter()->values();
        $areaSummary = match (true) {
            $areas->isEmpty() => '',
            $areas->count() <= 5 => ' and affected '.$areas->implode(', '),
            in_array(strtoupper((string) $request->lgu_level), ['CLGU', 'MLGU', 'MGLU'], true) => ' and affected '.$areas->count().' identified barangays within the locality',
            in_array(strtoupper((string) $request->lgu_level), ['PLGU', 'PGLU'], true) => ' and affected '.$areas->count().' identified cities and municipalities within the province',
            default => ' and affected '.$areas->count().' identified areas',
        };
        $provideAugmentation = ($meta['provide_augmentation'] ?? false) === true;
        $items = $request->items->filter(fn ($item) => (float) ($item->approved_quantity ?: $item->requested_quantity) > 0);
        $itemSummary = $items->map(function ($item): string {
            $quantity = $item->approved_quantity ?: $item->requested_quantity;
            $unit = strtolower((string) $item->unit);
            if ((float) $quantity !== 1.0) $unit = match ($unit) { 'box' => 'boxes', 'kit' => 'kits', 'set' => 'sets', 'pack' => 'packs', default => $unit };
            return number_format((float) $quantity).' '.$unit.' of '.$item->item_name;
        })->implode(', ');
        // Opening paragraph: name the requested items only — never the LGU-requested quantities.
        $requestedItemNames = $items->map(fn ($item): string => trim((string) $item->item_name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->implode(', ');
        $locality = $this->localityReference($request, $lguName, $provinceName, $directory?->lgu_level);
        $socialWorker = $this->responseSocialWorker($request, $meta);
        $workerReference = filled($socialWorker['full_name'])
            ? 'our Social Worker '.$socialWorker['full_name']
            : 'our assigned social worker';
        $workerContactSentence = filled($socialWorker['short_name']) && filled($socialWorker['contact'])
            ? sprintf(
                ' For further queries, %s will be coordinating with you through this mobile number %s.',
                $socialWorker['short_name'],
                $socialWorker['contact']
            )
            : (filled($socialWorker['short_name'])
                ? sprintf(' For further queries, %s will be coordinating with you.', $socialWorker['short_name'])
                : '');
        $occurrenceClause = $incidentDate
            ? ' which occurred in Caraga Region on '.$incidentDate
            : ' which occurred in Caraga Region';
        $responseLetterInitials = OperationalLibraryValue::query()
            ->where('library_type', 'response_letter_initials')
            ->where('context', 'response_letter')
            ->where('is_active', true)
            ->orderBy('id')
            ->value('value') ?: 'JSP/AAA/JLM/1628';
        $responseLetterInitials = preg_replace('/\s*\/\s*/', ' / ', trim((string) $responseLetterInitials));

        $dateOccurrence = 0;
        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            if (! $paragraph instanceof DOMElement) continue;
            $text = $this->paragraphText($xpath, $paragraph);

            if (str_starts_with(trim($text), 'HON. PABLO YVES')) {
                $this->replaceParagraphSegments($document, $xpath, $paragraph, [
                    ['text' => $recipient, 'breaks_before' => 2, 'bold' => true, 'italic' => false],
                    ['text' => $recipientPosition, 'breaks_before' => 1, 'bold' => false, 'italic' => false],
                    ['text' => $recipientAddress, 'breaks_before' => 1, 'bold' => false, 'italic' => false],
                ]);
                continue;
            }
            if (str_starts_with(trim($text), 'ATTENTION:')) {
                $this->replaceParagraphSegments($document, $xpath, $paragraph, [
                    ['text' => 'ATTENTION:', 'breaks_before' => 2, 'bold' => false, 'italic' => false],
                    ['text' => $attentionName ?: $request->office_agency_details, 'tab_before' => true, 'bold' => true, 'italic' => false],
                ]);
                $this->formatAttentionTabParagraph($document, $xpath, $paragraph);
                continue;
            }
            if (trim($text) === 'CSWDO') {
                $this->replaceParagraphSegments($document, $xpath, $paragraph, [[
                    'text' => $attentionPosition ?: 'LSWDO',
                ]]);
                $this->formatAttentionDesignationParagraph($document, $xpath, $paragraph);
                continue;
            }
            if (str_starts_with(trim($text), 'Dear Mayor Dumlao:')) {
                $segments = [['text' => 'Dear Sir/Madam:', 'breaks_before' => 2, 'bold' => false, 'italic' => false]];
                if (preg_match('/^Dear (Mayor|Governor) (.+):$/', $salutation, $matches)) {
                    $segments = [
                        ['text' => 'Dear ', 'breaks_before' => 2, 'bold' => false, 'italic' => false],
                        ['text' => $matches[1].' '.$matches[2], 'bold' => true, 'italic' => true],
                        ['text' => ':', 'bold' => false, 'italic' => true],
                    ];
                }
                $segments[] = ['text' => 'Greetings of service excellence and resilience!', 'breaks_before' => 2, 'bold' => false, 'italic' => true];
                $this->replaceParagraphSegments($document, $xpath, $paragraph, $segments);
                continue;
            }
            if (trim($text) === 'Respectfully yours,') {
                $this->prependLineBreaks($document, $paragraph, 1);
                continue;
            }
            if (str_starts_with(trim($text), 'MARI- FLOR A. DOLLAGA- LIBANG')) {
                $this->prependLineBreaks($document, $paragraph, 3);
                continue;
            }
            if (preg_match('/^[A-Z]{2,5}\/[A-Z]{2,5}\/[A-Z]{2,5}\/[0-9]+$/', trim($text)) === 1) {
                $this->replaceParagraphSegments($document, $xpath, $paragraph, [[
                    'text' => $responseLetterInitials,
                    'breaks_before' => 3,
                    'font' => 'Arial',
                    'font_size' => 8,
                    'italic' => true,
                ]]);
                continue;
            }
            if (preg_match('/^[A-Z]+\s+\d{1,2},\s+\d{4}$/', trim($text)) === 1) {
                $dateOccurrence++;
                if ($dateOccurrence === 2) $this->ensurePageBreakBefore($document, $xpath, $paragraph);
                $this->replaceParagraphSegments($document, $xpath, $paragraph, [[
                    'text' => strtoupper(now()->format('F j, Y')),
                    'breaks_before' => 1,
                ]]);
                continue;
            }
            if (trim($text) === 'DRN:') {
                $this->replaceParagraph($document, $xpath, $paragraph, 'DRN: '.($request->response_drn ?: ''));
                $this->formatDrnParagraph($document, $xpath, $paragraph);
                continue;
            }

            $replacement = match (true) {
                str_starts_with(trim($text), 'This is in reference to your letter requesting') => $isReliefAugmentation
                    ? sprintf(
                        'This is in reference to your letter requesting %s intended for the %s disaster-affected %s in %s due to %s%s%s.',
                        $requestedItemNames ?: 'Food and Non-Food Items',
                        number_format((int) ($request->affected_families ?? 0)),
                        (int) ($request->affected_families ?? 0) === 1 ? 'family' : 'families',
                        $locality,
                        $incident,
                        $occurrenceClause,
                        $areaSummary
                    )
                    : 'This is in reference to your letter requesting Food and Non-Food Items for preparedness and response readiness.',
                str_starts_with(trim($text), 'After a thorough assessment') => $provideAugmentation
                    ? sprintf(
                        'After a thorough assessment conducted by %s, %s is eligible to be provided with the requested goods as augmentation assistance from our office. Hence, we will extend %s to the above-mentioned number of affected families.',
                        $workerReference,
                        $locality,
                        $itemSummary ?: 'the approved Food and Non-Food Items'
                    )
                    : sprintf(
                        'After a thorough assessment conducted by %s, the requested augmentation is not recommended at this time. The requesting party will be advised of any additional documentation or coordination required.',
                        $workerReference
                    ),
                str_starts_with(trim($text), 'With this, the Regional Resource Operations Section') => $provideAugmentation
                    ? 'With this, the Regional Resource Operations Section (RROS) personnel will prepare the Requisition and Issuance Slip (RIS) of the said items. The assigned social worker will immediately coordinate with the Focal Person once the documents are prepared and the goods are ready for delivery and/or pick-up from your Local Government Unit Warehouse.'.$workerContactSentence
                    : 'The Disaster Response Management Division will coordinate with the requesting party regarding the assessment result and any succeeding action required.',
                default => null,
            };

            if ($replacement !== null) $this->replaceParagraph($document, $xpath, $paragraph, $replacement);
        }

        $this->compactLetterPages($document, $xpath);
        $zip->addFromString('word/document.xml', $document->saveXML());
        $this->normalizeHeadersAndFooters($zip);
        $zip->addFromString('word/media/image2.png', file_get_contents(public_path('images/dswd_logo_3.png')));
        $zip->addFromString('word/media/image3.png', file_get_contents(public_path('images/Bagong_PilipinasTransparent.png')));
        $zip->close();

        return ['path' => $path, 'filename' => $filename];
    }

    private function templatePath(): string
    {
        $profile = (string) ($_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: '');
        $configured = (string) config('services.response_documents.template');
        $candidates = array_filter([
            $configured,
            $profile !== '' ? $profile.DIRECTORY_SEPARATOR.'Downloads'.DIRECTORY_SEPARATOR.'Response Letter.docx' : null,
            storage_path('app/templates/Response Letter.docx'),
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return $candidate;
        }

        throw new RuntimeException('The official Response Letter.docx template could not be found.');
    }

    private function copyTemplate(string $template, string $destination): void
    {
        if (@copy($template, $destination)) return;

        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process([
                'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', base_path('scripts/CopySharedFile.ps1'),
                '-InputPath', $template, '-OutputPath', $destination,
            ]);
            $process->setTimeout(30);
            $process->run();
            if ($process->isSuccessful() && is_file($destination)) return;
        }

        throw new RuntimeException('Unable to prepare the official response letter template. Close the template in Word and try again.');
    }

    private function salutation(?string $recipient, ?string $position): string
    {
        $title = str_contains(strtolower((string) $position), 'governor') ? 'Governor' : (str_contains(strtolower((string) $position), 'mayor') ? 'Mayor' : null);
        if (! $title || blank($recipient)) return 'Dear Sir/Madam:';

        $clean = preg_replace('/^(HON\.?|ATTY\.?|DR\.?)\s+/i', '', trim($recipient));
        $clean = preg_replace('/,.*$/', '', (string) $clean);
        $parts = preg_split('/\s+/', trim((string) $clean));
        while ($parts && preg_match('/^(JR\.?|SR\.?|II|III|IV)$/i', end($parts))) array_pop($parts);
        $surname = $parts ? end($parts) : null;

        return $surname ? "Dear {$title} ".str($surname)->lower()->title().':' : "Dear {$title}:";
    }

    private function capitalizedName(?string $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : mb_strtoupper($value, 'UTF-8');
    }

    private function formattedAddress(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        $value = str($value)->lower()->title()->toString();
        $value = preg_replace_callback('/\b(Of|The|And|Del|De|La)\b/u', fn ($match) => strtolower($match[0]), $value);

        return strtr($value, [
            'Dswd' => 'DSWD', 'Lgu' => 'LGU', 'Plgu' => 'PLGU', 'Clgu' => 'CLGU', 'Mlgu' => 'MLGU',
            'Adn' => 'ADN', 'Ads' => 'ADS', 'Sdn' => 'SDN', 'Sds' => 'SDS', 'Pdi' => 'PDI', 'Ncr' => 'NCR',
            'Brgy.' => 'Brgy.',
        ]);
    }

    private function localityReference(AssistanceRequest $request, ?string $lguName, ?string $province, ?string $directoryLevel = null): string
    {
        $name = trim((string) ($request->municipality ?: $lguName ?: $request->requesting_agency));
        $name = preg_replace('/^(Municipality|City|Province)\s+of\s+/i', '', $name);
        $name = $this->formattedAddress($name);
        $level = strtoupper(trim((string) ($request->lgu_level ?: $directoryLevel)));

        return match (true) {
            in_array($level, ['CLGU'], true) => 'the city of '.($name ?: 'the requesting LGU'),
            in_array($level, ['PLGU', 'PGLU'], true) => 'the province of '.($this->formattedAddress($province ?: $name) ?: 'the requesting LGU'),
            default => 'the municipality of '.($name ?: 'the requesting LGU'),
        };
    }

    /** @return array{full_name: string, short_name: string, contact: string} */
    private function responseSocialWorker(AssistanceRequest $request, array $meta): array
    {
        $authenticated = auth()->user();
        $actor = $request->assessmentActor;
        $assigned = $request->drmdAssignedUser;
        $recordedName = trim((string) (
            $actor?->name
            ?? $meta['prepared_by']
            ?? $request->assigned_social_worker
            ?? $assigned?->name
            ?? $authenticated?->name
            ?? ''
        ));

        $resolved = $actor
            ?: (filled($assigned?->name) && mb_strtolower(trim($assigned->name)) === mb_strtolower($recordedName) ? $assigned : null)
            ?: (filled($authenticated?->name) && mb_strtolower(trim((string) $authenticated->name)) === mb_strtolower($recordedName) ? $authenticated : null)
            ?: (filled($recordedName)
                ? User::query()->whereRaw('lower(name) = ?', [mb_strtolower($recordedName)])->first()
                : null);

        $displayName = $this->personName($resolved?->name ?: $recordedName);
        $honorific = $this->personHonorific($displayName);
        $surname = $this->surname($displayName);

        return [
            'full_name' => filled($displayName)
                ? trim($honorific.' '.$this->stripLeadingHonorific($displayName))
                : '',
            'short_name' => filled($surname)
                ? trim($honorific.' '.$surname)
                : (filled($displayName) ? trim($honorific.' '.$this->stripLeadingHonorific($displayName)) : ''),
            'contact' => trim((string) ($resolved?->mobile_no ?: $resolved?->contact_number ?: '')),
        ];
    }

    private function personHonorific(?string $name): string
    {
        if (preg_match('/^(Mrs|Ms|Mr|Dr|Atty)\.?[\s,]/i', trim((string) $name), $matches) === 1) {
            $token = strtolower(rtrim($matches[1], '.'));

            return match ($token) {
                'mrs' => 'Mrs.',
                'ms' => 'Ms.',
                'dr' => 'Dr.',
                'atty' => 'Atty.',
                default => 'Mr.',
            };
        }

        return 'Mr.';
    }

    private function stripLeadingHonorific(?string $name): string
    {
        return trim((string) preg_replace('/^(MR\.?|MS\.?|MRS\.?|DR\.?|ATTY\.?)\s+/i', '', trim((string) $name)));
    }

    private function personName(?string $value): string
    {
        $value = $this->stripLeadingHonorific($value);
        if ($value === '') {
            return '';
        }

        // Keep ALL-CAPS source names readable in letter body (Roger L. Ongue).
        if ($value === mb_strtoupper($value, 'UTF-8')) {
            return $this->formattedAddress($value);
        }

        return $value;
    }

    private function surname(?string $name): string
    {
        $clean = $this->stripLeadingHonorific($name);
        $parts = preg_split('/\s+/', trim((string) $clean)) ?: [];
        while ($parts && preg_match('/^(JR\.?|SR\.?|II|III|IV)$/i', (string) end($parts))) {
            array_pop($parts);
        }

        $surname = $parts ? (string) end($parts) : '';
        if ($surname === '') {
            return '';
        }

        return $surname === mb_strtoupper($surname, 'UTF-8')
            ? $this->formattedAddress($surname)
            : $surname;
    }

    private function formatDrnParagraph(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph): void
    {
        $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
        if (! $properties instanceof DOMElement) {
            $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
            $paragraph->insertBefore($properties, $paragraph->firstChild);
        }
        foreach (['tabs', 'ind', 'jc', 'keepLines'] as $element) {
            foreach (iterator_to_array($xpath->query('./w:'.$element, $properties)) as $node) $properties->removeChild($node);
        }
        $justification = $document->createElementNS(self::WORD_NS, 'w:jc');
        $justification->setAttributeNS(self::WORD_NS, 'w:val', 'right');
        $properties->appendChild($justification);
        $properties->appendChild($document->createElementNS(self::WORD_NS, 'w:keepLines'));
    }

    private function formatAttentionTabParagraph(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph): void
    {
        $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
        if (! $properties instanceof DOMElement) {
            $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
            $paragraph->insertBefore($properties, $paragraph->firstChild);
        }
        foreach (['ind', 'tabs'] as $element) {
            foreach (iterator_to_array($xpath->query('./w:'.$element, $properties)) as $node) $properties->removeChild($node);
        }
        $tabs = $document->createElementNS(self::WORD_NS, 'w:tabs');
        $tab = $document->createElementNS(self::WORD_NS, 'w:tab');
        $tab->setAttributeNS(self::WORD_NS, 'w:val', 'left');
        $tab->setAttributeNS(self::WORD_NS, 'w:pos', '1380');
        $tabs->appendChild($tab);
        $properties->appendChild($tabs);
        if (! $xpath->query('./w:keepLines', $properties)->item(0)) {
            $properties->appendChild($document->createElementNS(self::WORD_NS, 'w:keepLines'));
        }
    }

    private function formatAttentionDesignationParagraph(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph): void
    {
        $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
        if (! $properties instanceof DOMElement) {
            $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
            $paragraph->insertBefore($properties, $paragraph->firstChild);
        }
        foreach (['ind', 'tabs'] as $element) {
            foreach (iterator_to_array($xpath->query('./w:'.$element, $properties)) as $node) $properties->removeChild($node);
        }
        $indent = $document->createElementNS(self::WORD_NS, 'w:ind');
        $indent->setAttributeNS(self::WORD_NS, 'w:left', '1380');
        $properties->appendChild($indent);
        if (! $xpath->query('./w:keepLines', $properties)->item(0)) {
            $properties->appendChild($document->createElementNS(self::WORD_NS, 'w:keepLines'));
        }
    }

    private function prependLineBreaks(DOMDocument $document, DOMElement $paragraph, int $count): void
    {
        $run = $document->createElementNS(self::WORD_NS, 'w:r');
        for ($index = 0; $index < $count; $index++) $run->appendChild($document->createElementNS(self::WORD_NS, 'w:br'));
        $firstContent = null;
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::WORD_NS && $child->localName === 'pPr') continue;
            $firstContent = $child;
            break;
        }
        $paragraph->insertBefore($run, $firstContent);
    }

    private function compactLetterPages(DOMDocument $document, DOMXPath $xpath): void
    {
        foreach (iterator_to_array($xpath->query('//w:body/w:p')) as $paragraph) {
            if (! $paragraph instanceof DOMElement || trim($this->paragraphText($xpath, $paragraph)) !== '') continue;
            if ($xpath->query('.//w:drawing|.//w:pict|.//w:br[@w:type="page"]|./w:pPr/w:sectPr', $paragraph)->length > 0) continue;
            $paragraph->parentNode?->removeChild($paragraph);
        }

        foreach ($xpath->query('//w:body//w:p') as $paragraph) {
            if (! $paragraph instanceof DOMElement) continue;
            $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
            if (! $properties instanceof DOMElement) {
                $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
                $paragraph->insertBefore($properties, $paragraph->firstChild);
            }
            $spacing = $xpath->query('./w:spacing', $properties)->item(0);
            if (! $spacing instanceof DOMElement) {
                $spacing = $document->createElementNS(self::WORD_NS, 'w:spacing');
                $properties->appendChild($spacing);
            }
            $spacing->setAttributeNS(self::WORD_NS, 'w:line', '276');
            $spacing->setAttributeNS(self::WORD_NS, 'w:lineRule', 'auto');
        }

        foreach ($xpath->query('//w:sectPr/w:pgMar') as $margins) {
            if (! $margins instanceof DOMElement) continue;
            // The repeated logo header ends at about 0.8 in. Reserve 0.9 in
            // before body content so page-one DRN and page-two date cannot
            // occupy the logo row.
            $margins->setAttributeNS(self::WORD_NS, 'w:top', '1296');
            $margins->setAttributeNS(self::WORD_NS, 'w:bottom', '720');
            $margins->setAttributeNS(self::WORD_NS, 'w:left', '1080');
            $margins->setAttributeNS(self::WORD_NS, 'w:right', '1080');
        }
    }

    private function normalizeHeadersAndFooters(ZipArchive $zip): void
    {
        // The template uses header3/footer3 for page one and header2/footer2 for
        // following pages. Clone page one's visual header into the default part,
        // while assigning fresh drawing IDs (Word rejects duplicate drawing IDs
        // across header parts as a corrupted package).
        $header = $zip->getFromName('word/header3.xml');
        if ($header !== false) {
            $headerDocument = new DOMDocument('1.0', 'UTF-8');
            $headerDocument->preserveWhiteSpace = true;
            $headerDocument->loadXML($header);
            $headerXPath = new DOMXPath($headerDocument);
            $headerXPath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $headerXPath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $headerXPath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
            $this->rebuildHeaderLogoLayer($headerDocument, $headerXPath);
            // Match the assessment form: 92 pt DSWD logo, 6 pt gap, and
            // 30 pt Bagong Pilipinas logo, aligned to the 0.75 in body margin.
            $this->normalizeHeaderLogo($headerDocument, $headerXPath, 'rId1', 685800, 274320, 1168400, 459385);
            $this->normalizeHeaderLogo($headerDocument, $headerXPath, 'rId2', 1930400, 313000, 381000, 381000);

            $normalizedPageOneHeader = $headerDocument->saveXML();
            $zip->addFromString('word/header3.xml', $normalizedPageOneHeader);

            $followingPageHeaderDocument = new DOMDocument('1.0', 'UTF-8');
            $followingPageHeaderDocument->preserveWhiteSpace = true;
            $followingPageHeaderDocument->loadXML($normalizedPageOneHeader);
            $followingPageHeaderXPath = new DOMXPath($followingPageHeaderDocument);
            $drawingId = 50000;
            foreach ($followingPageHeaderXPath->query('//*[local-name()="docPr" or local-name()="cNvPr"]') as $drawing) {
                if ($drawing instanceof DOMElement) $drawing->setAttribute('id', (string) ++$drawingId);
            }
            $zip->addFromString('word/header2.xml', $followingPageHeaderDocument->saveXML());
        }

        $footer = $zip->getFromName('word/footer3.xml');
        if ($footer === false) return;
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->loadXML($footer);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NS);
        foreach ($xpath->query('//w:p') as $paragraph) {
            if ($paragraph instanceof DOMElement && str_starts_with(trim($this->paragraphText($xpath, $paragraph)), 'PAGE')) {
                $this->replaceParagraph($document, $xpath, $paragraph, 'PAGE 1of 1');
                break;
            }
        }
        $normalizedFooter = $document->saveXML();
        $zip->addFromString('word/footer2.xml', $normalizedFooter);
        $zip->addFromString('word/footer3.xml', $normalizedFooter);
    }

    private function rebuildHeaderLogoLayer(DOMDocument $document, DOMXPath $xpath): void
    {
        $header = $document->documentElement;
        if (! $header instanceof DOMElement) return;

        $logoRuns = [];
        foreach (['rId1', 'rId2'] as $relationshipId) {
            $blip = $xpath->query('//a:blip[@r:embed="'.$relationshipId.'"]')->item(0);
            $run = $blip instanceof DOMElement
                ? $xpath->query('ancestor::*[local-name()="r"][1]', $blip)->item(0)
                : null;
            if ($run instanceof DOMElement) $logoRuns[] = $run->cloneNode(true);
        }
        if (count($logoRuns) !== 2) return;

        foreach (iterator_to_array($header->childNodes) as $child) $header->removeChild($child);

        $paragraph = $document->createElementNS(self::WORD_NS, 'w:p');
        $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
        $spacing = $document->createElementNS(self::WORD_NS, 'w:spacing');
        $spacing->setAttributeNS(self::WORD_NS, 'w:after', '0');
        $properties->appendChild($spacing);
        $paragraph->appendChild($properties);
        foreach ($logoRuns as $run) $paragraph->appendChild($run);
        $header->appendChild($paragraph);
    }

    private function normalizeHeaderLogo(
        DOMDocument $document,
        DOMXPath $xpath,
        string $relationshipId,
        int $x,
        int $y,
        int $width,
        int $height,
    ): void {
        $blip = $xpath->query('//a:blip[@r:embed="'.$relationshipId.'"]')->item(0);
        if (! $blip instanceof DOMElement) return;

        $anchor = $xpath->query('ancestor::wp:anchor[1]', $blip)->item(0);
        if (! $anchor instanceof DOMElement) return;

        $positionH = $xpath->query('./wp:positionH', $anchor)->item(0);
        $positionV = $xpath->query('./wp:positionV', $anchor)->item(0);
        $extent = $xpath->query('./wp:extent', $anchor)->item(0);
        if (! $positionH instanceof DOMElement || ! $positionV instanceof DOMElement || ! $extent instanceof DOMElement) return;

        $anchor->setAttribute('layoutInCell', '0');
        $anchor->setAttribute('allowOverlap', '0');
        $positionH->setAttribute('relativeFrom', 'page');
        $positionV->setAttribute('relativeFrom', 'page');
        $this->replacePositionOffset($document, $positionH, $x);
        $this->replacePositionOffset($document, $positionV, $y);
        $extent->setAttribute('cx', (string) $width);
        $extent->setAttribute('cy', (string) $height);

        foreach (iterator_to_array($xpath->query('ancestor::*[local-name()="pic"][1]//a:srcRect', $blip)) as $crop) {
            foreach (['l', 't', 'r', 'b'] as $attribute) $crop->removeAttribute($attribute);
        }
        foreach ($xpath->query('ancestor::*[local-name()="pic"][1]//a:xfrm/a:ext', $blip) as $shapeExtent) {
            if (! $shapeExtent instanceof DOMElement) continue;
            $shapeExtent->setAttribute('cx', (string) $width);
            $shapeExtent->setAttribute('cy', (string) $height);
        }
    }

    private function replacePositionOffset(DOMDocument $document, DOMElement $position, int $offset): void
    {
        foreach (iterator_to_array($position->childNodes) as $child) $position->removeChild($child);
        $node = $document->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:posOffset');
        $node->appendChild($document->createTextNode((string) $offset));
        $position->appendChild($node);
    }

    private function ensurePageBreakBefore(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph): void
    {
        $properties = $xpath->query('./w:pPr', $paragraph)->item(0);
        if (! $properties instanceof DOMElement) {
            $properties = $document->createElementNS(self::WORD_NS, 'w:pPr');
            $paragraph->insertBefore($properties, $paragraph->firstChild);
        }
        if (! $xpath->query('./w:pageBreakBefore', $properties)->item(0)) {
            $properties->appendChild($document->createElementNS(self::WORD_NS, 'w:pageBreakBefore'));
        }
    }

    private function paragraphText(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $parts = [];
        foreach ($xpath->query('.//w:t', $paragraph) as $textNode) $parts[] = $textNode->textContent;
        return implode('', $parts);
    }

    private function replaceParagraph(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph, string $value): void
    {
        $this->replaceParagraphSegments($document, $xpath, $paragraph, [['text' => $value]]);
    }

    private function replaceParagraphSegments(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph, array $segments): void
    {
        $runProperties = $xpath->query('.//w:r[.//w:t[string-length(normalize-space(.)) > 0]][1]/w:rPr', $paragraph)->item(0)?->cloneNode(true);
        foreach (iterator_to_array($paragraph->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::WORD_NS && $child->localName === 'pPr') continue;
            $paragraph->removeChild($child);
        }

        foreach ($segments as $segment) {
            $run = $document->createElementNS(self::WORD_NS, 'w:r');
            $properties = $runProperties ? $runProperties->cloneNode(true) : $document->createElementNS(self::WORD_NS, 'w:rPr');
            foreach (['bold' => 'b', 'italic' => 'i'] as $key => $element) {
                if (! array_key_exists($key, $segment)) continue;
                foreach (iterator_to_array($xpath->query('./w:'.$element, $properties)) as $node) $properties->removeChild($node);
                $toggle = $document->createElementNS(self::WORD_NS, 'w:'.$element);
                if ($segment[$key] !== true) $toggle->setAttributeNS(self::WORD_NS, 'w:val', '0');
                $properties->appendChild($toggle);
            }
            if (isset($segment['font'])) {
                foreach (iterator_to_array($xpath->query('./w:rFonts', $properties)) as $node) $properties->removeChild($node);
                $fonts = $document->createElementNS(self::WORD_NS, 'w:rFonts');
                foreach (['ascii', 'eastAsia', 'hAnsi', 'cs'] as $attribute) $fonts->setAttributeNS(self::WORD_NS, 'w:'.$attribute, (string) $segment['font']);
                $properties->appendChild($fonts);
            }
            if (isset($segment['font_size'])) {
                foreach (['sz', 'szCs'] as $element) {
                    foreach (iterator_to_array($xpath->query('./w:'.$element, $properties)) as $node) $properties->removeChild($node);
                    $size = $document->createElementNS(self::WORD_NS, 'w:'.$element);
                    $size->setAttributeNS(self::WORD_NS, 'w:val', (string) ((float) $segment['font_size'] * 2));
                    $properties->appendChild($size);
                }
            }
            $run->appendChild($properties);
            for ($break = 0; $break < ($segment['breaks_before'] ?? 0); $break++) $run->appendChild($document->createElementNS(self::WORD_NS, 'w:br'));
            if ($segment['tab_before'] ?? false) $run->appendChild($document->createElementNS(self::WORD_NS, 'w:tab'));
            foreach (preg_split('/\R/', (string) ($segment['text'] ?? '')) as $index => $line) {
                if ($index > 0) $run->appendChild($document->createElementNS(self::WORD_NS, 'w:br'));
                $text = $document->createElementNS(self::WORD_NS, 'w:t');
                $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $text->appendChild($document->createTextNode($line));
                $run->appendChild($text);
            }
            $paragraph->appendChild($run);
        }
    }
}
