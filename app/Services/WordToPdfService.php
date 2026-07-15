<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class WordToPdfService
{
    public function convert(string $wordPath): string
    {
        if (! is_file($wordPath)) {
            throw new RuntimeException('The generated response letter could not be found.');
        }

        return Cache::lock('response-letter-word-conversion', 90)->block(65, fn (): string => $this->convertWithWord($wordPath));
    }

    private function convertWithWord(string $wordPath): string
    {
        $directory = storage_path('app/generated-documents');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the generated-document directory.');
        }

        $pdfPath = $directory.'/'.uniqid('response-', true).'.pdf';
        $process = new Process([
            'powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            base_path('scripts/ConvertWordToPdf.ps1'),
            '-InputPath',
            realpath($wordPath) ?: $wordPath,
            '-OutputPath',
            $pdfPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($pdfPath) || filesize($pdfPath) === 0) {
            @unlink($pdfPath);
            throw new RuntimeException('Microsoft Word could not convert the response letter to PDF. '.$process->getErrorOutput());
        }

        return $pdfPath;
    }
}
