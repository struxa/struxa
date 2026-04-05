<?php

declare(strict_types=1);

namespace App\Auth;

use PHPAuth\Auth;

/**
 * Extends PHPAuth to support a two-step login: password verification without a session,
 * then {@see completeSessionAfterTwoFactor()} after TOTP succeeds.
 */
final class AppAuth extends Auth
{
    /**
     * Validates email/password/active user without creating a session (for 2FA gate).
     *
     * @return array{error: true, message: string}|array{error: false, uid: int, remember: int}
     */
    public function verifyPasswordPreSession(string $email, string $password, int $remember = 0, string $captcha_response = ''): array
    {
        $return = ['error' => true, 'message' => ''];

        $block_status = $this->isBlocked();

        if ($block_status === 'verify') {
            if (!$this->checkCaptcha($captcha_response)) {
                $return['message'] = $this->__lang('captcha.verify_code_invalid');

                return $return;
            }
        }

        if ($block_status === 'block') {
            $return['message'] = $this->__lang('user.temporary_banned');

            return $return;
        }

        $validateEmail = $this->validateEmail($email);
        $validatePassword = $this->validatePasswordLength($password);

        if ($validateEmail['error'] == 1) {
            $this->addAttempt();
            $return['message'] = $validateEmail['message'];

            return $return;
        }
        if ($validatePassword['error'] == 1) {
            $this->addAttempt();
            $return['message'] = $validatePassword['message'];

            return $return;
        }
        if ($remember !== 0 && $remember !== 1) {
            $this->addAttempt();
            $return['message'] = $this->__lang('remember_me_invalid');

            return $return;
        }

        $uid = $this->getUID($email);

        if (!$uid) {
            $this->addAttempt();
            $return['message'] = $this->__lang('account.not_found');

            return $return;
        }

        $user = $this->getBaseUser($uid);
        if (!$user || !is_array($user)) {
            $this->addAttempt();
            $return['message'] = $this->__lang('account.not_found');

            return $return;
        }

        if (!$this->password_verify_with_rehash($password, $user['password'], $uid)) {
            $this->addAttempt();
            $return['message'] = $this->__lang('account.no_pair_user_and_password');

            return $return;
        }

        if ($user['isactive'] != 1) {
            $this->addAttempt();
            $return['message'] = $this->__lang('account.not_activated');

            return $return;
        }

        return ['error' => false, 'uid' => (int) $user['uid'], 'remember' => $remember];
    }

    /**
     * Creates the PHPAuth session after TOTP (or when 2FA is not required).
     *
     * @return array{error: true, message: string}|array{error: false, message: string, hash: string, expire: int, cookie_name: string}
     */
    public function completeSessionAfterTwoFactor(int $uid, int $remember): array
    {
        $return = ['error' => true, 'message' => ''];

        $user = $this->getBaseUser($uid);
        if (!$user || !is_array($user)) {
            $return['message'] = $this->__lang('account.not_found');

            return $return;
        }

        if ($user['isactive'] != 1) {
            $return['message'] = $this->__lang('account.not_activated');

            return $return;
        }

        $sessiondata = $this->addSession($user['uid'], $remember === 1);

        if (!$sessiondata) {
            $return['message'] = $this->__lang('system.error') . ' #01';

            return $return;
        }

        $return['error'] = false;
        $return['message'] = $this->__lang('logged_in');
        $return['hash'] = $sessiondata['hash'];
        $return['expire'] = $sessiondata['expire'];
        $return['cookie_name'] = $this->config->cookie_name;

        return $return;
    }
}
