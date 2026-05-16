<?php

declare(strict_types=1);

/**
 * Struxa web installer — dark UI aligned with the admin panel.
 *
 * Usage: open /install.php in a browser (empty MySQL database recommended).
 * After success: delete or rename this file in production, and keep .env private.
 */

use App\CmsVersion;
use App\Database\Migrator;

$root = dirname(__DIR__);
$lockFile = $root . '/storage/installed.lock';
$bootstrapSql = $root . '/database/install/phpauth_bootstrap.sql';
$migrationsDir = $root . '/database/migrations';

session_start();

header('X-Robots-Tag: noindex, nofollow');

if (!is_file($root . '/vendor/autoload.php')) {
    install_render_minimal(
        'Setup unavailable',
        install_block_missing_vendor()
    );
    exit;
}

require $root . '/vendor/autoload.php';

if (is_file($lockFile)) {
    install_render_shell('Already installed', install_block_already_installed(), false);
    exit;
}

if (isset($_GET['finished']) && ($_SESSION['install_success'] ?? false) === true) {
    unset($_SESSION['install_success']);
    install_render_shell('You are ready', install_block_success(), false);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    $expected = (string) ($_SESSION['install_csrf'] ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        install_render_shell('Struxa setup', install_form_install($root, ['Invalid security token. Refresh and try again.']), true);
        exit;
    }

    $errors = [];
    $dbHost = trim((string) ($_POST['db_host'] ?? ''));
    $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
    $siteName = trim((string) ($_POST['site_name'] ?? ''));
    $panelTitle = trim((string) ($_POST['cms_panel_title'] ?? 'Struxa'));
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    $adminPass2 = (string) ($_POST['admin_password_confirm'] ?? '');
    $displayName = trim((string) ($_POST['admin_display_name'] ?? 'Administrator'));
    $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'Database host, name, and user are required.';
    }
    if ($dbPort === '' || !ctype_digit($dbPort)) {
        $errors[] = 'Database port must be a number.';
    }
    if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Enter a valid site URL (e.g. https://example.com or http://localhost:3439).';
    }
    if ($siteName === '') {
        $errors[] = 'Site name is required.';
    }
    if ($panelTitle === '') {
        $errors[] = 'Panel title is required.';
    }
    if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid administrator email.';
    }
    if (strlen($adminEmail) > 100) {
        $errors[] = 'Administrator email must be 100 characters or fewer (PHPAuth limit).';
    }
    if (strlen($adminPass) < 10) {
        $errors[] = 'Administrator password must be at least 10 characters.';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = 'Password confirmation does not match.';
    }
    if ($displayName === '') {
        $errors[] = 'Display name is required.';
    }
    if (strlen($displayName) > 160) {
        $errors[] = 'Display name must be 160 characters or fewer.';
    }

    if ($adminUsername !== '') {
        $uv = \App\Auth\UsernameValidation::validate($adminUsername, false);
        if (!$uv['ok']) {
            $errors[] = $uv['message'];
        }
    }

    $req = install_check_requirements($root);
    foreach ($req['errors'] as $e) {
        $errors[] = $e;
    }

    if ($errors !== []) {
        install_render_shell('Struxa setup', install_form_install($root, $errors), true);
        exit;
    }

    $siteKey = bin2hex(random_bytes(32));
    $parsed = parse_url($siteUrl);
    $cookieSecure = (($parsed['scheme'] ?? '') === 'https') ? '1' : '0';
    $siteEmail = 'no-reply@' . preg_replace('/^www\./', '', (string) ($parsed['host'] ?? 'localhost'));

    $envContent = install_build_env_file([
        'DB_HOST' => $dbHost,
        'DB_PORT' => $dbPort,
        'DB_NAME' => $dbName,
        'DB_USER' => $dbUser,
        'DB_PASS' => $dbPass,
        'PHPAUTH_SITE_KEY' => $siteKey,
        'PHPAUTH_SITE_URL' => $siteUrl,
        'PHPAUTH_COOKIE_SECURE' => $cookieSecure,
        'PHPAUTH_COOKIE_SAMESITE' => 'Lax',
        'PHPAUTH_SITE_EMAIL' => $siteEmail,
        'SITE_NAME' => $siteName,
        'CMS_UPLOAD_MAX_MB' => '5',
    ]);

    if (@file_put_contents($root . '/.env', $envContent) === false) {
        install_render_shell('Struxa setup', install_form_install($root, ['Could not write .env — check permissions on the project root.']), true);
        exit;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        install_render_shell('Struxa setup', install_form_install($root, ['Database connection failed: ' . $e->getMessage()]), true);
        exit;
    }

    try {
        if (!is_readable($bootstrapSql)) {
            throw new RuntimeException('Missing database/install/phpauth_bootstrap.sql');
        }
        install_ensure_phpauth($pdo, $bootstrapSql);
        $migrator = new Migrator($pdo, $migrationsDir);
        $migrator->run();

        $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $stmt = $pdo->prepare('INSERT INTO phpauth_users (email, username, password, isactive) VALUES (?, NULLIF(?, \'\'), ?, 1)');
        $stmt->execute([$adminEmail, $adminUsername, $hash]);
        $phpauthId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO cms_users (phpauth_user_id, email, display_name, role, is_active) VALUES (?, ?, ?, \'admin\', 1)'
        );
        $stmt->execute([$phpauthId, $adminEmail, $displayName]);
        $cmsUserId = (int) $pdo->lastInsertId();

        $rb = $pdo->prepare('INSERT IGNORE INTO cms_role_user (role_id, user_id) SELECT id, ? FROM cms_roles WHERE slug = \'super_admin\' LIMIT 1');
        $rb->execute([$cmsUserId]);

        $upd = $pdo->prepare('UPDATE cms_settings SET setting_value = ? WHERE setting_key = ?');
        $upd->execute([$siteName, 'site_name']);
        $upd->execute([$panelTitle, 'cms_panel_title']);

        $lockPayload = json_encode(
            ['installed_at' => gmdate('c'), 'cms_version' => CmsVersion::CURRENT],
            JSON_THROW_ON_ERROR
        );
        if (@file_put_contents($lockFile, $lockPayload . "\n") === false) {
            throw new RuntimeException('Could not write storage/installed.lock — check storage/ is writable.');
        }
    } catch (Throwable $e) {
        install_render_shell(
            'Struxa setup',
            install_form_install($root, ['Install failed: ' . $e->getMessage() . ' — fix the issue, then refresh (you may need to fix the database or remove partial tables).']),
            true
        );
        exit;
    }

    $_SESSION['install_success'] = true;
    header('Location: install.php?finished=1', true, 302);
    exit;
}

