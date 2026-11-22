<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckFiles extends Command
{
    protected $signature='files:check';
    protected $description='Secret-safe file validation and storage prerequisites; no S3 request or upload';
    public function handle(): int
    {
        $checks=[
            'private S3 disk'=>config('attachments.disk')==='s3' && config('filesystems.disks.s3.driver')==='s3',
            'S3 SDK and adapter installed'=>class_exists(\Aws\S3\S3Client::class) && class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class),
            'S3 bucket configured'=>is_string(config('filesystems.disks.s3.bucket')) && config('filesystems.disks.s3.bucket')!=='',
            'S3 region configured'=>is_string(config('filesystems.disks.s3.region')) && config('filesystems.disks.s3.region')!=='',
            'content MIME detection'=>extension_loaded('fileinfo'),
            'JPEG and PNG validation'=>extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagepng'),
        ];
        $key=config('filesystems.disks.s3.key'); $secret=config('filesystems.disks.s3.secret');
        $checks['AWS credential pair consistent']=(bool)$key===(bool)$secret; // Both absent can use the SDK credential chain.
        $endpoint=config('filesystems.disks.s3.endpoint');
        $url=is_string($endpoint) && $endpoint!=='' ? parse_url($endpoint) : null;
        $checks['custom S3 endpoint HTTPS or AWS default']=!$endpoint || ($url && ($url['scheme'] ?? '')==='https' && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']));
        foreach ($checks as $label=>$ok) $this->line(($ok ? 'PASS ' : 'FAIL ').$label);
        $this->warn('Prerequisites only: no S3 request, upload or presigning performed. AWS credentials/IAM, bucket policy, TLS and real file acceptance remain unverified. PDFs receive basic header/ending checks only; uploads are not antivirus-scanned.');
        return in_array(false,$checks,true) ? self::FAILURE : self::SUCCESS;
    }
}
