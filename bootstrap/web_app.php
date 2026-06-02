<?php

declare(strict_types=1);

use App\Auth\AppAuth;
use App\Auth\GoogleOAuthClient;
use App\Auth\GoogleSsoConfig;
use App\Auth\LoginFilterPipeline;
use App\Auth\PhpAuthUsernameRepository;
use App\Auth\UsernameValidation;
use App\Access\PermissionService;
use App\Asset\CoreAssetResolver;
use App\Cache\CacheConfig;
use App\Cache\CacheManager;
use App\Cache\PublicResponseCacheMiddleware;
use App\Cache\StorefrontCacheInvalidator;
use App\CmsUserRepository;
use App\Event\ContentEntryDeletedEvent;
use App\Event\ContentEntrySavedEvent;
use App\Event\EventDispatcher;
use App\Event\Events;
use App\Filter\FilterRegistry;
use App\Filter\Filters;
use App\Jobs\Jobs;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Event\UserLoggedInEvent;
use App\Flash;
use App\Http\Middleware\CsrfProtectionMiddleware;
use App\Http\Middleware\NotFoundLogMiddleware;
use App\Http\PublicNotFoundHandler;
use App\Http\Middleware\PublishScheduleMiddleware;
use App\Http\Middleware\RedirectMiddleware;
use App\Http\Middleware\IpBlockMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\ThrottlingMiddleware;
use App\Http\Middleware\TwigCmsGlobals;
use App\Twig\CoreAssetTwigExtension;
use App\Http\PostLoginRedirect;
use App\Http\SafeRedirectPath;
use App\Http\MediaDerivativeHandler;
use App\Http\ThemePublicAssetsHandler;
use App\Media\MediaDerivativeService;
use App\Media\MediaRepository;
use App\Media\MediaUrlHelper;
use App\Seo\MetaTagBuilder;
use App\Seo\SeoService;
use App\Settings\SettingsRepository;
use App\Settings\SiteSettingsService;
use App\Page\PageRepository;
use App\Page\PublicCmsPageRenderer;
use App\Section\CoreSectionDefinitionProvider;
use App\Section\SectionDefinitionRegistry;
use App\PhpAuthSettings;
use App\Plugin\PluginLoadScope;
use App\Plugin\PluginManager;
use App\Plugin\PluginPerformanceRegistry;
use App\Plugin\PluginRepository;
use App\Plugin\PluginScanner;
use App\Plugin\PluginValidator;
use App\Security\IpBlockHitThrottledLogger;
use App\Security\IpBlockRepository;
use App\Security\TwoFactorLoginSession;
use App\Security\TotpService;
use App\Settings;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexCardBuilder;
use App\Theme\ThemeHttpConfig;
use App\Theme\ThemeManager;
use App\Theme\ThemeViewResolver;
use App\Twig\CmsTwigExtension;
use App\Twig\FormTwigExtension;
use App\Twig\ContentEntryRefsTwigExtension;
use App\Twig\ContentListTwigExtension;
use App\Twig\GithubShowcaseTwigExtension;
use App\Twig\CommentTwigExtension;
use App\Twig\ContentTypeCardsTwigExtension;
use App\Twig\TaxonomyTwigExtension;
use App\Twig\ThemeTwigExtension;
use PHPAuth\Auth;
use PHPAuth\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$envFileReadable = is_readable($root . '/.env');

if ($envFileReadable) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

PluginPerformanceRegistry::configure($root);

