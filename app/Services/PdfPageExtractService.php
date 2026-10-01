<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class PdfPageExtractService
{
    /**
     * Extract a single 1-based page into a new PDF file.
     * When the source has fewer pages than requested, the last page is used.
     */
    public function extractPage(string $sourcePdfPath, int $pageNumber = 2): string
    {
        if (! is_file($sourcePdfPath)) {
            throw new RuntimeException('The source PDF could not be found.');
        }

        $directory = storage_path('app/generated-documents');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the generated-document directory.');
        }

        $outputPath = $directory.'/'.uniqid('pdf-page-', true).'.pdf';
        $node = $this->nodeBinary();
        $script = base_path('scripts/extract-pdf-page.mjs');
        $process = new Process([
            $node,
            $script,
            realpath($sourcePdfPath) ?: $sourcePdfPath,
            $outputPath,
            (string) max(1, $pageNumber),
        ]);
        $process->setTimeout(45);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException(
                'Unable to extract the requested PDF page. '.trim($process->getErrorOutput().' '.$process->getOutput())
            );
        }

        return $outputPath;
    }

    private function nodeBinary(): string
    {
        $candidates = [
            getenv('NODE_BINARY') ?: null,
            'node',
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
        ];

        foreach (array_filter($candidates) as $candidate) {
            if ($candidate === 'node') {
                return $candidate;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }
}
