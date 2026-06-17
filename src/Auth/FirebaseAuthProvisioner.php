<?php

declare(strict_types=1);

namespace App\Auth;

use App\CmsUserRepository;
use PDO;
use PHPAuth\Auth;

/**
 * Maps a verified Firebase account to PHPAuth + cms_users (member row).
 */
final class FirebaseAuthProvisioner
{
    public function __construct(
        private readonly FirebaseConfig $config,
        private readonly PDO $pdo,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param array{local_id: string, email: string, email_verified: bool, display_name: string} $firebaseUser
     * @return array{ok: true, phpauth_uid: int, email: string}|array{ok: false, message: string}
     */
    public function resolvePhpAuthUser(array $firebaseUser): array
    {
        if (!$firebaseUser['email_verified']) {
            return ['ok' => false, 'message' => 'Your Firebase account email is not verified. Verify it, then try again.'];
        }

        $email = $firebaseUser['email'];
        if (!$this->config->emailDomainAllowed($email)) {
            return ['ok' => false, 'message' => 'This account is not allowed to sign in here.'];
        }

        $firebaseUid = $firebaseUser['local_id'];
        $displayName = $firebaseUser['display_name'] !== ''
            ? $firebaseUser['display_name']
            : (str_contains($email, '@') ? substr($email, 0, (int) strrpos($email, '@')) : $email);

        $byFirebase = CmsUserRepository::findByFirebaseUid($this->pdo, $firebaseUid);
        if ($byFirebase !== null) {
            $phpauthUid = (int) ($byFirebase['phpauth_user_id'] ?? 0);
            if ($phpauthUid < 1) {
                return ['ok' => false, 'message' => 'Account link is invalid. Contact an administrator.'];
            }

            return ['ok' => true, 'phpauth_uid' => $phpauthUid, 'email' => $email];
        }

        $phpauthUid = (int) $this->auth->getUID($email);
        if ($phpauthUid < 1) {
            if (!$this->config->autoProvision) {
                return [
                    'ok' => false,
                    'message' => 'No account exists for that email. Use email and password, or ask an administrator to invite you.',
                ];
            }

            $random = bin2hex(random_bytes(32));
            $reg = $this->auth->register($email, $random, $random, [], '', false);
            if (($reg['error'] ?? true) === true) {
                return ['ok' => false, 'message' => (string) ($reg['message'] ?? 'Could not create an account.')];
            }
            $phpauthUid = (int) ($reg['uid'] ?? 0);
            if ($phpauthUid < 1) {
                return ['ok' => false, 'message' => 'Could not create an account.'];
            }
        }

        $existingUid = CmsUserRepository::firebaseUidOwnerPhpAuthId($this->pdo, $firebaseUid, $phpauthUid);
        if ($existingUid !== null && $existingUid !== $phpauthUid) {
            return ['ok' => false, 'message' => 'This Firebase account is already linked to another user.'];
        }

        try {
            CmsUserRepository::ensureMemberWithFirebase(
                $this->pdo,
                $phpauthUid,
                $email,
                $displayName,
                $firebaseUid,
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_cms_users_firebase_uid') || str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'message' => 'This Firebase account is already linked to another user.'];
            }

            throw $e;
        }

        return ['ok' => true, 'phpauth_uid' => $phpauthUid, 'email' => $email];
    }
}