Flash::start();

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['DB_NAME'] ?? 'studio';
$dbUser = $_ENV['DB_USER'] ?? 'studio';
$dbPass = $_ENV['DB_PASS'] ?? 'studio';

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database unavailable. Check DB_* in .env or run Docker MySQL, then composer migrate.\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $envHint = $envFileReadable
        ? 'Check <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code> in your <code>.env</code> file.'
        : 'Copy <code>.env.example</code> to <code>.env</code> and set database credentials.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database unavailable</title>'
        . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1.25rem;line-height:1.55;color:#1e293b;background:#f8fafc}'
        . 'code{background:#e2e8f0;padding:0.15em 0.4em;border-radius:4px;font-size:0.9em}'
        . 'h1{font-size:1.35rem}</style></head><body>'
        . '<h1>Database unavailable</h1>'
        . '<p>The app could not connect to MySQL.</p>'
        . '<p>' . $envHint . '</p>'
        . '<p>Local Docker: from the project root run <code>docker compose up -d</code>, wait until MySQL is healthy, then run <code>composer migrate</code> (or <code>php bin/cms.php migrate</code>).</p>'
        . '</body></html>';
    exit(1);
}

Settings::boot($pdo);

PhpAuthSettings::assertInstalledSiteKeyOrExit($root);

$eventDispatcher = new EventDispatcher();
Events::set($eventDispatcher);

$filterRegistry = new FilterRegistry();
Filters::set($filterRegistry);

Jobs::boot($pdo, $root);

$authConfig = new Config($pdo, PhpAuthSettings::fromEnv(), PhpAuthSettings::configType());
$auth = new AppAuth($pdo, $authConfig);
$googleSso = GoogleSsoConfig::fromSettings();

$themeManager = new ThemeManager($root);
$cacheStorage = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
$cacheManager = new CacheManager($cacheStorage);
$storefrontCacheInvalidator = new StorefrontCacheInvalidator($cacheManager, $themeManager);
$flushStorefrontCaches = static function () use ($storefrontCacheInvalidator): void {
    $storefrontCacheInvalidator->flushAll();
};
$eventDispatcher->listen(ContentEntrySavedEvent::class, $flushStorefrontCaches);
$eventDispatcher->listen(ContentEntryDeletedEvent::class, $flushStorefrontCaches);
$eventDispatcher->listen(StorefrontCachesInvalidateEvent::class, $flushStorefrontCaches);
$twigLoaderPaths = ThemeViewResolver::twigLoaderPaths($themeManager, $root . '/templates');
$twig = Twig::create($twigLoaderPaths, ['cache' => false]);
$mediaUrlHelper = new MediaUrlHelper($pdo);
$twig->getEnvironment()->addExtension(new CmsTwigExtension($mediaUrlHelper));
$twig->getEnvironment()->addExtension(new FormTwigExtension($pdo));
$twig->getEnvironment()->addExtension(new ContentTypeCardsTwigExtension(
    new ContentTypeRepository($pdo),
    new ContentEntryRepository($pdo),
    new PublicContentIndexCardBuilder(
        new ContentFieldRepository($pdo),
        new ContentEntryValueRepository($pdo),
        $mediaUrlHelper,
    ),
    $pdo,
));
$twig->getEnvironment()->addExtension(new CommentTwigExtension($pdo, new ContentTypeRepository($pdo)));
$twig->getEnvironment()->addExtension(new GithubShowcaseTwigExtension($root, $cacheManager->internal()));
$twig->getEnvironment()->addExtension(new CoreAssetTwigExtension(
    new CoreAssetResolver($root . DIRECTORY_SEPARATOR . 'public', CacheConfig::preferMinifiedAssets())
));
$twig->getEnvironment()->addExtension(new ThemeTwigExtension($themeManager, CacheConfig::preferMinifiedAssets()));
$twig->getEnvironment()->addExtension(new TaxonomyTwigExtension(
    new ContentEntryTaxonomyRepository($pdo),
    new TaxonomyRepository($pdo),
    new TaxonomyTermRepository($pdo)
));
$twig->getEnvironment()->addExtension(new ContentListTwigExtension($pdo));
$twig->getEnvironment()->addExtension(new ContentEntryRefsTwigExtension($pdo));
$app = AppFactory::create();
$app->add(new CsrfProtectionMiddleware());
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::create($app, $twig));
$app->add(new TwigCmsGlobals($twig, $pdo, $themeManager, $cacheManager, $app->getRouteCollector()->getRouteParser()));
$phpAuthCookieName = isset($auth->config->cookie_name) ? (string) $auth->config->cookie_name : 'phpauth_session_cookie';
$app->add(new PublicResponseCacheMiddleware($auth, $cacheManager->publicResponses(), $themeManager, $phpAuthCookieName));
$app->add(new NotFoundLogMiddleware($pdo));
$app->add(new RedirectMiddleware($pdo));
$app->add(new ThrottlingMiddleware($root));
$app->add(new SecurityHeadersMiddleware());
$app->add(new IpBlockMiddleware(
    new IpBlockRepository($pdo),
    $cacheManager->internal(),
    IpBlockHitThrottledLogger::createDefault($pdo, $root),
));
$app->add(new PublishScheduleMiddleware($pdo, $cacheManager->internal()));

