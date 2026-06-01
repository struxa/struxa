#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

use App\Asset\CoreAssetResolver;
use App\Blueprint\BlueprintSchemaValidator;
use App\Cache\PublicPageCacheKey;
use App\Cache\PublicResponseCacheEnvelope;
use App\Access\WorkflowService;
use App\Content\ContentEntryBulkService;
use App\Content\ContentEntryRepository;
use App\Content\PublicContentIndexPager;
use App\Content\RichtextTabsShortcode;
use App\Analytics\ExternalLinkClickRepository;
use App\Content\ReservedContentSlugs;
use App\Http\SafeRedirectPath;
use App\Plugin\PluginCapability;
use App\Plugin\PluginCapabilityException;
use App\Plugin\PluginCapabilityGuard;
use App\Plugin\PluginLoadScope;
use App\Plugin\PluginPerformanceRegistry;
use App\Page\PageDuplicationService;
use App\Privacy\PrivacyEmailHasher;
use App\Revisions\RevisionRetentionSettings;
use App\Plugin\PluginSemverConstraint;
use App\Plugin\PluginManifest;
use App\Plugin\PluginAdminNavGrouper;
use App\Plugin\PluginScanner;
use App\Search\ContentSearchService;
use App\Seo\ExternalLinkPolicy;
use App\Seo\RedirectRepository;
use App\Seo\SlugRedirectResult;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoContentAnalyzer;
use App\Seo\SeoFormParser;
use App\Seo\BreadcrumbSchemaBuilder;
use App\Seo\SchemaJsonLdMerger;
use App\Security\IpBlockMatcher;
use App\Security\IpBlockPatternValidator;
use App\Editing\EditLockService;
use App\Editing\EditSubjectType;
use App\Dev\PluginDependencyHealthCheck;
use App\Filter\FilterHook;
use App\Filter\FilterRegistry;
use App\Filter\Filters;
use App\Health\SiteHealthStatus;
use App\Jobs\JobHandlerContext;
use App\Jobs\JobHandlerRegistry;
use App\Jobs\JobStatus;
use App\Jobs\JobType;
use App\Richtext\OEmbedUrlParser;
use App\Richtext\RichtextOEmbedExpander;
use App\Page\PageContentSanitizer;
use App\Section\SectionPatternHost;
use App\Trash\TrashItemKind;
use App\Dev\TwigLayoutContractLinter;
use App\Maintenance\MaintenanceService;
use App\Media\MediaFolderFilter;
use App\Form\FormConditionalLogic;
use App\Form\FormFieldType;
use App\Form\FormQuizScorer;
use App\Form\FormSlugger;
use App\Form\FormValidator;
use App\Manifest\ManifestMeta;
use App\Mobile\MobileBootstrapService;
use App\Mobile\MobileSettings;
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

$seoAnalyze = (new SeoContentAnalyzer())->analyze([
    'title' => 'WordPress SEO Guide',
    'slug' => 'wordpress-seo-guide',
    'seo_title' => 'WordPress SEO Guide for Beginners',
    'seo_description' => 'Learn WordPress SEO with our beginner guide covering keyphrase research, titles, and meta descriptions for better rankings.',
    'focus_keyphrase' => 'wordpress seo',
    'content' => '<p>WordPress SEO starts with a focus keyphrase in your introduction.</p><p>WordPress SEO tools help you optimize titles and snippets.</p><h2>Next steps</h2><p>Keep improving wordpress seo over time with internal links like <a href="/blog/seo-tips">SEO tips</a>.</p>',
]);
if (($seoAnalyze['seo_score'] ?? 0) < 50) {
    $fail('SeoContentAnalyzer should score well-optimized sample content.');
}
if (($seoAnalyze['readability_score'] ?? 0) <= 0) {
    $fail('SeoContentAnalyzer should produce a readability score.');
}

