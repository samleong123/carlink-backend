<?php
namespace Tests\Feature;

use App\Services\FileContentValidator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FileContentValidatorTest extends TestCase
{
    private function image(string $mime='image/png',int $width=2,int $height=2): string
    {
        $image=imagecreatetruecolor($width,$height);
        try {
            ob_start();
            try { $mime==='image/png' ? imagepng($image) : imagejpeg($image); return ob_get_contents(); }
            finally { ob_end_clean(); }
        } finally { imagedestroy($image); }
    }
    private function assertInvalid(string $encoded,bool $photo=false): void
    {
        try { app(FileContentValidator::class)->validate($encoded,$photo); $this->fail('Invalid contents must not be accepted.'); }
        catch (HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
    }
    public function test_images_are_decoded_and_normalized_without_any_external_scanner(): void
    {
        // No network service or scanner binding is configured for this validation.
        foreach (['image/png'=>'png','image/jpeg'=>'jpg'] as $mime=>$extension) {
            $bytes=$this->image($mime).'<?php echo "hidden payload"; ?>';
            $file=app(FileContentValidator::class)->validate(base64_encode($bytes),true);
            $this->assertSame($mime,$file['mime_type']); $this->assertSame($extension,$file['extension']);
            $this->assertStringNotContainsString('hidden payload',$file['bytes']);
            $info=getimagesizefromstring($file['bytes']); $this->assertSame(2,$info[0]); $this->assertSame(2,$info[1]);
        }
    }
    public function test_canonical_base64_size_and_content_type_checks_remain_required(): void
    {
        foreach (['','not base64','YQ','YR==',base64_encode('<svg onload="alert(1)"></svg>'),base64_encode('<html>not an image</html>')] as $encoded) $this->assertInvalid($encoded);
        $this->assertInvalid(base64_encode(str_repeat('x',10485761)));
        $this->assertInvalid(base64_encode(str_repeat('x',2097153)),true);
        $this->assertInvalid(base64_encode("%PDF-1.4\n%%EOF\n"),true);
    }
    public function test_corrupt_images_and_oversize_dimensions_are_rejected(): void
    {
        $this->assertInvalid(base64_encode(substr($this->image(),0,33)));
        $this->assertInvalid(base64_encode($this->image('image/png',4097,1)));
        $this->assertInvalid(base64_encode($this->image('image/png',2900,2900)));
    }
    public function test_pdf_basic_framing_requires_no_binary_and_does_not_claim_full_validation(): void
    {
        $this->assertInvalid(base64_encode('%PDF-1.4 without an EOF marker'));
        $this->assertInvalid(base64_encode("%PDF-9.9\n%%EOF\n"));
        foreach (["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n", "%PDF-2.0\n/Encrypt 2 0 R\n%%EOF\n"] as $pdf) {
            $file=app(FileContentValidator::class)->validate(base64_encode($pdf));
            $this->assertSame(['bytes'=>$pdf,'mime_type'=>'application/pdf','extension'=>'pdf'],$file);
        }
    }
}