$displayErrorDetails = in_array(
    strtolower(trim((string) ($_ENV['APP_DEBUG'] ?? ''))),
    ['1', 'true', 'yes', 'on'],
    true
);
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, new PublicNotFoundHandler($twig));

$app->get(ThemeHttpConfig::assetRoutePattern(), new ThemePublicAssetsHandler($themeManager));

$mediaRepository = new MediaRepository($pdo);
$mediaDerivativeService = new MediaDerivativeService($root, $mediaRepository, $mediaUrlHelper);
$app->get(
    '/media-rs/{width:[0-9]+}/{id:[0-9]+}',
    new MediaDerivativeHandler($mediaDerivativeService, $mediaUrlHelper, $mediaRepository)
);

$viewData = static function (array $extra = []) use ($auth, $pdo, $googleSso): array {
    $userEmail = '';
    $userUsername = '';
    $userDisplayName = '';
    $userCanAccessAdmin = false;
    if ($auth->isLogged()) {
        $userEmail = (string) ($auth->getCurrentUser()['email'] ?? '');
        $uid = (int) $auth->getCurrentUID();
        if ($uid > 0) {
            try {
                $userUsername = PhpAuthUsernameRepository::findByUserId($pdo, $uid) ?? '';
            } catch (\PDOException) {
                $userUsername = '';
            }
            if (CmsUserRepository::tableExists($pdo)) {
                $cmsUser = CmsUserRepository::findByPhpAuthId($pdo, $uid);
                if ($cmsUser !== null && (int) ($cmsUser['is_active'] ?? 0) === 1) {
                    $userCanAccessAdmin = (new PermissionService())->canAccessAdmin($pdo, (int) $cmsUser['id']);
                    if ($userUsername === '') {
                        $displayName = trim((string) ($cmsUser['display_name'] ?? ''));
                        if ($displayName !== '') {
                            $userUsername = $displayName;
                        }
                    }
                }
            }
        }
        if ($userUsername !== '') {
            $userDisplayName = $userUsername;
        } elseif ($userEmail !== '') {
            $at = strpos($userEmail, '@');
            $userDisplayName = $at !== false ? substr($userEmail, 0, $at) : $userEmail;
        } else {
            $userDisplayName = 'Account';
        }
    }

    return array_merge([
        'logged_in' => $auth->isLogged(),
        'phpauth_user_id' => $auth->isLogged() ? (int) $auth->getCurrentUID() : 0,
        'user_email' => $userEmail,
        'user_username' => $userUsername,
        'user_display_name' => $userDisplayName,
        'user_can_access_admin' => $userCanAccessAdmin,
        'flash_error' => Flash::pull('error'),
        'flash_success' => Flash::pull('success'),
        'site_url' => \App\Settings\SiteUrlResolver::resolve(),
        'google_sso_enabled' => $googleSso !== null,
    ], $extra);
};

