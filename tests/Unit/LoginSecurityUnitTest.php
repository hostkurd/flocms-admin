<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Auth\QrCode;
use FloCMS\Admin\Auth\SecretBox;
use FloCMS\Admin\Auth\Totp;
use FloCMS\Admin\Auth\TrustedDevices;
use PHPUnit\Framework\TestCase;

/** Login security (0.35): codes, keys, the QR code writer and the secret box. */
final class LoginSecurityUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_KEY']);
        putenv('APP_KEY');
    }

    /** RFC 6238 appendix B (SHA-1), last six digits of each eight-digit value. */
    public function testCodesMatchTheRfcTestVectors(): void
    {
        $secret = '12345678901234567890';
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130'] as $time => $code) {
            self::assertSame($code, Totp::code($secret, Totp::step($time)), (string) $time);
        }
    }

    public function testVerifyAcceptsOneStepEitherSideAndOnlyLaterSteps(): void
    {
        $secret = '12345678901234567890';
        $now = 1111111111;
        $step = Totp::step($now);
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $step), 0, $now));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), 0, $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), 0, $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), 0, $now), 'two steps old');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step), $step, $now), 'a code works once');
        self::assertSame($step, Totp::verify($secret, substr(Totp::code($secret, $step), 0, 3) . ' ' . substr(Totp::code($secret, $step), 3), 0, $now), 'spaces are ignored');
        self::assertNull(Totp::verify($secret, '12345', 0, $now));
    }

    public function testBase32RoundTripsAndMatchesTheRfc4648Vectors(): void
    {
        foreach (['f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'] as $plain => $encoded) {
            self::assertSame($encoded, Totp::base32Encode($plain));
            self::assertSame($plain, Totp::base32Decode($encoded));
        }
        $secret = Totp::newSecret();
        self::assertSame(20, strlen($secret));
        self::assertSame($secret, Totp::base32Decode(Totp::displayKey($secret)));
        self::assertNull(Totp::base32Decode('not base32!'));
    }

    public function testTheUriFollowsTheKeyUriFormat(): void
    {
        $uri = Totp::uri('12345678901234567890', 'Acme: Admin', 'sara@example.com');
        self::assertSame('otpauth://totp/Acme%20Admin:sara%40example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Acme%20Admin&algorithm=SHA1&digits=6&period=30', $uri);
    }

    public function testQrCodesPickTheSmallestVersionAndKeepTheirShape(): void
    {
        $qr = QrCode::encode('otpauth://totp/Test:sara%40example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Test');
        self::assertSame(6, $qr->version);
        self::assertSame(41, $qr->size());
        // Finder patterns in three corners, the dark module, the timing pattern.
        foreach ([[0, 0], [0, 34], [34, 0]] as [$row, $column]) {
            self::assertTrue($qr->isDark($row, $column));
            self::assertTrue($qr->isDark($row + 6, $column + 6));
            self::assertFalse($qr->isDark($row + 1, $column + 1));
            self::assertTrue($qr->isDark($row + 3, $column + 3));
        }
        self::assertTrue($qr->isDark(41 - 8, 8));
        for ($i = 8; $i < 33; $i++) { self::assertSame($i % 2 === 0, $qr->isDark(6, $i)); }
        self::assertSame(1, QrCode::encode(str_repeat('a', 14))->version);
        self::assertSame(2, QrCode::encode(str_repeat('a', 15))->version);
        self::assertSame(10, QrCode::encode(str_repeat('a', 213))->version);
        self::assertSame(11, QrCode::encode(str_repeat('a', 214))->version);
        $svg = $qr->svg('QR code');
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 49 49"', $svg);
        self::assertStringContainsString('aria-label="QR code"', $svg);
    }

    /** The same text always gives the same matrix (checked once against a QR decoder). */
    public function testQrCodeOutputIsStable(): void
    {
        $qr = QrCode::encode('otpauth://totp/Test:sara%40example.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Test');
        $bits = '';
        for ($r = 0; $r < $qr->size(); $r++) { for ($c = 0; $c < $qr->size(); $c++) { $bits .= $qr->isDark($r, $c) ? '1' : '0'; } }
        self::assertSame(self::GOLDEN, hash('sha256', $bits));
    }

    private const GOLDEN = '308f6dbe5c21d0ac17097ed4c77aeb9ac5025833b5d2af95d8227ea176297b68';

    public function testTooLongTextIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::encode(str_repeat('a', 700));
    }

    public function testSecretsAreEncryptedWithAppKey(): void
    {
        $_ENV['APP_KEY'] = str_repeat('k', 40);
        self::assertTrue(SecretBox::ready());
        $stored = SecretBox::encrypt('12345678901234567890');
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('1234567890', $stored);
        self::assertNotSame($stored, SecretBox::encrypt('12345678901234567890'), 'a new nonce each time');
        self::assertSame('12345678901234567890', SecretBox::decrypt($stored));
        self::assertNull(SecretBox::decrypt('v1:' . base64_encode(str_repeat('x', 40))));

        $_ENV['APP_KEY'] = str_repeat('j', 40);
        self::assertNull(SecretBox::decrypt($stored), 'another APP_KEY can\'t read it');

        $_ENV['APP_KEY'] = 'short';
        self::assertFalse(SecretBox::ready());
        $this->expectException(\RuntimeException::class);
        SecretBox::encrypt('x');
    }

    public function testDevicesAreDescribedFromTheUserAgent(): void
    {
        \FloCMS\Admin\Support\AdminLang::setLanguage('en');
        self::assertSame('Chrome on Windows', TrustedDevices::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'));
        self::assertSame('Safari on iPhone', TrustedDevices::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
        self::assertSame('Edge on macOS', TrustedDevices::describe('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36 Edg/141.0'));
        self::assertSame('Firefox on Linux', TrustedDevices::describe('Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0'));
        self::assertSame('Unknown device', TrustedDevices::describe(''));
    }

    /**
     * The sign-in steps are registered from config/admin.php's code, before the
     * site reads .env (0.39.4): which switches may be on.
     */
    public function testConfigSwitchesAreReadFromTheFilesCode(): void
    {
        $file = sys_get_temp_dir() . '/flo-admin-switches-' . bin2hex(random_bytes(4)) . '.php';
        $cases = [
            "'two_factor' => true," => [true, false],
            "'two_factor' => false, 'email_verification' => null," => [false, false],
            "'brand' => 'x'," => [false, false],
            "'two_factor' => \\FloCMS\\Core\\Env::get('TWO_FACTOR', false)," => [true, false],
            "'two_factor' => E::get('TWO_FACTOR')," => [true, false],
            "'two_factor' => (bool) \$_ENV['TWO_FACTOR'] ?? false, 'email_verification' => \$verify," => [true, true],
            "'email_verification' => ['required' => true]," => [false, true],
            "'email_verification' => ['required_roles' => [2], 'enabled' => false]," => [false, false],
            "'email_verification' => ['required_roles' => [2], 'enabled' => getenv('VERIFY') === '1']," => [false, true],
            "'two_factor' => array('enabled' => false, 'issuer' => Env::get('APP_NAME'))," => [false, false],
            "// 'two_factor' => Env::get('TWO_FACTOR'),\n'brand' => Env::get('SITE_NAME_ADMIN', 'Admin')," => [false, false],
            "/* 'email_verification' => true, */ 'two_factor' => false," => [false, false],
            "'two_factor' => ['required_roles' => ['enabled' => false]]," => [true, false],
            "'two_factor' => ['enabled' => false, 'enabled' => true]," => [true, false],
        ];
        try {
            foreach ($cases as $code => [$twoFactor, $verification]) {
                file_put_contents($file, "<?php\nreturn [" . $code . "];\n");
                self::assertSame(['two_factor' => $twoFactor, 'email_verification' => $verification], \FloCMS\Admin\Support\ConfigSwitches::scan($file), $code);
            }
        } finally {
            @unlink($file);
        }
        self::assertSame(['two_factor' => false, 'email_verification' => false], \FloCMS\Admin\Support\ConfigSwitches::scan($file));
    }
}
