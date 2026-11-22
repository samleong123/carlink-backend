<?php
namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only fixed, public-safe reasons; never retain an SDK message, URL, key or file contents. */
class FileDependencyUnavailable extends HttpException
{
    private const MESSAGES=[
        'FILE_IMAGE_VALIDATOR_UNAVAILABLE'=>'Image validation is unavailable. An operator must check PHP image support.',
        'FILE_STORAGE_UNAVAILABLE'=>'Private file storage is unavailable. An operator must check S3 connectivity and permissions.',
        'FILE_LINK_SIGNING_UNAVAILABLE'=>'Private file links are unavailable. An operator must check S3 presigning credentials and endpoint configuration.',
    ];
    private array $diagnostics=[];

    public function __construct(public readonly string $reasonCode)
    {
        if (!isset(self::MESSAGES[$reasonCode])) throw new \InvalidArgumentException('Unsupported file failure reason.');
        parent::__construct(503,self::MESSAGES[$reasonCode]);
    }
    public static function storage(?\Throwable $cause=null): self
    {
        $error=new self('FILE_STORAGE_UNAVAILABLE');
        // Flysystem wraps AWS errors. Copy only a known symbolic reason, not the exception itself.
        for ($depth=0;$cause && $depth<8;$depth++,$cause=$cause->getPrevious()) {
            if (!$cause instanceof \Aws\Exception\AwsException) continue;
            $code=$cause->getAwsErrorCode();
            if (in_array($code,['AccessDenied','InvalidAccessKeyId','SignatureDoesNotMatch','ExpiredToken','InvalidToken','NoSuchBucket','NoSuchKey','PermanentRedirect','AuthorizationHeaderMalformed','AccessControlListNotSupported','KMS.AccessDeniedException','RequestTimeout','SlowDown','InternalError','ServiceUnavailable'],true)) $error->diagnostics['provider_code']=$code;
            $status=$cause->getStatusCode();
            if (is_int($status) && $status>=400 && $status<=599) $error->diagnostics['provider_status']=$status;
            break;
        }
        return $error;
    }
    public function diagnosticContext(): array { return ['reason_code'=>$this->reasonCode]+$this->diagnostics; }
}