$app->get('/', function (Request $request, Response $response) use ($twig, $viewData, $pdo, $themeManager): Response {
    $settings = (new SiteSettingsService(new SettingsRepository($pdo)))->forTwig();
    $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');

    $homePageIdRaw = Settings::publicHomepagePageIdRaw();
    $publishedHomePage = null;
    if ($homePageIdRaw !== '' && ctype_digit($homePageIdRaw)) {
        $cand = (new PageRepository($pdo))->findById((int) $homePageIdRaw);
        if ($cand !== null && $cand->isPubliclyVisible()) {
            $publishedHomePage = $cand;
        }
    }

    $activeTheme = strtolower(trim($themeManager->activeSlug()));
    if ($publishedHomePage !== null && $activeTheme !== 'cashback') {
        return PublicCmsPageRenderer::render(
            $twig,
            $response,
            $viewData,
            $pdo,
            $publishedHomePage,
            '/',
            true,
            $request,
        );
    }

    $seoTwig = MetaTagBuilder::twigVars((new SeoService(new MediaUrlHelper($pdo)))->resolveSiteHome($settings, $siteUrl));

    return $twig->render($response, 'page/home.twig', array_merge($viewData(), $seoTwig));
})->setName('home');

$app->get('/login', function (Request $request, Response $response) use ($twig, $viewData, $auth, $pdo): Response {
    $next = $request->getQueryParams()['next'] ?? null;
    $next = is_string($next) ? $next : null;
    if ($auth->isLogged()) {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo, $next))
            ->withStatus(302);
    }

    return $twig->render($response, 'pages/login.twig', $viewData(['login_next' => $next]));
})->setName('login');

$app->get('/auth/google/start', function (Request $request, Response $response) use ($googleSso, $auth, $pdo): Response {
    if ($googleSso === null) {
        throw new HttpNotFoundException($request);
    }
    if ($auth->isLogged()) {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $q = $request->getQueryParams();
        $alreadyNext = isset($q['next']) && is_string($q['next']) ? $q['next'] : null;

        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo, $alreadyNext))
            ->withStatus(302);
    }

    Flash::start();
    $state = bin2hex(random_bytes(16));
    $query = $request->getQueryParams();
    $next = isset($query['next']) && is_string($query['next']) ? $query['next'] : null;
    $remember = !empty($query['remember']) ? 1 : 0;

    $_SESSION['_struxa_google_oauth'] = [
        'state' => $state,
        'next' => $next,
        'remember' => $remember,
        'exp' => time() + 600,
    ];

    $client = new GoogleOAuthClient($googleSso);

    return $response
        ->withHeader('Location', $client->authorizationUrl($state))
        ->withStatus(302);
})->setName('auth.google.start');

