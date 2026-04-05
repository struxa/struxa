<?php

declare(strict_types=1);

namespace App\Blueprint;

/**
 * Validates CMS blueprint JSON (full structure package).
 */
final class BlueprintSchemaValidator
{
    public const VERSION = '1.0';

    /**
     * @return list<string> empty if valid
     */
    public function validate(mixed $data): array
    {
        if (!is_array($data)) {
            return ['Root must be a JSON object.'];
        }
        $ver = $data['cms_blueprint_version'] ?? null;
        if (!is_string($ver) || $ver !== self::VERSION) {
            return ['cms_blueprint_version must be "' . self::VERSION . '".'];
        }
        $label = $data['label'] ?? '';
        if ($label !== null && !is_string($label)) {
            return ['label must be a string.'];
        }
        if (!isset($data['content_types']) || !is_array($data['content_types'])) {
            return ['content_types must be an array.'];
        }
        foreach ($data['content_types'] as $i => $t) {
            if (!is_array($t)) {
                return ["content_types[$i] must be an object."];
            }
            $slug = $t['slug'] ?? '';
            $name = $t['name'] ?? '';
            if (!is_string($slug) || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{0,62}$/', $slug)) {
                return ["content_types[$i].slug invalid."];
            }
            if (!is_string($name) || $name === '') {
                return ["content_types[$i].name required."];
            }
            if (isset($t['fields']) && !is_array($t['fields'])) {
                return ["content_types[$i].fields must be an array."];
            }
            if (isset($t['taxonomies']) && !is_array($t['taxonomies'])) {
                return ["content_types[$i].taxonomies must be an array."];
            }
        }
        if (isset($data['menus']) && !is_array($data['menus'])) {
            return ['menus must be an array.'];
        }
        if (isset($data['settings']) && !is_array($data['settings'])) {
            return ['settings must be an object map.'];
        }
        if (isset($data['required_plugin_slugs']) && !is_array($data['required_plugin_slugs'])) {
            return ['required_plugin_slugs must be an array of strings.'];
        }
        if (isset($data['pages']) && !is_array($data['pages'])) {
            return ['pages must be an array.'];
        }
        if (isset($data['redirects']) && !is_array($data['redirects'])) {
            return ['redirects must be an array.'];
        }
        if (array_key_exists('public_homepage_page_slug', $data) && !is_string($data['public_homepage_page_slug'])) {
            return ['public_homepage_page_slug must be a string.'];
        }
        if (isset($data['media_seed'])) {
            if (!is_array($data['media_seed'])) {
                return ['media_seed must be an array.'];
            }
            foreach ($data['media_seed'] as $i => $row) {
                if (!is_array($row)) {
                    return ["media_seed[$i] must be an object."];
                }
                $ms = $row['slug'] ?? null;
                $su = $row['source_url'] ?? null;
                if (!is_string($ms) || trim($ms) === '') {
                    return ["media_seed[$i].slug must be a non-empty string."];
                }
                if (!is_string($su) || trim($su) === '') {
                    return ["media_seed[$i].source_url must be a non-empty string."];
                }
            }
        }

        return [];
    }
}