// GET — show form
$req = install_check_requirements($root);
$errs = $req['errors'];
install_render_shell('Struxa setup', install_form_install($root, $errs), true);

// --- helpers ---

/**
 * @return array{errors: list<string>, ok: bool}
 */
function install_check_requirements(string $root): array
{
    $errors = [];
    if (PHP_VERSION_ID < 80200) {
        $errors[] = 'PHP 8.2 or newer is required (found ' . PHP_VERSION . ').';
    }
    foreach (['pdo', 'pdo_mysql', 'json', 'mbstring'] as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "PHP extension \"{$ext}\" is required.";
        }
    }
    if (!is_dir($root . '/storage') || !is_writable($root . '/storage')) {
        $errors[] = 'The storage/ directory must exist and be writable.';
    }
    $uploads = $root . '/public/uploads';
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0755, true);
    }
    if (!is_dir($uploads) || !is_writable($uploads)) {
        $errors[] = 'The public/uploads/ directory must be writable (for media uploads).';
    }
    if (!is_file($root . '/vendor/autoload.php')) {
        $errors[] = 'Run composer install from the project root.';
    }

    return ['errors' => $errors, 'ok' => $errors === []];
}

/**
 * @param array<string, string> $vars
 */
function install_build_env_file(array $vars): string
{
    $lines = [
        '# Struxa — generated by web installer ' . gmdate('c'),
        '# Regenerate keys and URLs as needed for each environment.',
    ];
    foreach ($vars as $k => $v) {
        $lines[] = $k . '=' . install_env_quote($v);
    }
    $lines[] = '';
    $lines[] = '# Theme and plugin catalog (struxapoint.com folder; optional override)';
    $lines[] = 'STRUXA_DIST_CATALOG_URL=https://struxapoint.com/struxa-dist/repo.json';

    return implode("\n", $lines) . "\n";
}