$bc = BreadcrumbSchemaBuilder::build([
    ['name' => 'Home', 'url' => '/'],
    ['name' => 'Guide'],
], 'https://example.com');
if ($bc === null || !str_contains($bc, 'BreadcrumbList')) {
    $fail('BreadcrumbSchemaBuilder should emit BreadcrumbList JSON-LD.');
}
$merged = SchemaJsonLdMerger::merge('{"@type":"WebPage","name":"Test"}', $bc);
if ($merged === null || !str_contains($merged, '@graph')) {
    $fail('SchemaJsonLdMerger should combine documents into @graph.');
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
if (!ReservedContentSlugs::isReserved('commerce')) {
    $fail('ReservedContentSlugs should treat core segment "commerce" as reserved.');
}
if (!ReservedContentSlugs::isReserved('shop')) {
    $fail('ReservedContentSlugs should treat core segment "shop" as reserved.');
}

use App\Commerce\Product\PurchasableProduct;
$pp = new PurchasableProduct(1, 2, 'product', 'demo', 'Demo', 1999, 'gbp', null, 'SKU-1', null);
if ($pp->formattedPrice() !== '£19.99') {
    $fail('PurchasableProduct::formattedPrice should format GBP.');
}
if (!$pp->isInStock(1)) {
    $fail('PurchasableProduct with null stock should be in stock.');
}
$limited = new PurchasableProduct(1, 2, 'product', 'demo', 'Demo', 1999, 'gbp', null, 'SKU-1', 2);
if (!$limited->isInStock(2) || $limited->isInStock(3)) {
    $fail('PurchasableProduct::isInStock should respect stock_qty.');
}

use App\Commerce\Coupon\CommerceCoupon;
$coupon = new CommerceCoupon(1, 'SAVE10', 'percent', 10, 0, null, 0, true, null, '', '');
if ($coupon->discountForSubtotal(2000) !== 200) {
    $fail('CommerceCoupon percent discount should be 10% of subtotal.');
}
$fixed = new CommerceCoupon(2, 'FIVE', 'fixed', 500, 1000, null, 0, true, null, '', '');
if ($fixed->discountForSubtotal(999) !== 0 || $fixed->discountForSubtotal(1500) !== 500) {
    $fail('CommerceCoupon fixed discount should respect min subtotal and cap at subtotal.');
}

use App\Commerce\Order\ShippingAddressFormatter;
$addr = ShippingAddressFormatter::lines([
    'name' => 'Jane Doe',
    'address' => ['line1' => '1 High St', 'city' => 'London', 'postal_code' => 'SW1A 1AA', 'country' => 'GB'],
]);
if (count($addr) < 3 || !str_contains(implode(' ', $addr), 'Jane Doe')) {
    $fail('ShippingAddressFormatter should format address lines.');
}

use App\Commerce\Order\OrderListFilter;
$olf = OrderListFilter::fromQueryParams(['status' => 'paid', 'email' => 'a@b.co']);
if (!$olf->isActive() || $olf->status !== 'paid') {
    $fail('OrderListFilter should parse query params.');
}

use App\Commerce\Shipping\ShippingZone;
$zone = new ShippingZone(1, 'UK', 'UK Standard', 499, 5000, ['GB'], 0, true);
if ($zone->isFallback() || $zone->priceCents !== 499) {
    $fail('ShippingZone should expose zone metadata.');
}
$fallback = new ShippingZone(2, 'Rest', 'International', 999, 0, [], 1, true);
if (!$fallback->isFallback()) {
    $fail('ShippingZone with empty countries should be fallback.');
}

use App\Commerce\Tax\CommerceTaxRate;
$taxRate = new CommerceTaxRate(1, 'GB', 'VAT', 2000, true, 0);
if ($taxRate->rateBps !== 2000) {
    $fail('CommerceTaxRate should store basis points.');
}

use App\Commerce\Shipping\ShippingZoneRepository;
if (ShippingZoneRepository::normalizeCountries('gb, ie, US') !== ['GB', 'IE', 'US']) {
    $fail('ShippingZoneRepository should normalize country lists.');
}

use App\Commerce\Digital\DigitalDeliverySpec;
$fileSpec = new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_FILE, ['media_id' => 42], 'PDF');
if (!$fileSpec->hasDelivery() || $fileSpec->label !== 'PDF') {
    $fail('DigitalDeliverySpec file type should require media_id.');
}
$urlSpec = new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_URL, ['url' => 'https://example.com/file.zip']);
if (!$urlSpec->hasDelivery()) {
    $fail('DigitalDeliverySpec url type should accept valid url payload.');
}
$emptySpec = new DigitalDeliverySpec(DigitalDeliverySpec::TYPE_FILE, []);
if ($emptySpec->hasDelivery()) {
    $fail('DigitalDeliverySpec should reject empty file payload.');
}

