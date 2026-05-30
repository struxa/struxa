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
use App\Analytics\ExternalLinkClickRepository;
use App\Content\ReservedContentSlugs;
use App\Http\SafeRedirectPath;
use App\Plugin\PluginAdminNavGrouper;
use App\Plugin\PluginScanner;
use App\Search\ContentSearchService;
use App\Seo\ExternalLinkPolicy;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoFormParser;
use App\Security\IpBlockMatcher;
use App\Security\IpBlockPatternValidator;
use App\Dev\PluginDependencyHealthCheck;
use App\Dev\TwigLayoutContractLinter;
use App\Maintenance\MaintenanceService;
use App\Media\MediaFolderFilter;
use App\Form\FormFieldType;
use App\Form\FormSlugger;
use App\Form\FormValidator;
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

if (!ExternalLinkPolicy::hrefIsExternalHttp('https://other.com/x', 'example.com')) {
    $fail('ExternalLinkPolicy should treat other host as external.');
}
if (ExternalLinkPolicy::hrefIsExternalHttp('https://example.com/x', 'example.com')) {
    $fail('ExternalLinkPolicy should not mark same host as external.');
}
if (ExternalLinkPolicy::hrefIsExternalHttp('/local', 'example.com')) {
    $fail('ExternalLinkPolicy relative href is not external http.');
}
$navRel = ExternalLinkPolicy::anchorRelForNavLink('https://x.com', '_blank', true, 'mysite.com');
if (!str_contains($navRel, 'nofollow') || !str_contains($navRel, 'noopener')) {
    $fail('ExternalLinkPolicy::anchorRelForNavLink should combine blank target and nofollow.');
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

if (IpBlockMatcher::isBlocked('192.0.2.1', ['192.0.2.2'])) {
    $fail('IpBlockMatcher should not block different IPv4.');
}
if (!IpBlockMatcher::isBlocked('192.0.2.1', ['192.0.2.1'])) {
    $fail('IpBlockMatcher should block exact IPv4.');
}
if (!IpBlockMatcher::isBlocked('192.0.2.10', ['192.0.2.0/24'])) {
    $fail('IpBlockMatcher should block IPv4 in CIDR.');
}
if (IpBlockMatcher::isBlocked('192.0.3.1', ['192.0.2.0/24'])) {
    $fail('IpBlockMatcher should not block IPv4 outside CIDR.');
}
$cidrNorm = IpBlockPatternValidator::normalize('192.0.2.5/24');
if (!$cidrNorm['ok'] || $cidrNorm['pattern'] !== '192.0.2.0/24') {
    $fail('IpBlockPatternValidator should normalize IPv4 CIDR to network base.');
}

if (!ReservedContentSlugs::isReserved('search')) {
    $fail('ReservedContentSlugs should treat "search" as reserved.');
}
if (!ReservedContentSlugs::isReserved('admin')) {
    $fail('ReservedContentSlugs should treat core segment "admin" as reserved.');
}
if (ReservedContentSlugs::isReserved('my-catalog')) {
    $fail('ReservedContentSlugs should not treat unregistered plugin segment "my-catalog" as reserved.');
}
ReservedContentSlugs::registerPluginReservedSlugs(['my-catalog', 'my-reviews']);
if (!ReservedContentSlugs::isReserved('my-catalog') || !ReservedContentSlugs::isReserved('my-reviews')) {
    $fail('ReservedContentSlugs should treat plugin-registered segments as reserved.');
}
if (in_array('casino-review', ReservedContentSlugs::coreReservedSlugs(), true)) {
    $fail('Core RESERVED must not contain application-specific slugs like casino-review.');
}
ReservedContentSlugs::resetPluginReservedSlugsForTesting();

if (SafeRedirectPath::afterLogin('/admin', '/home') !== '/admin') {
    $fail('SafeRedirectPath should allow a simple absolute same-origin path.');
}
if (SafeRedirectPath::afterLogin(null, '/x') !== '/x') {
    $fail('SafeRedirectPath should return fallback for null input.');
}
if (SafeRedirectPath::afterLogin('//evil.com/x', '/x') !== '/x') {
    $fail('SafeRedirectPath should reject protocol-relative URLs.');
}
if (SafeRedirectPath::afterLogin('/\\evil.com', '/x') !== '/x') {
    $fail('SafeRedirectPath should reject paths that begin with a backslash-bypass.');
}
if (SafeRedirectPath::afterLogin("/admin\r\nLocation: //evil.com", '/x') !== '/x') {
    $fail('SafeRedirectPath should reject CR/LF for header-injection.');
}
if (SafeRedirectPath::afterLogin("/admin\tfoo", '/x') !== '/x') {
    $fail('SafeRedirectPath should reject tab characters.');
}
if (SafeRedirectPath::afterLogin('/javascript://evil.com', '/x') !== '/x') {
    $fail('SafeRedirectPath should reject scheme smuggling like /javascript://...');
}
if (SafeRedirectPath::afterLogin('/../etc/passwd', '/x') !== '/x') {
    $fail('SafeRedirectPath should reject /.. parent traversal.');
}
if (SafeRedirectPath::afterLogin('/admin/users?q=1', '/x') !== '/admin/users?q=1') {
    $fail('SafeRedirectPath should preserve same-origin paths with query strings.');
}

$scanner = new PluginScanner($root);
$part = PluginAdminNavGrouper::partition([
    ['plugin_slug' => 'alpha', 'label' => 'Zebra', 'route_name' => 'home', 'route_params' => [], 'parent_plugin_slug' => null],
    ['plugin_slug' => 'child', 'label' => 'Apple', 'route_name' => 'home', 'route_params' => [], 'parent_plugin_slug' => 'content-stream-plugin'],
    ['plugin_slug' => 'child2', 'label' => 'Mango', 'route_name' => 'home', 'route_params' => [], 'parent_plugin_slug' => 'content-stream-plugin'],
], $scanner);
if (count($part['flat']) !== 1 || ($part['flat'][0]['plugin_slug'] ?? '') !== 'alpha') {
    $fail('PluginAdminNavGrouper should keep non-child items in flat.');
}
if (count($part['groups']) !== 1) {
    $fail('PluginAdminNavGrouper should merge siblings under one parent slug.');
}
$kids = $part['groups'][0]['items'] ?? [];
if (count($kids) !== 2 || ($kids[0]['label'] ?? '') !== 'Apple' || ($kids[1]['label'] ?? '') !== 'Mango') {
    $fail('PluginAdminNavGrouper should sort children by label.');
}
if (($part['groups'][0]['label'] ?? '') === '' || !is_string($part['groups'][0]['label'])) {
    $fail('PluginAdminNavGrouper should set a non-empty parent label.');
}

if (ContentSearchService::sanitizeQuery(" \t\n  ") !== '') {
    $fail('ContentSearchService::sanitizeQuery should return "" for whitespace-only input.');
}
if (ContentSearchService::sanitizeQuery('a') !== '') {
    $fail('ContentSearchService::sanitizeQuery should reject single-char input.');
}
$sanLong = str_repeat('x', 200);
$out = ContentSearchService::sanitizeQuery($sanLong);
if (strlen($out) !== ContentSearchService::MAX_QUERY_LENGTH) {
    $fail('ContentSearchService::sanitizeQuery should cap at MAX_QUERY_LENGTH.');
}
if (ContentSearchService::sanitizeQuery("hello\x00\x07world") !== 'hello world') {
    $fail('ContentSearchService::sanitizeQuery should strip control characters.');
}
if (ContentSearchService::escapeLike('100% _safe \\path') !== '100\\% \\_safe \\\\path') {
    $fail('ContentSearchService::escapeLike should escape backslash, percent, and underscore.');
}
$snippet = ContentSearchService::extractSnippet(
    'The quick brown fox jumps over the lazy dog and runs across the meadow many times today.',
    'lazy',
    40
);
if (strpos($snippet, 'lazy') === false) {
    $fail('ContentSearchService::extractSnippet should include the matched term.');
}
if (ContentSearchService::plainText('<script>alert(1)</script><p>Hello <b>world</b>!</p>') !== 'Hello world!') {
    $fail('ContentSearchService::plainText should strip script blocks and HTML tags.');
}

$hashA = ExternalLinkClickRepository::destinationHash('https://EXAMPLE.com/path');
$hashB = ExternalLinkClickRepository::destinationHash('  https://example.com/path  ');
if ($hashA !== $hashB) {
    $fail('ExternalLinkClickRepository::destinationHash should be case-insensitive and trim whitespace.');
}
$hashC = ExternalLinkClickRepository::destinationHash('https://example.com/other');
if ($hashA === $hashC) {
    $fail('ExternalLinkClickRepository::destinationHash should differ for different URLs.');
}

if (MaintenanceService::formatBytes(512) !== '512 B') {
    $fail('MaintenanceService::formatBytes should format bytes.');
}
if (!str_contains(MaintenanceService::formatBytes(2048), 'KB')) {
    $fail('MaintenanceService::formatBytes should format kilobytes.');
}

if (MediaFolderFilter::fromQueryParams([])->mode !== MediaFolderFilter::MODE_ALL) {
    $fail('MediaFolderFilter should default to all files.');
}
$unfiled = MediaFolderFilter::fromQueryParams(['folder' => 'unfiled']);
if ($unfiled->mode !== MediaFolderFilter::MODE_UNFILED) {
    $fail('MediaFolderFilter should parse unfiled.');
}
$inFolder = MediaFolderFilter::fromQueryParams(['folder' => '12']);
if ($inFolder->mode !== MediaFolderFilter::MODE_FOLDER || $inFolder->folderId !== 12) {
    $fail('MediaFolderFilter should parse folder id.');
}
if ($unfiled->toQueryParams() !== ['folder' => 'unfiled']) {
    $fail('MediaFolderFilter::toQueryParams should emit unfiled.');
}

if (!ReservedContentSlugs::isReserved('forms')) {
    $fail('ReservedContentSlugs should treat core segment "forms" as reserved.');
}

$fields = [
    ['id' => 1, 'field_key' => 'email', 'field_type' => FormFieldType::EMAIL, 'label' => 'Email', 'required' => 1],
    ['id' => 2, 'field_key' => '_hp_url', 'field_type' => FormFieldType::HONEYPOT, 'label' => 'HP', 'required' => 0],
];
$ok = FormValidator::validateSubmission(['email' => 'a@b.com'], $fields, true);
if (($ok['ok'] ?? false) !== true) {
    $fail('FormValidator should accept valid email submission.');
}
$spam = FormValidator::validateSubmission(['email' => 'a@b.com', '_hp_url' => 'bot'], $fields, true);
if (($spam['ok'] ?? true) !== false) {
    $fail('FormValidator should reject honeypot hits.');
}
if (FormSlugger::fromName('Contact Us!') !== 'contact-us') {
    $fail('FormSlugger should slugify names.');
}

echo "All tests passed.\n";
exit(0);
