<?php

declare(strict_types=1);

namespace App\Content;

/**
 * options_json for field_type "entry_refs".
 *
 * Example: {"target_content_type_id": 3, "max_refs": 12, "require_public_targets": true}
 * - target_content_type_id: omit or 0 = any content type that has a public route
 * - max_refs: 1–100, default 25
 * - require_public_targets: when true (default), saving as published requires each target to be publicly visible
 */
final class ContentEntryRefsFieldOptions
{
    public function __construct(
        public readonly ?int $targetContentTypeId,
        public readonly int $maxRefs,
        public readonly bool $requirePublicTargets,
    ) {
    }

    public static function fromField(ContentField $field): self
    {
        $raw = $field->optionsJson;
        if ($raw === null || trim($raw) === '') {
            return new self(null, 25, true);
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(null, 25, true);
        }
        if (!is_array($data)) {
            return new self(null, 25, true);
        }
        $tid = null;
        if (isset($data['target_content_type_id'])) {
            $v = (int) $data['target_content_type_id'];
            $tid = $v > 0 ? $v : null;
        }
        $max = isset($data['max_refs']) ? (int) $data['max_refs'] : 25;
        $max = max(1, min(100, $max));
        $reqPub = !array_key_exists('require_public_targets', $data) || !empty($data['require_public_targets']);

        return new self($tid, $max, $reqPub);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, json: ?string}
     */
    public static function validateOptionsBody(array $body): array
    {
        $errors = [];
        $raw = isset($body['options_json']) && is_string($body['options_json']) ? trim($body['options_json']) : '';
        if ($raw === '') {
            return ['errors' => $errors, 'json' => null];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors['options_json'] = 'Invalid JSON for entry link options.';

            return ['errors' => $errors, 'json' => null];
        }
        if (!is_array($data)) {
            $errors['options_json'] = 'Entry link options must be a JSON object.';

            return ['errors' => $errors, 'json' => null];
        }
        $opts = self::fromDecoded($data);
        try {
            $enc = json_encode([
                'target_content_type_id' => $opts->targetContentTypeId ?? 0,
                'max_refs' => $opts->maxRefs,
                'require_public_targets' => $opts->requirePublicTargets,
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors['options_json'] = 'Could not encode entry link options.';

            return ['errors' => $errors, 'json' => null];
        }

        return ['errors' => $errors, 'json' => $enc];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromDecoded(array $data): self
    {
        $tid = null;
        if (isset($data['target_content_type_id'])) {
            $v = (int) $data['target_content_type_id'];
            $tid = $v > 0 ? $v : null;
        }
        $max = isset($data['max_refs']) ? (int) $data['max_refs'] : 25;
        $max = max(1, min(100, $max));
        $reqPub = !array_key_exists('require_public_targets', $data) || !empty($data['require_public_targets']);

        return new self($tid, $max, $reqPub);
    }
}
