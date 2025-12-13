<?php
// app/Services/Export/PdfExportStrategy.php

namespace App\Services\Export;

use Barryvdh\DomPDF\Facade\Pdf;

class PdfExportStrategy implements ExportStrategyInterface
{
    public function export(array $data, array $options = []): string
    {
        $filename = storage_path('app/exports/' . uniqid('export_') . '.pdf');

        // Ensure directory exists
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }

        $pdf = Pdf::loadView('exports.pdf-template', [
            'data' => $data,
            'title' => $options['title'] ?? 'Report',
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $pdf->save($filename);

        return $filename;
    }
}