$app->get('/auth/google/callback', function (Request $request, Response $response) use ($googleSso, $auth, $pdo): Response {
    if ($googleSso === null) {
        throw new HttpNotFoundException($request);
    }

    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $loginUrl = $routeParser->urlFor('login');

    $redirectLogin = static function (?string $next, string $message) use ($response, $loginUrl): Response {
        Flash::set('error', $message);
        $url = $loginUrl;
        if ($next !== null && $next !== '') {
            $url .= '?' . http_build_query(['next' => $next]);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    };

    if ($auth->isLogged()) {
        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo))
            ->withStatus(302);
    }

    $q = $request->getQueryParams();
    if (isset($q['error']) && is_string($q['error']) && $q['error'] !== '') {
        return $redirectLogin(null, 'Google sign-in was cancelled or denied.');
    }

    Flash::start();
    $bag = $_SESSION['_struxa_google_oauth'] ?? null;
    unset($_SESSION['_struxa_google_oauth']);

    $code = isset($q['code']) && is_string($q['code']) ? $q['code'] : '';
    $state = isset($q['state']) && is_string($q['state']) ? $q['state'] : '';

    if (!is_array($bag) || $code === '' || $state === '') {
        return $redirectLogin(null, 'Sign-in session expired. Try again from the log in page.');
    }

    $exp = (int) ($bag['exp'] ?? 0);
    if ($exp < time()) {
        return $redirectLogin(null, 'Sign-in session expired. Try again from the log in page.');
    }

    $expected = (string) ($bag['state'] ?? '');
    if ($expected === '' || !hash_equals($expected, $state)) {
        return $redirectLogin(null, 'Invalid sign-in state. Try again from the log in page.');
    }

    $next = isset($bag['next']) && is_string($bag['next']) ? $bag['next'] : null;
    $remember = (int) ($bag['remember'] ?? 0) === 1 ? 1 : 0;

    $client = new GoogleOAuthClient($googleSso);

    try {
        $token = $client->exchangeAuthorizationCode($code);
        $info = $client->fetchUserInfo($token['access_token']);
    } catch (\RuntimeException $e) {
        return $redirectLogin($next, $e->getMessage());
    }

    if (!$info['email_verified']) {
        return $redirectLogin($next, 'Your Google account email is not verified. Verify it with Google, then try again.');
    }

    $email = $info['email'];
    if (!$googleSso->emailDomainAllowed($email)) {
        return $redirectLogin($next, 'This Google account is not allowed to sign in here.');
    }

    $uid = $auth->getUID($email);
    if ($uid < 1) {
        if (!$googleSso->autoProvision) {
            return $redirectLogin(
                $next,
                'No account exists for that email. Use email and password, or ask an administrator to invite you.'
            );
        }

        $random = bin2hex(random_bytes(32));
        $reg = $auth->register($email, $random, $random, [], '', false);
        if (($reg['error'] ?? true) === true) {
            return $redirectLogin($next, (string) ($reg['message'] ?? 'Could not create an account.'));
        }
        $uid = (int) ($reg['uid'] ?? 0);
        if ($uid < 1) {
            return $redirectLogin($next, 'Could not create an account.');
        }
    }

    $totpRow = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $uid);
    $needsTotp = $totpRow !== null
        && (int) ($totpRow['totp_enabled'] ?? 0) === 1
        && trim((string) ($totpRow['totp_secret'] ?? '')) !== '';

    if ($needsTotp) {
        TwoFactorLoginSession::put($uid, $remember);
        $tfUrl = $routeParser->urlFor('login.two_factor');
        if ($next !== null && $next !== '') {
            $tfUrl .= '?' . http_build_query(['next' => $next]);
        }

        return $response->withHeader('Location', $tfUrl)->withStatus(302);
    }

    $complete = $auth->completeSessionAfterTwoFactor($uid, $remember);
    if (($complete['error'] ?? true) === true) {
        return $redirectLogin($next, (string) ($complete['message'] ?? 'Login failed'));
    }

    $block = LoginFilterPipeline::blockMessage($email, $uid, 'google');
    if ($block !== null) {
        return $redirectLogin($next, $block);
    }

    Events::dispatch(new UserLoggedInEvent($email));
    $target = PostLoginRedirect::target($next, $uid, $routeParser, $pdo);

    return $response->withHeader('Location', $target)->withStatus(302);
})->setName('auth.google.callback');

$app->get('/login/two-factor', function (Request $request, Response $response) use ($twig, $viewData, $auth, $pdo): Response {
    if ($auth->isLogged()) {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo))
            ->withStatus(302);
    }
    if (TwoFactorLoginSession::get() === null) {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $response
            ->withHeader('Location', $routeParser->urlFor('login'))
            ->withStatus(302);
    }
    $next = $request->getQueryParams()['next'] ?? null;
    $next = is_string($next) ? $next : null;

    return $twig->render($response, 'pages/login_two_factor.twig', $viewData(['login_next' => $next]));
})->setName('login.two_factor');

