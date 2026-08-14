<?php

namespace App\Services;

use App\Models\OperationalLibraryValue;
use App\Support\InlinePdfFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class StfDocumentPdfService
{
    public function __construct(private readonly StfSheetSyncService $transactions) {}

    public function stream(string $reference, bool $inline = true): Response
    {
        $transaction = $this->transactions->transactionRows(500)
            ->firstWhere('stf_reference', $reference);
        abort_unless($transaction, 404, 'STF transaction is unavailable.');

        $signatories = OperationalLibraryValue::query()
            ->where('library_type', 'rros_stf_signatory')
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(function ($row): array {
                [$name, $designation] = array_pad(explode('|', (string) $row->value, 2), 2, '');

                return [$row->context => [
                    'name' => trim((string) (data_get($row->metadata, 'employee_name') ?: $name)),
                    'designation' => trim((string) (data_get($row->metadata, 'designation') ?: $designation)),
                ]];
            })->all();

        $document = Pdf::loadView('documents.stf', ['transaction' => $transaction, 'signatories' => $signatories])
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');
        $filename = InlinePdfFilename::fromCandidates($reference, 'STF-transaction');

        return $inline ? $document->stream($filename) : $document->download($filename);
    }
}
