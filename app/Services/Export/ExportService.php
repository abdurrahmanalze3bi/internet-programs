<?php
// app/Services/Export/ExportService.php

namespace App\Services\Export;

class ExportService
{
    private ExportStrategyInterface $strategy;

    public function setStrategy(ExportStrategyInterface $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function export(array $data, array $options = []): string
    {
        return $this->strategy->export($data, $options);
    }
}