$app->post('/login/two-factor', function (Request $request, Response $response) use ($auth, $pdo): Response {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    $loginUrl = $routeParser->urlFor('login');
    $twoFactorUrl = $routeParser->urlFor('login.two_factor');

    $pending = TwoFactorLoginSession::get();
    if ($pending === null) {
        Flash::set('error', 'Your sign-in session expired. Please log in again.');

        return $response->withHeader('Location', $loginUrl)->withStatus(302);
    }

    $body = $request->getParsedBody();
    $next = is_array($body) && isset($body['next']) && is_string($body['next']) ? $body['next'] : null;
    if ($next !== null && $next !== '') {
        $twoFactorUrl .= '?' . http_build_query(['next' => $next]);
        $loginUrl .= '?' . http_build_query(['next' => $next]);
    }

    $code = is_array($body) ? trim((string) ($body['code'] ?? '')) : '';
    $totpState = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $pending['phpauth_uid']);
    $secret = $totpState !== null ? (string) ($totpState['totp_secret'] ?? '') : '';
    $issuer = Settings::get('site_name') ?? 'CMS';
    $totp = new TotpService($issuer);
    if ($secret === '' || !$totp->verify($secret, $code)) {
        Flash::set('error', 'Invalid code. Try again.');

        return $response->withHeader('Location', $twoFactorUrl)->withStatus(302);
    }

    $complete = $auth->completeSessionAfterTwoFactor($pending['phpauth_uid'], $pending['remember']);
    if (($complete['error'] ?? true) === true) {
        TwoFactorLoginSession::clear();
        Flash::set('error', (string) ($complete['message'] ?? 'Could not complete sign-in.'));

        return $response->withHeader('Location', $loginUrl)->withStatus(302);
    }

    TwoFactorLoginSession::clear();
    $email = $totpState !== null ? trim((string) ($totpState['email'] ?? '')) : '';
    if ($email !== '') {
        $block = LoginFilterPipeline::blockMessage($email, (int) $pending['phpauth_uid'], 'two_factor');
        if ($block !== null) {
            Flash::set('error', $block);

            return $response->withHeader('Location', $loginUrl)->withStatus(302);
        }
        Events::dispatch(new UserLoggedInEvent($email));
    }

    $target = PostLoginRedirect::target($next, (int) $pending['phpauth_uid'], $routeParser, $pdo);

    return $response->withHeader('Location', $target)->withStatus(302);
})->setName('login.two_factor.submit');

$app->post('/login', function (Request $request, Response $response) use ($auth, $pdo): Response {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    if ($auth->isLogged()) {
        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo))
            ->withStatus(302);
    }

    $body = $request->getParsedBody();
    $email = is_array($body)
        ? trim((string) ($body['email'] ?? $body['username'] ?? ''))
        : '';
    $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
    $remember = is_array($body) && !empty($body['remember']) ? 1 : 0;

    $next = is_array($body) && isset($body['next']) && is_string($body['next']) ? $body['next'] : null;
    $loginUrl = $routeParser->urlFor('login');
    if ($next !== null && $next !== '') {
        $loginUrl .= '?' . http_build_query(['next' => $next]);
    }

    $result = $auth->verifyPasswordPreSession($email, $password, $remember);
    if ($result['error'] === true) {
        Flash::set('error', (string) ($result['message'] ?? 'Login failed'));

        return $response->withHeader('Location', $loginUrl)->withStatus(302);
    }

    $uid = (int) ($result['uid'] ?? 0);
    $rem = (int) ($result['remember'] ?? 0) === 1 ? 1 : 0;
    $totpRow = CmsUserRepository::findTotpStateByPhpAuthId($pdo, $uid);
    $needsTotp = $totpRow !== null
        && (int) ($totpRow['totp_enabled'] ?? 0) === 1
        && trim((string) ($totpRow['totp_secret'] ?? '')) !== '';

    if ($needsTotp) {
        TwoFactorLoginSession::put($uid, $rem);
        $tfUrl = $routeParser->urlFor('login.two_factor');
        if ($next !== null && $next !== '') {
            $tfUrl .= '?' . http_build_query(['next' => $next]);
        }

        return $response->withHeader('Location', $tfUrl)->withStatus(302);
    }

    $complete = $auth->completeSessionAfterTwoFactor($uid, $rem);
    if (($complete['error'] ?? true) === true) {
        Flash::set('error', (string) ($complete['message'] ?? 'Login failed'));

        return $response->withHeader('Location', $loginUrl)->withStatus(302);
    }

    $block = LoginFilterPipeline::blockMessage($email, $uid, 'password');
    if ($block !== null) {
        Flash::set('error', $block);

        return $response->withHeader('Location', $loginUrl)->withStatus(302);
    }

    Events::dispatch(new UserLoggedInEvent($email));
    $target = PostLoginRedirect::target($next, $uid, $routeParser, $pdo);

    return $response->withHeader('Location', $target)->withStatus(302);
});

