<?php

declare(strict_types=1);

namespace App\Privacy;

use PDO;
use PDOException;

final class PersonalDataErasureResult
{
    /**
     * @param array{comments: int, form_entries: int} $deleted
     */
    public function __construct(
        public readonly array $deleted,
    ) {
    }

    public function total(): int
    {
        return $this->deleted['comments'] + $this->deleted['form_entries'];
    }
}

/**
 * Erase personal data for an email address from comments and form submissions.
 */
final class PersonalDataErasureService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{ok: true, result: PersonalDataErasureResult}|array{ok: false, error: string}
     */
    public function erase(string $email): array
    {
        if (!PrivacyEmailHasher::isValidEmail($email)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }

        $normalized = PrivacyEmailHasher::normalize($email);
        $hash = PrivacyEmailHasher::hash($email);

        $comments = $this->eraseComments($hash);
        $forms = $this->eraseFormSubmissions($normalized);

        return [
            'ok' => true,
            'result' => new PersonalDataErasureResult([
                'comments' => $comments,
                'form_entries' => $forms,
            ]),
        ];
    }

    private function eraseComments(string $emailHash): int
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM cms_comments WHERE author_email_hash = :hash');
            $stmt->execute([':hash' => $emailHash]);

            return max(0, $stmt->rowCount());
        } catch (PDOException) {
            return 0;
        }
    }

    private function eraseFormSubmissions(string $normalizedEmail): int
    {
        try {
            $entryIds = $this->matchingFormEntryIds($normalizedEmail);
            if ($entryIds === []) {
                return 0;
            }

            $deleted = 0;
            $stmt = $this->pdo->prepare('DELETE FROM cms_form_entries WHERE id = ? LIMIT 1');
            foreach ($entryIds as $id) {
                $stmt->execute([(int) $id]);
                $deleted += $stmt->rowCount();
            }

            return $deleted;
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * @return list<int>
     */
    private function matchingFormEntryIds(string $normalizedEmail): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT fv.entry_id
             FROM cms_form_entry_values fv
             LEFT JOIN cms_form_fields ff ON ff.id = fv.field_id
             WHERE (
                 (ff.field_type = \'email\' OR fv.field_key IN (\'email\', \'e-mail\', \'email_address\'))
                 AND LOWER(TRIM(fv.value_text)) = :email
             ) OR LOWER(TRIM(fv.value_text)) = :email2'
        );
        $stmt->execute([
            ':email' => $normalizedEmail,
            ':email2' => $normalizedEmail,
        ]);
        $ids = [];
        while ($id = $stmt->fetchColumn()) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
