<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\ClientIp;
use PDO;
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

    /**
     * Uses {@see ClientIp} instead of PHPAuth Helpers::getIp() so the bound session IP matches behind
     * reverse proxies when CMS_TRUSTED_PROXY_IPS is set, and stays aligned with $_SERVER when getenv('REMOTE_ADDR')
     * is empty under PHP-FPM.
     */
    public function checkSession(string $hash, ?string $device_id = null): bool
    {
        $ip = ClientIp::fromSuperglobals();
        $block_status = $this->isBlocked();

        if ($block_status == 'block') {
            return false;
        }

        if (strlen($hash) != self::HASH_LENGTH) {
            return false;
        }

        $query = "SELECT id, uid, expiredate, remember, ip, agent, cookie_crc, device_id FROM {$this->config->table_sessions} WHERE hash = :hash";
        $query_prepared = $this->dbh->prepare($query);
        if ($query_prepared === false) {
            return false;
        }
        if (!$query_prepared->execute(['hash' => $hash])) {
            return false;
        }

        $row = $query_prepared->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return false;
        }

        $uid = $row['uid'];
        $expire_date = strtotime($row['expiredate']);
        $current_date = strtotime(date('Y-m-d H:i:s'));
        $db_ip = $row['ip'];
        $db_cookie = $row['cookie_crc'];
        $db_device_id = $row['device_id'];

        if ($current_date > $expire_date) {
            $this->deleteSession($hash);

            return false;
        }

        $skipIpBind = self::phpAuthSkipSessionIpBind();
        if ($device_id != null) {
            if ($db_device_id !== $device_id) {
                return false;
            }
        } elseif (!$skipIpBind && $ip !== $db_ip) {
            return false;
        }

        if ($db_cookie == sha1($hash . $this->config->site_key)) {
            if ($expire_date - $current_date < strtotime($this->config->cookie_renew) - $current_date) {
                $remember = ((int) ($row['remember'] ?? 0)) === 1;
                $this->deleteSession($hash);
                if ($this->addSession((int) $uid, $remember) === false) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function addSession(int $uid, bool $remember)
    {
        $ip = ClientIp::fromSuperglobals();
        $user = $this->getBaseUser($uid);

        if (!$user) {
            return false;
        }

        $data['hash'] = sha1($this->config->site_key . microtime());
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!$this->config->allow_concurrent_sessions) {
            $this->deleteExistingSessions($uid);
        }

        $data['expire']
            = $remember
            ? strtotime($this->config->cookie_remember)
            : strtotime($this->config->cookie_forget);

        $data['cookie_crc'] = sha1($data['hash'] . $this->config->site_key);

        $query = "
            INSERT INTO {$this->config->table_sessions}
            (uid, hash, expiredate, remember, ip, agent, cookie_crc)
            VALUES (:uid, :hash, :expiredate, :remember, :ip, :agent, :cookie_crc)
            ";
        $query_prepared = $this->dbh->prepare($query);
        $query_params = [
            'uid' => $uid,
            'hash' => $data['hash'],
            'expiredate' => date('Y-m-d H:i:s', $data['expire']),
            'remember' => $remember ? 1 : 0,
            'ip' => $ip,
            'agent' => $agent,
            'cookie_crc' => $data['cookie_crc'],
        ];

        if (!$query_prepared->execute($query_params)) {
            return false;
        }

        $cookie_options = [
            'expires' => $data['expire'],
            'path' => $this->config->cookie_path,
            'domain' => $this->config->cookie_domain,
            'secure' => $this->config->cookie_secure,
            'httponly' => $this->config->cookie_http,
            'samesite' => $this->config->cookie_samesite ?? 'Lax',
        ];

        if ($this->config->uses_session) {
            $_SESSION[$this->config->cookie_name] = $data['hash'];
            $_SESSION[$this->config->cookie_name . '_expire'] = $data['expire'];
        } else {
            if (!setcookie($this->config->cookie_name, $data['hash'], $cookie_options)) {
                return false;
            }
            $_COOKIE[$this->config->cookie_name] = $data['hash'];
        }

        return $data;
    }

    private static function phpAuthSkipSessionIpBind(): bool
    {
        $v = $_ENV['CMS_PHPAUTH_SKIP_SESSION_IP_CHECK'] ?? getenv('CMS_PHPAUTH_SKIP_SESSION_IP_CHECK');

        return is_string($v) && trim($v) === '1';
    }
}