function install_env_quote(string $value): string
{
    $escaped = str_replace(["\r", "\n", '\\', '"'], ['', '', '\\\\', '\\"'], $value);

    return '"' . $escaped . '"';
}

function install_ensure_phpauth(PDO $pdo, string $sqlPath): void
{
    $check = $pdo->query("SHOW TABLES LIKE 'phpauth_users'");
    if ($check !== false && $check->rowCount() > 0) {
        return;
    }
    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException('Cannot read PHPAuth bootstrap SQL.');
    }
    install_run_sql_script($pdo, $sql);
}

function install_run_sql_script(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?=\R)/', $sql) ?: [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }
}

function install_csrf_token(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['install_csrf'];
}

function install_render_minimal(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · Struxa</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,600;0,700;0,800&display=swap" rel="stylesheet">';
    echo '<style>' . install_css() . '</style></head><body>';
    echo '<div class="ix-grid" aria-hidden="true"></div><div class="ix-wrap">';
    echo $body;
    echo '</div></body></html>';
}

function install_render_shell(string $title, string $body, bool $showBrand): void
{
    $v = htmlspecialchars(CmsVersion::CURRENT, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · Struxa</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">';
    echo '<style>' . install_css() . '</style></head><body>';
    echo '<div class="ix-grid" aria-hidden="true"></div><div class="ix-wrap">';
    if ($showBrand) {
        echo '<header class="ix-head"><div class="ix-brand"><span class="ix-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="8" x="3" y="3" rx="1.5"/><rect width="8" height="8" x="13" y="3" rx="1.5"/><rect width="8" height="8" x="3" y="13" rx="1.5"/><rect width="8" height="8" x="13" y="13" rx="1.5"/></svg></span><div><span class="ix-brand-title">Struxa</span><span class="ix-brand-sub">Content CMS · setup</span></div></div><span class="ix-ver">v' . $v . '</span></header>';
    }
    echo $body;
    echo '</div></body></html>';
}

function install_css(): string
{
    return <<<'CSS'
:root {
  --ix-bg: #090f1a;
  --ix-card: #111a2a;
  --ix-border: rgba(255,255,255,0.08);
  --ix-text: #f4f4f5;
  --ix-muted: #9ca3af;
  --ix-dim: #6b7280;
  --ix-purple: #a855f7;
  --ix-blue: #3b82f6;
  --ix-teal: #2dd4bf;
  --ix-orange: #fb923c;
  --ix-radius: 16px;
  --ix-font: "Plus Jakarta Sans", system-ui, sans-serif;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  min-height: 100vh;
  font-family: var(--ix-font);
  color: var(--ix-text);
  background: var(--ix-bg);
  background-image:
    radial-gradient(ellipse 95% 75% at 0% 0%, rgba(30, 64, 175, 0.16), transparent 52%),
    radial-gradient(ellipse 80% 60% at 100% -10%, rgba(168, 85, 247, 0.12), transparent 48%),
    radial-gradient(ellipse 70% 50% at 50% 100%, rgba(88, 28, 135, 0.1), transparent 55%);
  -webkit-font-smoothing: antialiased;
}
.ix-grid {
  position: fixed;
  inset: 0;
  pointer-events: none;
  opacity: 0.35;
  background-image:
    linear-gradient(rgba(255,255,255,0.028) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.028) 1px, transparent 1px);
  background-size: 48px 48px;
  mask-image: radial-gradient(ellipse 90% 70% at 50% 0%, black, transparent 68%);
}
.ix-wrap { position: relative; z-index: 1; max-width: 520px; margin: 0 auto; padding: 2.5rem 1.25rem 3rem; }
.ix-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 2rem;
}
.ix-brand { display: flex; align-items: center; gap: 0.85rem; }
.ix-mark {
  width: 2.5rem; height: 2.5rem;
  display: grid; place-items: center;
  border-radius: 12px;
  background: linear-gradient(145deg, rgba(168,85,247,0.2), rgba(59,130,246,0.12));
  border: 1px solid var(--ix-border);
  color: #c4b5fd;
}
.ix-mark svg { width: 1.35rem; height: 1.35rem; }
.ix-brand-title { display: block; font-weight: 800; font-size: 1.35rem; letter-spacing: -0.03em; }
.ix-brand-sub { display: block; font-size: 0.75rem; color: var(--ix-muted); font-weight: 600; margin-top: 0.15rem; }
.ix-ver {
  font-size: 0.7rem; font-weight: 700;
  letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--ix-dim);
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  border: 1px solid var(--ix-border);
  background: rgba(255,255,255,0.03);
}
.ix-card {
  background: var(--ix-card);
  border: 1px solid var(--ix-border);
  border-radius: var(--ix-radius);
  padding: 1.5rem 1.35rem;
  box-shadow:
    0 0 0 1px rgba(0,0,0,0.35) inset,
    0 24px 60px -20px rgba(0,0,0,0.55),
    0 0 48px -12px rgba(168, 85, 247, 0.12);
}
.ix-card + .ix-card { margin-top: 1rem; }
.ix-card-title {
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.ix-card-desc { margin: 0 0 1rem; font-size: 0.8125rem; color: var(--ix-muted); line-height: 1.5; }
.ix-label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ix-dim); margin-bottom: 0.35rem; }
.ix-input, .ix-select {
  width: 100%;
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  border: 1px solid var(--ix-border);
  background: rgba(0,0,0,0.25);
  color: var(--ix-text);
  font: inherit;
  font-size: 0.9rem;
}
.ix-input:focus, .ix-select:focus {
  outline: none;
  border-color: rgba(168, 85, 247, 0.45);
  box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.12);
}
.ix-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
@media (max-width: 480px) { .ix-row2 { grid-template-columns: 1fr; } }
.ix-field { margin-bottom: 0.85rem; }
.ix-field:last-child { margin-bottom: 0; }
.ix-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  width: 100%;
  margin-top: 1rem;
  padding: 0.85rem 1.25rem;
  border: none;
  border-radius: 12px;
  font: inherit;
  font-weight: 700;
  font-size: 0.95rem;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #9333ea, #6366f1);
  box-shadow: 0 4px 22px rgba(168, 85, 247, 0.35);
  transition: filter 0.2s ease, transform 0.15s ease;
}
.ix-btn:hover { filter: brightness(1.06); }
.ix-btn:active { transform: scale(0.99); }
.ix-errors {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0.85rem 1rem;
  border-radius: 12px;
  background: rgba(248, 113, 113, 0.1);
  border: 1px solid rgba(248, 113, 113, 0.35);
  color: #fecaca;
  font-size: 0.875rem;
  line-height: 1.45;
}
.ix-errors li + li { margin-top: 0.35rem; }
.ix-req-ok { color: #6ee7b7; font-size: 0.8125rem; margin: 0 0 0.75rem; font-weight: 600; }
.ix-req-bad { color: #fecaca; font-size: 0.8125rem; margin: 0 0 0.75rem; }
.ix-hint { font-size: 0.75rem; color: var(--ix-dim); margin-top: 0.35rem; line-height: 1.4; }
.ix-success-icon {
  width: 3.5rem; height: 3.5rem;
  margin: 0 auto 1.25rem;
  display: grid; place-items: center;
  border-radius: 50%;
  background: linear-gradient(145deg, rgba(45,212,191,0.2), rgba(16,185,129,0.1));
  border: 1px solid rgba(45,212,191,0.35);
  color: #5eead4;
}
.ix-success-icon svg { width: 1.75rem; height: 1.75rem; }
.ix-center { text-align: center; }
.ix-links { margin-top: 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; }
.ix-links a {
  color: #a5b4fc;
  font-weight: 600;
  font-size: 0.9rem;
  text-decoration: none;
}
.ix-links a:hover { text-decoration: underline; }
.ix-muted-block { font-size: 0.8125rem; color: var(--ix-muted); line-height: 1.55; margin: 0; }
CSS;
}

/**
 * @param list<string> $errors
 */
function install_form_install(string $root, array $errors): string
{
    $csrf = htmlspecialchars(install_csrf_token(), ENT_QUOTES, 'UTF-8');
    $req = install_check_requirements($root);
    $dbHost = htmlspecialchars((string) ($_POST['db_host'] ?? '127.0.0.1'), ENT_QUOTES, 'UTF-8');
    $dbPort = htmlspecialchars((string) ($_POST['db_port'] ?? '3306'), ENT_QUOTES, 'UTF-8');
    $dbName = htmlspecialchars((string) ($_POST['db_name'] ?? 'studio'), ENT_QUOTES, 'UTF-8');
    $dbUser = htmlspecialchars((string) ($_POST['db_user'] ?? 'studio'), ENT_QUOTES, 'UTF-8');
    $siteUrl = htmlspecialchars((string) ($_POST['site_url'] ?? install_guess_site_url()), ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars((string) ($_POST['site_name'] ?? 'My site'), ENT_QUOTES, 'UTF-8');
    $panelTitle = htmlspecialchars((string) ($_POST['cms_panel_title'] ?? 'Struxa'), ENT_QUOTES, 'UTF-8');
    $adminEmail = htmlspecialchars((string) ($_POST['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $displayName = htmlspecialchars((string) ($_POST['admin_display_name'] ?? 'Administrator'), ENT_QUOTES, 'UTF-8');
    $adminUser = htmlspecialchars((string) ($_POST['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8');

    ob_start();
    if ($errors !== []) {
        echo '<ul class="ix-errors" role="alert">';
        foreach ($errors as $e) {
            echo '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }

    if ($req['ok']) {
        echo '<p class="ix-req-ok">Requirements look good. Empty MySQL database recommended.</p>';
    } else {
        echo '<p class="ix-req-bad">Fix the issues below before continuing.</p>';
    }

    echo '<form method="post" action="install.php" class="ix-form">';
    echo '<input type="hidden" name="_csrf" value="' . $csrf . '" />';

    echo '<div class="ix-card"><h2 class="ix-card-title">Database</h2><p class="ix-card-desc">Create an empty database and user with full rights on that database, then enter credentials here.</p>';
    echo '<div class="ix-field"><label class="ix-label" for="db_host">Host</label><input class="ix-input" id="db_host" name="db_host" value="' . $dbHost . '" required autocomplete="off" /></div>';
    echo '<div class="ix-row2"><div class="ix-field"><label class="ix-label" for="db_port">Port</label><input class="ix-input" id="db_port" name="db_port" value="' . $dbPort . '" required inputmode="numeric" /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="db_name">Database name</label><input class="ix-input" id="db_name" name="db_name" value="' . $dbName . '" required autocomplete="off" /></div></div>';
    echo '<div class="ix-field"><label class="ix-label" for="db_user">User</label><input class="ix-input" id="db_user" name="db_user" value="' . $dbUser . '" required autocomplete="username" /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="db_pass">Password</label><input class="ix-input" id="db_pass" name="db_pass" type="password" value="" autocomplete="new-password" /><p class="ix-hint">Leave blank if your MySQL user has no password (local dev only).</p></div></div>';

    echo '<div class="ix-card"><h2 class="ix-card-title">Site</h2><p class="ix-card-desc">Public URL of this installation (no trailing slash). Used for links and cookies.</p>';
    echo '<div class="ix-field"><label class="ix-label" for="site_url">Site URL</label><input class="ix-input" id="site_url" name="site_url" type="url" placeholder="https://example.com" value="' . $siteUrl . '" required /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="site_name">Site name</label><input class="ix-input" id="site_name" name="site_name" value="' . $siteName . '" required /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="cms_panel_title">Admin panel title</label><input class="ix-input" id="cms_panel_title" name="cms_panel_title" value="' . $panelTitle . '" required /></div></div>';

    echo '<div class="ix-card"><h2 class="ix-card-title">Administrator</h2><p class="ix-card-desc">First CMS account (super admin). You can add more users later in the panel.</p>';
    echo '<div class="ix-field"><label class="ix-label" for="admin_email">Email</label><input class="ix-input" id="admin_email" name="admin_email" type="email" value="' . $adminEmail . '" required maxlength="100" autocomplete="email" /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="admin_username">Username (optional)</label><input class="ix-input" id="admin_username" name="admin_username" value="' . $adminUser . '" maxlength="32" autocomplete="off" /><p class="ix-hint">For login with username instead of email. Letters, numbers, underscores; 3–32 chars.</p></div>';
    echo '<div class="ix-field"><label class="ix-label" for="admin_display_name">Display name</label><input class="ix-input" id="admin_display_name" name="admin_display_name" value="' . $displayName . '" required maxlength="160" /></div>';
    echo '<div class="ix-row2"><div class="ix-field"><label class="ix-label" for="admin_password">Password</label><input class="ix-input" id="admin_password" name="admin_password" type="password" required minlength="10" autocomplete="new-password" /></div>';
    echo '<div class="ix-field"><label class="ix-label" for="admin_password_confirm">Confirm</label><input class="ix-input" id="admin_password_confirm" name="admin_password_confirm" type="password" required minlength="10" autocomplete="new-password" /></div></div></div>';

    echo '<button type="submit" class="ix-btn" ' . ($req['ok'] ? '' : 'disabled aria-disabled="true"') . '>Install Struxa</button>';
    echo '</form>';

    return (string) ob_get_clean();
}

function install_guess_site_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

function install_block_success(): string
{
    ob_start();
    echo '<div class="ix-card ix-center">';
    echo '<div class="ix-success-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>';
    echo '<h2 class="ix-card-title" style="margin-bottom:0.5rem">Installation complete</h2>';
    echo '<p class="ix-muted-block">Database migrated, administrator created, and <code style="color:#c4b5fd">.env</code> written. For production, remove or protect <code style="color:#c4b5fd">public/install.php</code> and confirm <code style="color:#c4b5fd">storage/</code> stays non-public.</p>';
    echo '<div class="ix-links"><a href="/login">Sign in to the CMS</a><a href="/">View the site</a></div>';
    echo '</div>';

    return (string) ob_get_clean();
}

function install_block_already_installed(): string
{
    ob_start();
    echo '<div class="ix-card ix-center">';
    echo '<h2 class="ix-card-title">Already installed</h2>';
    echo '<p class="ix-muted-block">This copy has a setup lock in <code style="color:#c4b5fd">storage/installed.lock</code>. To reinstall, remove that file and <code style="color:#c4b5fd">.env</code> after backing up your database.</p>';
    echo '<div class="ix-links"><a href="/login">CMS login</a><a href="/">Home</a></div>';
    echo '</div>';

    return (string) ob_get_clean();
}

function install_block_missing_vendor(): string
{
    ob_start();
    echo '<div class="ix-card">';
    echo '<h2 class="ix-card-title">Composer dependencies missing</h2>';
    echo '<p class="ix-card-desc">From the project root, run <code style="color:#93c5fd">composer install</code>, then reload this page.</p>';
    echo '</div>';

    return (string) ob_get_clean();
}
