#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Provision Struxa Vision homepage content types, hero entry (with illustration), and CMS Home page.
 * Run from CMS root: php themes/struxa-theme/bin/provision-home.php
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

use App\Cli\CmsCliEnv;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Media\MediaRepository;
use App\Page\PageRepository;
use App\Section\PageSectionRepository;
use App\Settings\SettingsRepository;

$dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
$dbPort = CmsCliEnv::get('DB_PORT', '3306');
$dbName = CmsCliEnv::get('DB_NAME', 'studio');
$dbUser = CmsCliEnv::get('DB_USER', 'studio');
$dbPass = CmsCliEnv::get('DB_PASS', 'studio');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

$types = new ContentTypeRepository($pdo);
$fields = new ContentFieldRepository($pdo);
$entries = new ContentEntryRepository($pdo);
$values = new ContentEntryValueRepository($pdo);
$pages = new PageRepository($pdo);
$pageSections = new PageSectionRepository($pdo);
$settings = new SettingsRepository($pdo);
$media = new MediaRepository($pdo);

function ensureType(ContentTypeRepository $types, ContentFieldRepository $fields, string $slug, string $name, bool $publicRoute, array $fieldDefs): int
{
    $existing = $types->findBySlug($slug);
    if ($existing !== null) {
        return $existing->id;
    }
    $typeId = $types->insert($name, $slug, 'star-outline', 'Struxa Vision demo content', $publicRoute, false, true, false);
    foreach ($fieldDefs as $i => $def) {
        $fields->insert(
            $typeId,
            $def['label'],
            $def['key'],
            $def['type'],
            '',
            $def['help'] ?? '',
            false,
            '',
            '',
            ($i + 1) * 10
        );
    }

    return $typeId;
}

$heroTypeId = ensureType($types, $fields, 'homepage-hero', 'Homepage hero', false, [
    ['key' => 'badge_1', 'label' => 'Badge 1', 'type' => 'text'],
    ['key' => 'badge_2', 'label' => 'Badge 2', 'type' => 'text'],
    ['key' => 'headline', 'label' => 'Headline', 'type' => 'text'],
    ['key' => 'lead', 'label' => 'Lead paragraph', 'type' => 'textarea'],
    ['key' => 'primary_cta_label', 'label' => 'Primary CTA label', 'type' => 'text'],
    ['key' => 'primary_cta_url', 'label' => 'Primary CTA URL', 'type' => 'url'],
    ['key' => 'secondary_cta_label', 'label' => 'Secondary CTA label', 'type' => 'text'],
    ['key' => 'secondary_cta_url', 'label' => 'Secondary CTA URL', 'type' => 'url'],
]);
$trustTypeId = ensureType($types, $fields, 'trust-logos', 'Trust logos', true, []);

// Hero illustration → media library
$src = $root . '/themes/struxa-theme/assets/images/hero-illustration.jpg';
$uploadsDir = $root . '/public/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0775, true);
}
$mediaId = null;
if (is_readable($src)) {
    $filename = 'struxa-hero-' . substr(sha1((string) filemtime($src)), 0, 12) . '.jpg';
    $dest = $uploadsDir . '/' . $filename;
    if (!is_file($dest)) {
        copy($src, $dest);
    }
    $size = (int) filesize($dest);
    $existing = $pdo->query("SELECT id FROM cms_media WHERE original_name = 'struxa-hero-illustration.jpg' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($existing !== false) {
        $mediaId = (int) $existing;
    } else {
        $mediaId = $media->insert(
            $filename,
            'struxa-hero-illustration.jpg',
            'image/jpeg',
            'jpg',
            $size,
            '/uploads/' . $filename,
            null,
            null,
            null,
            null
        );
    }
    echo "Hero media id: {$mediaId}\n";
}

$heroFields = $fields->forTypeOrdered($heroTypeId);
$fieldIdByKey = [];
foreach ($heroFields as $f) {
    $fieldIdByKey[$f->fieldKey] = $f->id;
}

$hero = $entries->findByTypeAndSlug($heroTypeId, 'main');
$heroValues = [
    'badge_1' => 'Content types',
    'badge_2' => 'Themes & plugins',
    'headline' => 'Tailored solutions for teams who ship on Struxa.',
    'lead' => 'Join teams who publish structured content with custom types, Twig themes, optional commerce, and an admin built for editors — not spreadsheet chaos.',
    'primary_cta_label' => 'Schedule a consultation',
    'primary_cta_url' => '/register',
    'secondary_cta_label' => '',
    'secondary_cta_url' => '',
];

if ($hero === null) {
    $heroId = $entries->insert(
        $heroTypeId,
        $heroValues['headline'],
        'main',
        'published',
        $mediaId,
        null,
        null,
        null,
        null,
        false,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        '2026-06-01 12:00:00',
        null,
        null,
        null
    );
    echo "Created homepage-hero entry #{$heroId}\n";
} else {
    $heroId = $hero->id;
    echo "Using existing homepage-hero entry #{$heroId}\n";
}

if ($mediaId !== null) {
    $pdo->prepare('UPDATE cms_content_entries SET featured_image_id = ? WHERE id = ?')->execute([$mediaId, $heroId]);
}

foreach ($heroValues as $key => $val) {
    if (!isset($fieldIdByKey[$key])) {
        continue;
    }
    $values->upsert($heroId, $fieldIdByKey[$key], $val);
}

$trustLabels = ['Pages & blocks', 'REST & GraphQL', 'Role-based admin', 'Plugin ecosystem', 'Theme catalog'];
foreach ($trustLabels as $label) {
    $slug = ContentSlugger::slugify($label);
    if ($entries->findByTypeAndSlug($trustTypeId, $slug) !== null) {
        continue;
    }
    $entries->insert(
        $trustTypeId,
        $label,
        $slug,
        'published',
        null,
        null,
        null,
        null,
        null,
        false,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        '2026-06-01 12:00:00',
        null,
        null,
        null
    );
}
echo "Trust logo entries ready.\n";

$homePage = $pages->findBySlug('home');
if ($homePage === null) {
    $pageId = $pages->insert(
        'Home',
        'home',
        null,
        null,
        null,
        null,
        null,
        null,
        false,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        '',
        'published',
        '2026-06-01 12:00:00',
        null,
        null
    );
    echo "Created Home page #{$pageId}\n";
} else {
    $pageId = $homePage->id;
    $pageSections->deleteAllForPage($pageId);
    echo "Reset sections on Home page #{$pageId}\n";
}

$pageSections->insert($pageId, 0, 'content_type_hero', [
    'content_type_slug' => 'homepage-hero',
    'entry_slug' => 'main',
    'fallback_image' => 'images/hero-illustration.jpg',
], ['padding' => 'comfortable', 'background' => 'default']);

$pageSections->insert($pageId, 1, 'vision_trust_bar', [
    'headline' => 'Built for teams who ship structured content on Struxa.',
    'content_type_slug' => 'trust-logos',
    'limit' => '5',
], ['padding' => 'comfortable', 'background' => 'default']);

$settings->upsert('public_homepage_page_id', (string) $pageId, true);
$settings->upsert('active_theme', 'struxa-theme', true);

echo "Public homepage set to page #{$pageId}. Active theme: struxa-theme.\n";
echo "Done. Reload http://localhost:3439/\n";
