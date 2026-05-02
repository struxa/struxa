<?php

declare(strict_types=1);

namespace App\Security;

use RobThree\Auth\Providers\Qr\QRServerProvider;
use RobThree\Auth\TwoFactorAuth;

final class TotpService
{
    private TwoFactorAuth $tfa;

    public function __construct(?string $issuer = null)
    {
        $issuer = $issuer !== null && $issuer !== '' ? $issuer : 'CMS';
        $this->tfa = new TwoFactorAuth(new QRServerProvider(), $issuer);
    }

    public function createSecret(): string
    {
        return $this->tfa->createSecret();
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return $code !== '' && $this->tfa->verifyCode($secret, $code);
    }

    public function getQrDataUri(string $accountLabel, string $secret, int $size = 200): string
    {
        return $this->tfa->getQRCodeImageAsDataUri($accountLabel, $secret, $size);
    }
}