$app->get('/register', function (Request $request, Response $response) use ($twig, $viewData, $auth, $pdo): Response {
    if ($auth->isLogged()) {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();

        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo))
            ->withStatus(302);
    }

    return $twig->render($response, 'pages/register.twig', array_merge($viewData(), [
        'registration_collect_username' => Settings::get('registration_collect_username', '0') === '1',
    ]));
})->setName('register');

$app->post('/register', function (Request $request, Response $response) use ($auth, $pdo): Response {
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();
    if ($auth->isLogged()) {
        return $response
            ->withHeader('Location', PostLoginRedirect::forCurrentUser($auth, $routeParser, $pdo))
            ->withStatus(302);
    }

    $body = $request->getParsedBody();
    $email = is_array($body) ? trim((string) ($body['email'] ?? '')) : '';
    $password = is_array($body) ? (string) ($body['password'] ?? '') : '';
    $password2 = is_array($body) ? (string) ($body['password_confirm'] ?? '') : '';
    $usernameRaw = is_array($body) ? (string) ($body['username'] ?? '') : '';
    $collectUsername = Settings::get('registration_collect_username', '0') === '1';
    $usernameCheck = UsernameValidation::validate($usernameRaw, $collectUsername);
    $registerUrl = $routeParser->urlFor('register');

    if (!$usernameCheck['ok']) {
        Flash::set('error', $usernameCheck['message']);

        return $response->withHeader('Location', $registerUrl)->withStatus(302);
    }
    if ($collectUsername && $usernameCheck['value'] !== '' && PhpAuthUsernameRepository::isTaken($pdo, $usernameCheck['value'])) {
        Flash::set('error', 'That username is already taken.');

        return $response->withHeader('Location', $registerUrl)->withStatus(302);
    }

    $result = $auth->register($email, $password, $password2, [], '', false);
    if ($result['error'] === true) {
        Flash::set('error', (string) ($result['message'] ?? 'Registration failed'));

        return $response
            ->withHeader('Location', $registerUrl)
            ->withStatus(302);
    }

    $uid = (int) ($result['uid'] ?? 0);
    if ($collectUsername && $usernameCheck['value'] !== '' && $uid > 0) {
        PhpAuthUsernameRepository::setForUserId($pdo, $uid, $usernameCheck['value']);
    }

    Flash::set('success', (string) ($result['message'] ?? 'Account created.'));

    return $response
        ->withHeader('Location', $routeParser->urlFor('login'))
        ->withStatus(302);
});

$app->post('/logout', function (Request $request, Response $response) use ($auth): Response {
    TwoFactorLoginSession::clear();
    $hash = $auth->getCurrentSessionHash();
    if ($hash !== '') {
        $auth->logout($hash);
    }
    $routeParser = RouteContext::fromRequest($request)->getRouteParser();

    return $response
        ->withHeader('Location', $routeParser->urlFor('home'))
        ->withStatus(302);
})->setName('logout');

$app->get('/logout', function (Request $request, Response $response) use ($twig, $viewData): Response {
    return $twig->render($response, 'pages/logout_confirm.twig', $viewData());
});

(require $root . '/routes/public_seo.php')($app, $pdo, $viewData);

(require $root . '/routes/public_api.php')($app, $twig, $pdo, $viewData);
(require $root . '/routes/mobile_api.php')($app, $pdo, $themeManager, $auth, $viewData);
(require $root . '/routes/public_mobile.php')($app, $twig, $viewData);
(require $root . '/routes/public_comments.php')($app, $pdo, $root, $auth);
(require $root . '/routes/public_forms.php')($app, $twig, $pdo, $root, $viewData);
(require $root . '/routes/public_external_link_tracking.php')($app, $pdo, $root, $auth);
(require $root . '/routes/public_commerce.php')($app, $twig, $pdo, $viewData);

