<?php
/** Inicialização central do SmartParking: sessão, PDO, CSRF e autenticação. */
const DB_HOST = '127.0.0.1';
const DB_PORT = '3309';
const DB_NAME = 'smartparking';
const DB_USER = 'root';
const DB_PASS = 'senai@123'; // Em produção, use variável de ambiente.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    session_start();
}
function criar_conexao(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = criar_conexao();
        } catch (PDOException $ex) {
            error_log('SmartParking PDO: ' . $ex->getMessage());
            http_response_code(503);
            exit('Não foi possível conectar ao sistema no momento. Tente novamente mais tarde.');
        }
    }
    return $pdo;
}
function db_tentar(): ?PDO
{
    static $pdo = null, $falhou = false;
    if ($pdo || $falhou)
        return $pdo;
    try {
        $pdo = criar_conexao();
    } catch (PDOException $ex) {
        error_log('SmartParking PDO: ' . $ex->getMessage());
        $falhou = true;
    }
    return $pdo;
}
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function post_str(string $k): string
{
    $v = $_POST[$k] ?? '';
    return is_string($v) ? $v : '';
}
function redirecionar(string $url): void
{
    header('Location: ' . $url);
    exit;
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_ok(string $t): bool
{
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function usuario_logado(): ?array
{
    if (empty($_SESSION['usuario_id']))
        return null;
    return ['id' => (int) $_SESSION['usuario_id'], 'nome' => $_SESSION['usuario_nome'] ?? '', 'email' => $_SESSION['usuario_email'] ?? '', 'perfil' => $_SESSION['usuario_perfil'] ?? 'cliente'];
}
function url_atual_relativa(): string
{
    $s = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $q = $_SERVER['QUERY_STRING'] ?? '';
    return $s . ($q !== '' ? '?' . $q : '');
}
function destino_local_seguro(string $d): bool
{
    if ($d === '' || preg_match('/[\r\n]/', $d))
        return false;
    $p = parse_url($d);
    if ($p === false || isset($p['scheme']) || isset($p['host']))
        return false;
    $f = $p['path'] ?? '';
    return strpos($f, '/') === false && strpos($f, '\\') === false && strpos($f, '..') === false && preg_match('/^[A-Za-z0-9_-]+\.php$/', $f) === 1 && is_file(__DIR__ . '/' . $f);
}
function exigir_login(): void
{
    if (!usuario_logado()) {
        $_SESSION['redirect_after_login'] = url_atual_relativa();
        redirecionar('login.php?next=' . rawurlencode($_SESSION['redirect_after_login']));
    }
}
function exigir_perfil(array $perfis): void
{
    exigir_login();
    $u = usuario_logado();
    if (!$u || !in_array($u['perfil'], $perfis, true)) {
        http_response_code(403);
        exit('Acesso negado. Você não tem permissão para acessar esta página.');
    }
}
