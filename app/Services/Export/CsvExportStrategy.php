<?php
// app/Services/Export/CsvExportStrategy.php

namespace App\Services\Export;

class CsvExportStrategy implements ExportStrategyInterface
{
    public function export(array $data, array $options = []): string
    {
        $filename = storage_path('app/exports/' . uniqid('export_') . '.csv');

        // Ensure directory exists
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0755, true);
        }

        // Open file for writing
        $file = fopen($filename, 'w');

        if ($file === false) {
            throw new \RuntimeException("Failed to create CSV file: {$filename}");
        }

        // Add BOM for UTF-8 (helps Excel recognize UTF-8)
        fwrite($file, "\xEF\xBB\xBF");

        // Add headers
        if (!empty($data)) {
            $flattenedFirst = $this->flattenArray($data[0]);
            fputcsv($file, array_keys($flattenedFirst));

            // Add data rows
            foreach ($data as $row) {
                $flattenedRow = $this->flattenArray($row);
                fputcsv($file, $flattenedRow);
            }
        }

        fclose($file);

        return $filename;
    }

    /**
     * Flatten nested arrays into single level with dot notation
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '_' . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } elseif (is_object($value)) {
                $result[$newKey] = json_encode($value);
            } elseif (is_bool($value)) {
                $result[$newKey] = $value ? 'Yes' : 'No';
            } elseif (is_null($value)) {
                $result[$newKey] = '';
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}