(require $root . '/routes/admin.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_analytics.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_users.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_roles.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_activity.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_updates.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_tools.php')($app, $twig, $auth, $pdo, $viewData);
SectionDefinitionRegistry::instance()->registerProvider(new CoreSectionDefinitionProvider());

(require $root . '/routes/admin_pages.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_section_patterns.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_richtext.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_trash.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_editing.php')($app, $auth, $pdo);
(require $root . '/routes/admin_settings.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_search.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_mobile.php')($app, $twig, $auth, $pdo, $themeManager, $viewData);
(require $root . '/routes/admin_cache.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_maintenance.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_jobs.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_privacy.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_content_search.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_site_health.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_seo.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_security.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_comments.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_forms.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_commerce.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_system_api_keys.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_account.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_menus.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_media.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_content.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_content_lists.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_ai_blog.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_ai_comments.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_taxonomies.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_themes.php')($app, $twig, $auth, $pdo, $viewData);
(require $root . '/routes/admin_plugins.php')($app, $twig, $auth, $pdo, $viewData);

$pluginRepo = new PluginRepository($pdo);
$pluginScope = PluginLoadScope::fromWebRequest($_SERVER['REQUEST_URI'] ?? '/');
$pluginManager = new PluginManager($root, $pluginRepo, new PluginScanner($root), new PluginValidator($pdo));
$pluginContexts = $pluginManager->registerActivePublicRoutes($app, $twig, $auth, $pdo, $viewData, $eventDispatcher, $pluginScope);

(require $root . '/routes/public_search.php')($app, $twig, $pdo, $root, $viewData);
(require $root . '/routes/public_preview.php')($app, $twig, $pdo, $viewData);
(require $root . '/routes/public_pages.php')($app, $twig, $pdo, $viewData);
// Before public_taxonomy_archive: FastRoute errors if plugin admin adds /admin/.../... after /{a}/{b}/{c}.
$pluginManager->registerActiveAdminRoutes($app, $twig, $auth, $pdo, $viewData, $eventDispatcher);
if (method_exists($pluginManager, 'registerStruxaCatalogAdminRoutesIfNeeded')) {
    $pluginManager->registerStruxaCatalogAdminRoutesIfNeeded($app, $twig, $auth, $pdo, $viewData, $eventDispatcher);
}
(require $root . '/routes/public_taxonomy_archive.php')($app, $twig, $pdo, $viewData);
(require $root . '/routes/public_content_lists.php')($app, $twig, $pdo, $viewData);
(require $root . '/routes/public_content_index.php')($app, $twig, $pdo, $viewData);
(require $root . '/routes/public_content.php')($app, $twig, $pdo, $viewData);

$pluginBootContexts = $pluginManager->createActiveBootContexts(
    $app,
    $twig,
    $auth,
    $pdo,
    $viewData,
    $eventDispatcher,
    $pluginScope,
);
$pluginManager->bootActivePluginLifecycle($pluginBootContexts, $eventDispatcher);

$scheduleRunToken = trim((string) ($_ENV['CMS_SCHEDULE_RUN_TOKEN'] ?? ''));
if ($scheduleRunToken !== '') {
    $app->get('/schedule/run', function (Request $request, Response $response) use ($pdo, $scheduleRunToken): Response {
        $q = $request->getQueryParams();
        if (($q['token'] ?? '') !== $scheduleRunToken) {
            throw new HttpNotFoundException($request);
        }
        (new \App\Preview\PreviewTokenRepository($pdo))->deleteExpired();
        $report = (new \App\Publishing\PublishScheduleService($pdo))->runDue();
        $ok = $report['errors'] === [];
        $payload = json_encode(array_merge(['ok' => $ok], $report), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($ok ? 200 : 500);
    })->setName('public.schedule_run');
}

return $app;
