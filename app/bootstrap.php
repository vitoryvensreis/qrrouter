<?php
declare(strict_types=1);

define('ROTAQR_ROOT', dirname(__DIR__));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function security_headers(bool $html = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    if ($html) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}

function configuration(): array {
    static $config;
    if ($config === null) {
        $path = ROTAQR_ROOT . '/app/config.php';
        if (!is_file($path)) throw new RuntimeException('Sistema ainda não instalado.');
        $config = require $path;
        if (!is_array($config) || empty($config['base_url']) || empty($config['secret'])) throw new RuntimeException('Configuração inválida.');
    }
    return $config;
}

function base_url(): string { return rtrim(configuration()['base_url'], '/'); }
function router_url(string $code): string { return base_url() . '/r.php?c=' . rawurlencode($code); }

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $c = configuration()['database'];
        $pdo = new PDO('mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function start_session(bool $installer = false): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $base = $installer ? '' : base_url();
    $path = $installer ? (rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/') : (rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/');
    $https = $installer ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') : parse_url($base, PHP_URL_SCHEME) === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_name('rq_' . substr(hash('sha256', ROTAQR_ROOT), 0, 12));
    session_set_cookie_params(['lifetime'=>0, 'path'=>$path, 'secure'=>$https, 'httponly'=>true, 'samesite'=>'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrf_token(): string { return (string) ($_SESSION['csrf'] ?? ''); }
function valid_csrf(?string $token): bool { return $token !== null && csrf_token() !== '' && hash_equals(csrf_token(), $token); }

function destroy_login(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-42000, 'path'=>$p['path'], 'secure'=>$p['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
    }
    session_destroy();
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > 7200 || time() - (int) ($_SESSION['started'] ?? 0) > 43200) {
        destroy_login();
        return null;
    }
    $q = db()->prepare('SELECT id, name, email, role, active, session_version, must_change_password, created_at FROM rq_users WHERE id = ?');
    $q->execute([(int) $_SESSION['uid']]);
    $user = $q->fetch();
    if (!$user || !(int) $user['active'] || (int) $user['session_version'] !== (int) ($_SESSION['version'] ?? 0)) {
        destroy_login();
        return null;
    }
    $_SESSION['last_seen'] = time();
    return $user;
}

function hash_password(string $password): string {
    if (defined('PASSWORD_ARGON2ID')) return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>32768, 'time_cost'=>3, 'threads'=>1]);
    return password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
}

function validate_password(mixed $password): string {
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) throw new InvalidArgumentException('A senha precisa ter entre 10 e 72 bytes (acentos ocupam mais de um byte).');
    return $password;
}

function validate_name(mixed $name): string {
    if (!is_string($name)) throw new InvalidArgumentException('Informe um nome.');
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || preg_match('/[\x00-\x1f\x7f]/u', $name)) throw new InvalidArgumentException('Use um nome de até 100 caracteres.');
    return $name;
}

function validate_email(mixed $email): string {
    if (!is_string($email)) throw new InvalidArgumentException('Informe um e-mail válido.');
    $email = strtolower(trim($email));
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Informe um e-mail válido.');
    return $email;
}

function validate_destination(mixed $url): string {
    if (!is_string($url)) throw new InvalidArgumentException('Informe o link de destino.');
    $url = trim($url);
    $parts = parse_url($url);
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
        throw new InvalidArgumentException('Use um endereço completo, começando com https:// ou http://, com até 2.048 caracteres.');
    }
    $own = parse_url(base_url());
    $path = rawurldecode($parts['path'] ?? '/');
    $ownPath = rtrim($own['path'] ?? '', '/') . '/r.php';
    if (strtolower($parts['host']) === strtolower($own['host'] ?? '') && ($path === $ownPath || str_contains($path, '/r.php'))) throw new InvalidArgumentException('O destino deve ser a página final, não outro roteador deste sistema.');
    return $url;
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_user(): array {
    $user = current_user();
    if (!$user) json_response(['error'=>'Sua sessão terminou. Entre novamente.'], 401);
    return $user;
}

function require_admin(array $user): void {
    if ($user['role'] !== 'admin') json_response(['error'=>'Esta ação é exclusiva do administrador.'], 403);
}

function rate_limit(string $key, int $limit, int $seconds): void {
    $bucket = hash_hmac('sha256', $key, configuration()['secret']);
    $now = time();
    $q = db()->prepare('INSERT INTO rq_rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = IF(expires_at <= ?, 1, hits + 1), expires_at = IF(expires_at <= ?, ?, expires_at)');
    $q->execute([$bucket, $now+$seconds, $now, $now, $now+$seconds]);
    $q = db()->prepare('SELECT hits, expires_at FROM rq_rate_limits WHERE bucket = ?');
    $q->execute([$bucket]);
    $row = $q->fetch();
    if ((int) $row['hits'] > $limit) {
        header('Retry-After: ' . max(1, (int) $row['expires_at']-$now));
        json_response(['error'=>'Muitas tentativas. Aguarde 15 minutos e tente novamente.'], 429);
    }
    if (random_int(1, 50) === 1) {
        $q = db()->prepare('DELETE FROM rq_rate_limits WHERE expires_at < ? LIMIT 500');
        $q->execute([$now]);
    }
}

function link_record(array $row): array {
    return ['id'=>(int)$row['id'], 'name'=>$row['name'], 'code'=>$row['code'], 'url'=>router_url($row['code']), 'destination'=>$row['destination'], 'active'=>(bool)$row['active'], 'owner_name'=>$row['owner_name'] ?? '', 'owner_id'=>(int)$row['user_id'], 'created_at'=>$row['created_at'], 'updated_at'=>$row['updated_at']];
}

function user_record(array $row): array {
    return ['id'=>(int)$row['id'], 'name'=>$row['name'], 'email'=>$row['email'], 'role'=>$row['role'], 'active'=>(bool)$row['active'], 'must_change_password'=>(bool)$row['must_change_password'], 'created_at'=>$row['created_at']];
}
