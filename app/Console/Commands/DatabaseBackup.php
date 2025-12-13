<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:database';
    protected $description = 'Backup MySQL database with timestamp and log status';

    public function handle()
    {
        $date = Carbon::now()->format('Y-m-d_H-i-s');
        $filename = "backup_{$date}.sql";
        $path = storage_path("app/backups/{$filename}");

        $db = config('database.connections.mysql');

        $command = sprintf(
            'mysqldump -u%s -p%s -h%s %s > %s',
            $db['username'],
            $db['password'],
            $db['host'],
            $db['database'],
            $path
        );

        $result = null;
        $output = null;
        exec($command, $output, $result);

        if ($result === 0) {
            DB::table('backup_logs')->insert([
                'filename' => $filename,
                'status' => 'success',
                'created_at' => now()
            ]);

            $this->info('Backup created: ' . $filename);
        } else {
            DB::table('backup_logs')->insert([
                'filename' => $filename,
                'status' => 'failed',
                'created_at' => now()
            ]);

            $this->error('Backup failed.');
        }
    }
}
