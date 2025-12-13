<?php
// app/Services/Export/ExportStrategyInterface.php

namespace App\Services\Export;

interface ExportStrategyInterface
{
    public function export(array $data, array $options = []): string;
}
