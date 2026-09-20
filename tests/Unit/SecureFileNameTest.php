<?php

namespace Tests\Unit;

use App\Services\Files\SecureFileService;
use PHPUnit\Framework\TestCase;

class SecureFileNameTest extends TestCase
{
    public function test_original_filename_is_reduced_to_a_safe_basename(): void
    {
        $this->assertSame('passwd', SecureFileService::safeOriginalName('../../etc/passwd'));
        $this->assertSame('proof.pdf', SecureFileService::safeOriginalName('C:\\Users\\x\\proof.pdf'));
        $this->assertSame('proof.pdf', SecureFileService::safeOriginalName("pro\x00of.pdf\r\n"));
        $this->assertSame('upload', SecureFileService::safeOriginalName('...'));
        $this->assertSame('upload', SecureFileService::safeOriginalName("\xff\xfe"));
        $this->assertSame(120, mb_strlen(SecureFileService::safeOriginalName(str_repeat('a', 500).'.pdf')));
    }
}
