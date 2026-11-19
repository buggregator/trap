<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket\Tls;

use Buggregator\Trap\Server\Socket\Tls\SelfSignedCertificate;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(SelfSignedCertificate::class)]
final class SelfSignedCertificateTest
{
    /** @var non-empty-string */
    private string $directory;

    #[BeforeTest]
    public function createDirectory(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('The certificate needs ext-openssl.');
        $this->directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-tls-' . \bin2hex(\random_bytes(4));
    }

    #[AfterTest]
    public function removeDirectory(): void
    {
        foreach (\glob($this->directory . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            \unlink($file);
        }
        \is_dir($this->directory) and \rmdir($this->directory);
    }

    #[Test]
    public function generatesCertificateForLocalhost(): void
    {
        $path = SelfSignedCertificate::ensure($this->directory);

        $pem = (string) \file_get_contents($path);
        $info = \openssl_x509_parse($pem);
        Assert::true(\is_array($info));
        Assert::same($info['subject']['CN'] ?? null, 'localhost');
        Assert::same($info['extensions']['subjectAltName'] ?? null, 'DNS:localhost, IP Address:127.0.0.1, IP Address:0:0:0:0:0:0:0:1');
        Assert::true(\openssl_x509_check_private_key($pem, $pem));
        Assert::same(\glob($this->directory . \DIRECTORY_SEPARATOR . '*'), [$path]);
    }

    #[Test]
    public function reusesValidCertificate(): void
    {
        $path = SelfSignedCertificate::ensure($this->directory);
        $pem = \file_get_contents($path);

        Assert::same(SelfSignedCertificate::ensure($this->directory), $path);
        Assert::same(\file_get_contents($path), $pem);
    }

    #[Test]
    public function replacesBrokenCertificate(): void
    {
        \mkdir($this->directory);
        $path = $this->directory . \DIRECTORY_SEPARATOR . 'localhost.pem';
        \file_put_contents($path, 'broken');

        Assert::same(SelfSignedCertificate::ensure($this->directory), $path);
        Assert::true(\openssl_x509_parse((string) \file_get_contents($path)) !== false);
    }

    #[Test]
    public function refusesSymbolicLinkInPlaceOfDirectory(): void
    {
        SelfSignedCertificate::userId() === null and throw new SkipTest('Files have no owners to compare here.');
        $target = $this->directory . '-target';
        \mkdir($target, 0700);
        \symlink($target, $this->directory);

        try {
            Expect::exception(\RuntimeException::class);
            SelfSignedCertificate::ensure($this->directory);
        } finally {
            \unlink($this->directory);
            \rmdir($target);
        }
    }
}