use App\Commerce\Digital\DigitalGrant;
$grant = DigitalGrant::fromRow([
    'id' => 1,
    'order_id' => 10,
    'order_item_id' => 20,
    'content_entry_id' => 5,
    'access_token' => str_repeat('a', 64),
    'delivery_type' => 'file',
    'delivery_payload_json' => '{"media_id":1}',
    'label' => 'E-book',
    'revoked_at' => null,
    'download_count' => 0,
    'last_download_at' => null,
    'created_at' => '2026-01-01',
]);
if (!$grant->isActive() || $grant->payload['media_id'] !== 1) {
    $fail('DigitalGrant should parse row and report active state.');
}
$revoked = DigitalGrant::fromRow(array_merge([
    'id' => 2,
    'order_id' => 10,
    'order_item_id' => 21,
    'content_entry_id' => 6,
    'access_token' => str_repeat('b', 64),
    'delivery_type' => 'url',
    'delivery_payload_json' => '{"url":"https://x.test"}',
    'label' => 'Link',
    'revoked_at' => '2026-01-02 12:00:00',
    'download_count' => 3,
    'last_download_at' => '2026-01-02 11:00:00',
    'created_at' => '2026-01-01',
]));
if ($revoked->isActive()) {
    $fail('DigitalGrant with revoked_at should not be active.');
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

$condField = ['field_key' => 'x', 'conditional' => [
    'enabled' => true,
    'action' => 'show',
    'operator' => 'all',
    'rules' => [['field_key' => 'country', 'operator' => 'is', 'value' => 'US']],
]];
if (!FormConditionalLogic::isVisible($condField, ['country' => 'US'])) {
    $fail('FormConditionalLogic should show field when rule matches.');
}
if (FormConditionalLogic::isVisible($condField, ['country' => 'UK'])) {
    $fail('FormConditionalLogic should hide field when rule fails.');
}

$quizForm = ['form_type' => 'quiz', 'settings' => ['quiz_pass_percent' => 70]];
$quizFields = [
    ['field_key' => 'q1', 'field_type' => FormFieldType::RADIO, 'settings' => ['quiz_points' => 10, 'quiz_correct' => 'B']],
];
$quizValues = [['field_key' => 'q1', 'value_text' => 'B']];
$quizResult = FormQuizScorer::score($quizForm, $quizFields, $quizValues);
if ($quizResult['score'] !== 10 || !$quizResult['passed']) {
    $fail('FormQuizScorer should score correct quiz answers.');
}

if (!EditSubjectType::isValid('page') || EditSubjectType::isValid('invalid')) {
    $fail('EditSubjectType validation failed.');
}

$lockRef = new ReflectionClass(EditLockService::class);
/** @var EditLockService $lockSvc */
$lockSvc = $lockRef->newInstanceWithoutConstructor();
if (!$lockSvc->isActive(['heartbeat_at' => date('Y-m-d H:i:s')])) {
    $fail('EditLockService::isActive should accept fresh heartbeat.');
}
if ($lockSvc->isActive(['heartbeat_at' => date('Y-m-d H:i:s', time() - EditLockService::TTL_SECONDS - 10)])) {
    $fail('EditLockService::isActive should reject stale heartbeat.');
}

if (!TrashItemKind::isValid('page') || !TrashItemKind::isValid('content_entry') || TrashItemKind::isValid('invalid')) {
    $fail('TrashItemKind validation failed.');
}

if (SiteHealthStatus::worst([SiteHealthStatus::GOOD, SiteHealthStatus::RECOMMENDED]) !== SiteHealthStatus::RECOMMENDED) {
    $fail('SiteHealthStatus::worst should prefer recommended over good.');
}
if (SiteHealthStatus::worst([SiteHealthStatus::GOOD, SiteHealthStatus::CRITICAL]) !== SiteHealthStatus::CRITICAL) {
    $fail('SiteHealthStatus::worst should prefer critical.');
}

if (!FilterHook::isValid('seo.meta') || FilterHook::isValid('invalid')) {
    $fail('FilterHook validation failed.');
}

$filters = new FilterRegistry();
Filters::set($filters);
Filters::add(FilterHook::HTML_SANITIZE, static fn (mixed $v): string => (string) $v . '-a', 20);
Filters::add(FilterHook::HTML_SANITIZE, static fn (mixed $v): string => (string) $v . '-b', 10);
if (Filters::apply(FilterHook::HTML_SANITIZE, 'x', []) !== 'x-b-a') {
    $fail('FilterRegistry should apply callbacks in ascending priority order.');
}

if (!JobStatus::isValid('pending') || JobStatus::isValid('bogus')) {
    $fail('JobStatus validation failed.');
}
if (!JobStatus::isTerminal(JobStatus::COMPLETED) || JobStatus::isTerminal(JobStatus::PENDING)) {
    $fail('JobStatus::isTerminal failed.');
}
if (!JobType::isBuiltin(JobType::SITEMAP_WARM) || JobType::isBuiltin('custom.job')) {
    $fail('JobType::isBuiltin failed.');
}

$jobHandlers = new JobHandlerRegistry();
$jobHandlers->register('test.echo', static function ($job, JobHandlerContext $ctx): array {
    return ['ok' => true, 'message' => (string) ($job->payload['msg'] ?? '')];
});
$testJob = \App\Jobs\Job::fromRow([
    'id' => 1,
    'queue' => 'default',
    'type' => 'test.echo',
    'payload' => json_encode(['msg' => 'hi'], JSON_THROW_ON_ERROR),
    'status' => JobStatus::PENDING,
    'available_at' => gmdate('Y-m-d H:i:s'),
    'attempts' => 0,
    'max_attempts' => 3,
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$handlerCtx = new JobHandlerContext(
    new PDO('sqlite::memory:'),
    $root,
    new \App\Jobs\JobQueue(new \App\Jobs\JobRepository(new PDO('sqlite::memory:'))),
);
$result = $jobHandlers->handle($testJob, $handlerCtx);
if (($result['ok'] ?? false) !== true || ($result['message'] ?? '') !== 'hi') {
    $fail('JobHandlerRegistry should dispatch registered handlers.');
}

$redirectPdo = new PDO('sqlite::memory:');
$redirectPdo->exec(
    'CREATE TABLE cms_redirects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_path TEXT NOT NULL,
        to_url TEXT NOT NULL,
        status_code INTEGER NOT NULL DEFAULT 301,
        hit_count INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NULL
    )'
);
$redirectRepo = new RedirectRepository($redirectPdo);
$redirectRepo->upsertPath('/p/middle', 'https://site.test/p/middle', 301);
$redirectRepo->insert('/p/legacy', 'https://site.test/p/middle', 301);
$updated = $redirectRepo->retargetDestinations('/p/middle', 'https://site.test/p/new-post');
if ($updated < 1) {
    $fail('RedirectRepository::retargetDestinations should update redirects pointing at the old path.');
}
$legacy = $redirectRepo->findByPath('/p/legacy');
if ($legacy === null || ($legacy['to_url'] ?? '') !== 'https://site.test/p/new-post') {
    $fail('Redirect chain should point legacy path at the newest slug URL.');
}

$result = new SlugRedirectResult(true, '/p/foo', 'https://site.test/p/bar', 2);
if (!str_contains($result->flashSuffix(), '/p/foo') || !str_contains($result->flashSuffix(), '2 older redirect')) {
    $fail('SlugRedirectResult flashSuffix should describe redirect and chain updates.');
}

if (!SectionPatternHost::isValid('both') || SectionPatternHost::isValid('invalid')) {
    $fail('SectionPatternHost validation failed.');
}
if (!SectionPatternHost::supports(SectionPatternHost::BOTH, SectionPatternHost::PAGE)) {
    $fail('SectionPatternHost should allow both on pages.');
}
if (SectionPatternHost::supports(SectionPatternHost::PAGE, SectionPatternHost::CONTENT_ENTRY)) {
    $fail('SectionPatternHost page-only should not match content entry host.');
}

$yt = OEmbedUrlParser::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
if ($yt === null || $yt->provider !== 'youtube' || $yt->id !== 'dQw4w9WgXcQ') {
    $fail('OEmbedUrlParser should parse YouTube watch URLs.');
}
$tw = OEmbedUrlParser::parse('https://x.com/jack/status/1234567890123456789');
if ($tw === null || $tw->provider !== 'twitter' || $tw->id !== '1234567890123456789') {
    $fail('OEmbedUrlParser should parse X status URLs.');
}
$expanded = RichtextOEmbedExpander::expand('<p>https://youtu.be/dQw4w9WgXcQ</p>');
if (!str_contains($expanded, 'cms-oembed--youtube') || !str_contains($expanded, 'youtube-nocookie.com/embed/dQw4w9WgXcQ')) {
    $fail('RichtextOEmbedExpander should convert standalone YouTube URLs.');
}
$san = PageContentSanitizer::fromEnv()->sanitize($expanded);
if (!str_contains($san, 'cms-oembed--youtube') || !str_contains($san, 'youtube-nocookie.com/embed/dQw4w9WgXcQ')) {
    $fail('PageContentSanitizer should keep YouTube oEmbed markup.');
}
$twExpanded = RichtextOEmbedExpander::expand('<p><a href="https://twitter.com/n/status/999">link</a></p>');
if ($twExpanded === null || !str_contains($twExpanded, 'cms-oembed--twitter')) {
    $fail('RichtextOEmbedExpander should convert linked tweet URLs.');
}

if (!PluginSemverConstraint::satisfies('1.2.0', '^1.0.0')) {
    $fail('PluginSemverConstraint should accept compatible caret range.');
}
if (PluginSemverConstraint::satisfies('2.0.0', '^1.0.0')) {
    $fail('PluginSemverConstraint should reject major mismatch for caret.');
}
if (!PluginCapability::isValid('database.write') || PluginCapability::isValid('invalid.cap')) {
    $fail('PluginCapability validation failed.');
}
$manifest = PluginManifest::fromArray([
    'name' => 'Test',
    'slug' => 'test-plugin',
    'version' => '1.0.0',
    'requires_plugins' => ['base-plugin' => '^1.0'],
    'capabilities' => ['admin.nav'],
    'hooks' => ['filters' => ['seo.meta'], 'events' => ['ContentEntrySavedEvent']],
    'database' => ['tables' => ['cms_test_items']],
], 'test-plugin');
if ($manifest->requiresPlugins['base-plugin'] !== '^1.0' || $manifest->hookFilters !== ['seo.meta']) {
    $fail('PluginManifest should parse contract fields.');
}

$legacyManifest = PluginManifest::fromArray([
    'name' => 'Legacy',
    'slug' => 'legacy-plugin',
    'version' => '1.0.0',
], 'legacy-plugin');
$legacyGuard = new PluginCapabilityGuard($legacyManifest);
$legacyGuard->assertCapability(PluginCapability::ADMIN_NAV);
$contractManifest = PluginManifest::fromArray([
    'name' => 'Strict',
    'slug' => 'strict-plugin',
    'version' => '1.0.0',
    'capabilities' => ['admin.nav'],
    'hooks' => ['filters' => ['admin.dashboard']],
], 'strict-plugin');
$strictGuard = new PluginCapabilityGuard($contractManifest);
try {
    $strictGuard->assertFilterRegistration(FilterHook::SEO_META);
    $fail('Strict plugin should reject undeclared filter hooks.');
} catch (PluginCapabilityException) {
    // expected
}
$strictGuard->assertFilterRegistration(FilterHook::ADMIN_DASHBOARD);
if (FilterHook::requiredCapability(FilterHook::CONTENT_SAVE) !== PluginCapability::DATABASE_WRITE) {
    $fail('FilterHook should map content.save to database.write.');
}

$loadManifest = PluginManifest::fromArray([
    'name' => 'Admin Only',
    'slug' => 'admin-only',
    'version' => '1.0.0',
    'load' => ['public' => false, 'admin' => true],
], 'admin-only');
if ($loadManifest->loadPublic || !$loadManifest->loadAdmin) {
    $fail('PluginManifest should parse load flags.');
}
if (PluginLoadScope::Public->allows($loadManifest) || !PluginLoadScope::Admin->allows($loadManifest)) {
    $fail('PluginLoadScope should respect manifest load flags.');
}

$tmpRoot = sys_get_temp_dir() . '/struxa-plugin-perf-' . getmypid();
@mkdir($tmpRoot . '/storage', 0755, true);
PluginPerformanceRegistry::configure($tmpRoot);
$perf = PluginPerformanceRegistry::instance();
$perf->recordBoot('demo-plugin', 12.5, 2, 1);
$perf->recordHookCall('filter:seo.meta', 30.0, 'demo-plugin');
$snap = $perf->snapshotForSlug('demo-plugin');
if ($snap === null || ($snap['last_boot_ms'] ?? 0) != 12.5) {
    $fail('PluginPerformanceRegistry should persist boot snapshots.');
}
if (($snap['slow_hooks'][0]['hook'] ?? '') !== 'filter:seo.meta') {
    $fail('PluginPerformanceRegistry should record slow hooks.');
}
@unlink($tmpRoot . '/storage/plugin-performance.json');
@rmdir($tmpRoot . '/storage');
@rmdir($tmpRoot);

if (ContentEntryBulkService::normalizeIds(['1', 2, '0', 'x', 2]) !== [1, 2]) {
    $fail('ContentEntryBulkService should normalize entry ids.');
}

if (PrivacyEmailHasher::hash('  User@Example.COM ') !== hash('sha256', 'user@example.com')) {
    $fail('PrivacyEmailHasher should normalize email before hashing.');
}
if (PrivacyEmailHasher::isValidEmail('not-an-email') || !PrivacyEmailHasher::isValidEmail('a@b.co')) {
    $fail('PrivacyEmailHasher should validate emails.');
}
if (RevisionRetentionSettings::normalize('999') !== 500 || RevisionRetentionSettings::normalize('-1') !== 0) {
    $fail('RevisionRetentionSettings should clamp limits.');
}
if (ContentSearchService::sanitizeQuery('a') !== '' || ContentSearchService::sanitizeQuery('  hello  ') !== 'hello') {
    $fail('ContentSearchService sanitizeQuery should enforce min length and trim.');
}

if (PageDuplicationService::copyTitle('About') !== 'About (Copy)') {
    $fail('PageDuplicationService should append (Copy) to titles.');
}

if (!FilterHook::isValid('mobile.bootstrap')) {
    $fail('FilterHook should allow mobile.bootstrap.');
}
if (FilterHook::requiredCapability(FilterHook::MOBILE_BOOTSTRAP) !== PluginCapability::FRONTEND_RENDER) {
    $fail('FilterHook::MOBILE_BOOTSTRAP should require frontend.render.');
}

$tabs = MobileSettings::defaultTabs(true, true, 2);
if (count($tabs) !== 5 || $tabs[4]['type'] !== 'account') {
    $fail('MobileSettings::defaultTabs should include home, content, search, shop, and account.');
}
$parsed = MobileSettings::parseTabsJson('[{"id":"home","label":"Home","type":"home"},{"id":"bad id","label":"X","type":"x"}]');
if (count($parsed) !== 1 || $parsed[0]['id'] !== 'home') {
    $fail('MobileSettings::parseTabsJson should accept valid tabs and reject invalid ids.');
}
if (MobileSettings::parseTabsJson('not-json') !== []) {
    $fail('MobileSettings::parseTabsJson should return [] for invalid JSON.');
}

if (MobileBootstrapService::absoluteUrl('https://example.com', '/logo.svg') !== 'https://example.com/logo.svg') {
    $fail('MobileBootstrapService::absoluteUrl should join site URL and path.');
}
if (MobileBootstrapService::absoluteUrl('https://example.com', 'https://cdn.test/x.png') !== 'https://cdn.test/x.png') {
    $fail('MobileBootstrapService::absoluteUrl should pass through absolute URLs.');
}

if (\App\Mobile\MobileContentService::PER_PAGE_DEFAULT !== 20 || \App\Mobile\MobileContentService::PER_PAGE_MAX !== 30) {
    $fail('MobileContentService pagination constants should match mobile API defaults.');
}

if (\App\Mobile\MobileCommerceService::formatMoney(1999, 'gbp') !== '£19.99') {
    $fail('MobileCommerceService::formatMoney should format GBP.');
}
if (\App\Mobile\MobileCommerceService::PER_PAGE_DEFAULT !== 20 || \App\Mobile\MobileCommerceService::PER_PAGE_MAX !== 30) {
    $fail('MobileCommerceService pagination constants should match mobile API defaults.');
}

$deeplink = \App\Mobile\MobileSiteLink::deepLinkAddSite('https://demo.struxa.test');
if (!str_starts_with($deeplink, 'struxa://add-site?url=')) {
    $fail('MobileSiteLink::deepLinkAddSite should build struxa add-site URLs.');
}
if (\App\Mobile\MobileSiteLink::webAddSitePath('https://demo.struxa.test') !== 'https://demo.struxa.test/mobile/add') {
    $fail('MobileSiteLink::webAddSitePath should append /mobile/add.');
}
if (!str_contains(\App\Mobile\MobileQrCode::svg('test'), '<svg')) {
    $fail('MobileQrCode::svg should return SVG markup.');
}

$pluginTab = MobileSettings::parseTabsJson('[{"id":"x","label":"X","type":"plugin","plugin_slug":"demo","url":"https://example.com/p"}]');
if (($pluginTab[0]['plugin_slug'] ?? '') !== 'demo' || ($pluginTab[0]['url'] ?? '') !== 'https://example.com/p') {
    $fail('MobileSettings::parseTabsJson should preserve plugin tab optional fields.');
}
$insecureTab = MobileSettings::parseTabsJson('[{"id":"web","label":"Web","type":"url","url":"http://example.com"}]');
if (isset($insecureTab[0]['url'])) {
    $fail('MobileSettings::parseTabsJson should reject non-HTTPS tab URLs.');
}

$slugs = MobileSettings::parseSlugListJson('["post","page"]');
if ($slugs !== ['post', 'page']) {
    $fail('MobileSettings::parseSlugListJson should parse content type slugs.');
}
$encoded = MobileSettings::encodeSlugList(['post', 'post', 'bad slug!']);
if ($encoded !== '["post"]') {
    $fail('MobileSettings::encodeSlugList should dedupe and sanitize slugs.');
}
$noBrowse = MobileSettings::defaultTabs(true, true, 3, ['browse' => false, 'search' => true, 'shop' => true, 'account' => true]);
if (count($noBrowse) !== 4 || ($noBrowse[1]['type'] ?? '') === 'content') {
    $fail('MobileSettings::defaultTabs should omit browse tab when feature is off.');
}

$prevSiteKey = $_ENV['PHPAUTH_SITE_KEY'] ?? null;
$_ENV['PHPAUTH_SITE_KEY'] = 'unit-test-mobile-jwt-key';
try {
    $issued = \App\Mobile\MobileJwt::issueAccessToken(42, 'user@example.com');
    $payload = \App\Mobile\MobileJwt::decode($issued['token']);
    if ((int) ($payload['sub'] ?? 0) !== 42 || ($payload['email'] ?? '') !== 'user@example.com') {
        $fail('MobileJwt round-trip should preserve subject and email.');
    }
} finally {
    if ($prevSiteKey === null) {
        unset($_ENV['PHPAUTH_SITE_KEY']);
    } else {
        $_ENV['PHPAUTH_SITE_KEY'] = $prevSiteKey;
    }
}

echo "All tests passed.\n";
exit(0);
