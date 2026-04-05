#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use App\Asset\CoreAssetResolver;
use App\Blueprint\BlueprintSchemaValidator;
use App\Cache\PublicPageCacheKey;
use App\Cache\PublicResponseCacheEnvelope;
use App\Content\PublicContentIndexPager;
use App\Content\RichtextTabsShortcode;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoFormParser;
use App\Dev\PluginDependencyHealthCheck;
use App\Dev\TwigLayoutContractLinter;
use App\Manifest\ManifestMeta;
use Slim\Psr7\Factory\ServerRequestFactory;

$fail = static function (string $msg): void {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

$v = new BlueprintSchemaValidator();
if ($v->validate([]) === []) {
    $fail('BlueprintSchemaValidator should reject empty root.');
}
$badSlug = [
    'cms_blueprint_version' => '1.0',
    'label' => 'X',
    'content_types' => [
        ['slug' => '!!!', 'name' => 'Bad'],
    ],
];
if ($v->validate($badSlug) === []) {
    $fail('Blueprint with invalid content type slug should fail.');
}
$validMinimal = [
    'cms_blueprint_version' => '1.0',
    'label' => 'Test',
    'content_types' => [
        ['slug' => 'post', 'name' => 'Post'],
    ],
];
if ($v->validate($validMinimal) !== []) {
    $fail('Minimal valid blueprint should pass.');
}

if (ManifestMeta::parseTags(null) !== []) {
    $fail('ManifestMeta::parseTags(null) should be [].');
}
if (ManifestMeta::parseTags(['  a  ', 'b']) !== ['a', 'b']) {
    $fail('ManifestMeta::parseTags trimming failed.');
}
if (ManifestMeta::httpUrlOrNull('ftp://x') !== null) {
    $fail('ManifestMeta::httpUrlOrNull should reject non-http(s).');
}
if (ManifestMeta::httpUrlOrNull('https://example.com') !== 'https://example.com') {
    $fail('ManifestMeta::httpUrlOrNull should accept https URL.');
}

$layoutIssues = (new TwigLayoutContractLinter($root))->lint(false);
foreach ($layoutIssues as $issue) {
    if ($issue->isError()) {
        $fail("Twig layout contract: {$issue->code}\n" . $issue->formatLine());
    }
}

$depIssues = (new PluginDependencyHealthCheck($root))->run(false, false);
foreach ($depIssues as $issue) {
    if ($issue->isError()) {
        $fail("Plugin dependency health: {$issue->code}\n" . $issue->formatLine());
    }
}

if (PublicPageCacheKey::normalizePath('') !== '/') {
    $fail('PublicPageCacheKey::normalizePath empty should be /.');
}
if (PublicPageCacheKey::normalizePath('/foo/bar/') !== '/foo/bar') {
    $fail('PublicPageCacheKey should strip trailing slash.');
}
if (PublicPageCacheKey::normalizePath('///a//b//') !== '/a/b') {
    $fail('PublicPageCacheKey should collapse slashes.');
}

$qOrderA = PublicPageCacheKey::canonicalQuery(['b' => '2', 'a' => '1']);
$qOrderB = PublicPageCacheKey::canonicalQuery(['a' => '1', 'b' => '2']);
if ($qOrderA !== $qOrderB || $qOrderA !== 'a=1&b=2') {
    $fail('PublicPageCacheKey::canonicalQuery should sort keys stably.');
}

$reqFactory = new ServerRequestFactory();
$reqKey = $reqFactory->createServerRequest('GET', 'https://ex.com:8443/foo/?b=2&a=1');
if (PublicPageCacheKey::build($reqKey, 'v2') !== 'v2|https|ex.com:8443|/foo|a=1&b=2|theme:_') {
    $fail('PublicPageCacheKey::build should include scheme, host, port, path, query, theme.');
}
if (!str_ends_with(PublicPageCacheKey::build($reqKey, 'v2', 'queue'), '|theme:queue')) {
    $fail('PublicPageCacheKey::build should append storefront theme slug.');
}

$publicDir = $root . DIRECTORY_SEPARATOR . 'public';
$coreAsset = new CoreAssetResolver($publicDir, false);
$adminCss = $coreAsset->url('css/admin.css');
if ($adminCss === '' || !str_starts_with($adminCss, '/css/admin.css')) {
    $fail('CoreAssetResolver should return root-relative path for existing file.');
}
$coreAssetMin = new CoreAssetResolver($publicDir, true);
$adminCssMinPref = $coreAssetMin->url('css/admin.css');
if (str_contains($adminCssMinPref, '.min.css')) {
    $fail('CoreAssetResolver should not switch to missing .min.css.');
}
if ($coreAsset->url('../secret.css') !== '') {
    $fail('CoreAssetResolver should reject path traversal.');
}

$payload = ['status' => 200, 'headers' => ['Content-Type' => ['text/html']], 'body' => '<p>x</p>'];
$wrapped = PublicResponseCacheEnvelope::wrap('v2|https|ex.com|/|', $payload);
if (PublicResponseCacheEnvelope::responsePayload($wrapped) !== $payload) {
    $fail('PublicResponseCacheEnvelope wrap/unwrap should round-trip.');
}
if (PublicResponseCacheEnvelope::responsePayload($payload) !== $payload) {
    $fail('PublicResponseCacheEnvelope should accept legacy bare payload.');
}

if (PublicContentIndexPager::pageItems(1, 1) !== [1]) {
    $fail('PublicContentIndexPager should return [1] for a single page.');
}
$p3 = PublicContentIndexPager::pageItems(2, 3);
if ($p3 !== [1, 2, 3]) {
    $fail('PublicContentIndexPager small range should list all pages.');
}

$tabsOut = RichtextTabsShortcode::transform(
    '[tabs][tab title="A"]<p>one</p>[/tab][tab label="B"]<p>two</p>[/tab][/tabs]'
);
if (!str_contains($tabsOut, 'data-cms-tabs') || !str_contains($tabsOut, '>A</button>') || !str_contains($tabsOut, '>B</button>')) {
    $fail('RichtextTabsShortcode should render tab bar and labels.');
}
$single = RichtextTabsShortcode::transform('[tabs]<p>only</p>[/tabs]');
if (!str_contains($single, 'cms-tabs-shortcode--single') || str_contains($single, 'data-cms-tabs')) {
    $fail('RichtextTabsShortcode single-pane should not use data-cms-tabs.');
}

$badLd = SeoFormParser::normalizeSchemaJsonForStorage('{not json');
if ($badLd['error'] === null || $badLd['value'] !== null) {
    $fail('SeoFormParser should reject invalid schema JSON.');
}
$goodLd = SeoFormParser::normalizeSchemaJsonForStorage('{"@context":"https://schema.org","name":"</script>"}');
if ($goodLd['error'] !== null || $goodLd['value'] === null) {
    $fail('SeoFormParser should accept valid JSON with angle brackets in strings.');
}
$safeScript = MetaTagBuilder::jsonLdSafeForScript($goodLd['value']);
if ($safeScript === null || str_contains(strtolower($safeScript), '</script>')) {
    $fail('MetaTagBuilder::jsonLdSafeForScript should escape script breakout sequences.');
}

echo "All tests passed.\n";
exit(0);
