<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class FlushRequestLogSpool extends Command
{
    protected $signature = 'logs:flush-request-spool';
    protected $description = 'Replay encrypted request events into the logging database';

    public function handle(): int
    {
        $path = config('logging.request_spool_path');
        if (!is_file($path)) { return self::SUCCESS; }
        $file = @fopen($path, 'c+b');
        if (!$file || !flock($file, LOCK_EX)) { $this->error('Cannot lock request log spool.'); return self::FAILURE; }
        try {
            rewind($file);
            while (($line = fgets($file)) !== false) {
                $event = json_decode(Crypt::decryptString(trim($line)), true, flags: JSON_THROW_ON_ERROR);
                app(\App\Services\RequestLogWriter::class)->persist($event);
            }
            if (!feof($file)) { throw new \RuntimeException('Unable to read complete spool.'); }
            ftruncate($file, 0); fflush($file);
            if (function_exists('fsync')) { fsync($file); }
            $this->info('Request log spool replayed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request log replay failed. The spool was retained.');
            return self::FAILURE;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
