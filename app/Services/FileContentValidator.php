<?php
namespace App\Services;

use App\Exceptions\FileDependencyUnavailable;

/** Format/size validation and image normalization only; no antivirus or malware-free guarantee. */
class FileContentValidator
{
    public function validate(string $encoded, bool $photo=false): array
    {
        $limit=(int)config($photo ? 'attachments.photo_max_bytes' : 'attachments.max_bytes');
        abort_unless(strlen($encoded)<=4*(int)ceil($limit/3) && preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D',$encoded),422,'Invalid file encoding or size.');
        $bytes=base64_decode($encoded,true);
        abort_unless($bytes !== false && strlen($bytes)>0 && strlen($bytes)<=$limit && base64_encode($bytes)===$encoded,422,'Invalid file encoding or size.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime,$photo ? ['image/png','image/jpeg'] : ['image/png','image/jpeg','application/pdf'],true),422,'Only JPEG, PNG and PDF contents are accepted.');
        if ($mime === 'application/pdf') {
            // Basic framing only: not a full PDF parser, encryption check or active-content scan.
            abort_unless(preg_match('/^%PDF-(?:1\.[0-7]|2\.0)(?:\r\n|\r|\n)/D',$bytes) && preg_match('/%%EOF\s*$/D',$bytes),422,'Invalid PDF header or ending.');
            // PDFs are signed with attachment disposition, never embedded by the application.
            return ['bytes'=>$bytes,'mime_type'=>$mime,'extension'=>'pdf'];
        }
        if (!function_exists('imagecreatefromstring')) throw new FileDependencyUnavailable('FILE_IMAGE_VALIDATOR_UNAVAILABLE');
        $info=@getimagesizefromstring($bytes);
        abort_unless($info && $info['mime']===$mime && $info[0]>0 && $info[1]>0 && $info[0]<=4096 && $info[1]<=4096 && $info[0]*$info[1]<=8388608,422,'Invalid image dimensions.');
        $image=@imagecreatefromstring($bytes); abort_unless($image,422,'Image contents could not be decoded.');
        try {
            ob_start();
            try { imagesavealpha($image,true); $ok=$mime==='image/png' ? imagepng($image,null,6) : imagejpeg($image,null,90); $clean=ob_get_contents(); }
            finally { ob_end_clean(); }
            abort_unless($ok && strlen($clean)<=$limit,422,'Normalized image exceeds size limit.');
        } finally { imagedestroy($image); }
        return ['bytes'=>$clean,'mime_type'=>$mime,'extension'=>$mime==='image/png' ? 'png' : 'jpg'];
    }
}
