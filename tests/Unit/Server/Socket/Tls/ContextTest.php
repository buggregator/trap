<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Socket\Tls;

use Buggregator\Trap\Server\Config\Tls;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Socket\Tls\Context;
use Buggregator\Trap\Server\Socket\Tls\SelfSignedCertificate;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Context::class)]
final class ContextTest
{
    #[BeforeTest]
    public function requireOpenssl(): void
    {
        \extension_loaded('openssl') or throw new SkipTest('TLS needs ext-openssl.');
    }

    #[Test]
    public function usesConfiguredCertificateAndKey(): void
    {
        $pem = self::certificate('trap-tls-tests');
        $config = new Tls();
        $config->cert = $pem;
        $config->key = $pem;

        $context = Context::create($config, new Logger());

        Assert::notNull($context);
        Assert::same($context->options['local_cert'], $pem);
        Assert::same($context->options['local_pk'], $pem);
        Assert::same($context->options['alpn_protocols'], 'http/1.1');
        Assert::true($context->trusted);
    }

    #[Test]
    public function isOffWhenConfiguredFileIsMissing(): void
    {
        $config = new Tls();
        $config->cert = self::certificate('trap-tls-tests');
        $config->key = __DIR__ . '/missing.pem';
        $output = new BufferedOutput();

        Assert::null(Context::create($config, new Logger($output)));
        Assert::string($output->fetch())->contains('missing.pem');
    }

    #[Test]
    public function isOffWhenFileHasNoCertificate(): void
    {
        $config = new Tls();
        $config->cert = __FILE__;
        $output = new BufferedOutput();

        Assert::null(Context::create($config, new Logger($output)));
        Assert::string($output->fetch())->contains('no certificate');
    }

    #[Test]
    public function isOffWhenKeyDoesNotMatchCertificate(): void
    {
        $config = new Tls();
        $config->cert = self::certificate('trap-tls-tests');
        $config->key = self::certificate('trap-tls-tests-other');
        $output = new BufferedOutput();

        Assert::null(Context::create($config, new Logger($output)));
        Assert::string($output->fetch())->contains('does not match');
    }

    #[Test]
    public function generatesCertificateWhenNoneIsConfigured(): void
    {
        $directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'trap-tls-tests';
        $output = new BufferedOutput();

        $context = Context::create(new Tls(), new Logger($output), $directory);

        Assert::notNull($context);
        Assert::same($context->options['local_cert'], $directory . \DIRECTORY_SEPARATOR . 'localhost.pem');
        Assert::false(isset($context->options['local_pk']));
        Assert::false($context->trusted);
        Assert::string($output->fetch())->contains('localhost.pem');
    }

    /**
     * @param non-empty-string $directory
     *
     * @return non-empty-string A PEM file with a certificate and its key.
     */
    private static function certificate(string $directory): string
    {
        return SelfSignedCertificate::ensure(\sys_get_temp_dir() . \DIRECTORY_SEPARATOR . $directory);
    }
}
