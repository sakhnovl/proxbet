<?php

declare(strict_types=1);

/**
 * Admin API Router for Proxbet - Bans Management + Statistics
 *
 * Endpoints:
 *   GET  ?action=list_bans[&limit=20&offset=0]
 *   POST ?action=add_ban        body: {country,liga,home,away}
 *   POST ?action=update_ban     body: {id, country,liga,home,away}
 *   POST ?action=delete_ban     body: {id}
 *   GET  ?action=list_matches_stats[&limit=20&offset=0&status=ok&q=team]
 *   GET  ?action=get_match_stats&match_id=123
 *   POST ?action=refresh_match_stats body: {match_id}
 *   POST ?action=refresh_stats_batch body: {limit,offset,force}
 *   GET  ?action=stats_overview
 *
 * Auth: Bearer token in Authorization header OR ?token= query param.
 * Token must match ADMIN_PASSWORD from .env
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../bootstrap/runtime.php';
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../security/RateLimiter.php';
require_once __DIR__ . '/../security/CsrfProtection.php';
require_once __DIR__ . '/../security/InputValidator.php';
require_once __DIR__ . '/../security/SecurityHeaders.php';
require_once __DIR__ . '/../security/RequestValidator.php';
require_once __DIR__ . '/../security/AuditLogger.php';

use Proxbet\Line\Db;
use Proxbet\Statistic\StatisticServiceFactory;
use Proxbet\Security\RateLimiter;
use Proxbet\Security\CsrfProtection;
use Proxbet\Security\InputValidator;
use Proxbet\Security\SecurityHeaders;
use Proxbet\Security\RequestValidator;
use Proxbet\Security\AuditLogger;

proxbet_bootstrap_env();

// ── Security Headers ───────────────────────────────────────────────────────

SecurityHeaders::apply(isApi: true);

// ── Request Size Validation ────────────────────────────────────────────────

RequestValidator::validateRequestSize();

// ── CORS / Headers ─────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
proxbetHandleCors(['GET', 'POST', 'OPTIONS'], ['Authorization', 'Content-Type']);

// ── Helpers ────────────────────────────────────────────────────────────────

function jsonOk(mixed $data): never
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function jsonError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function getBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sanitizeString(?string $value): ?string
{
    return InputValidator::sanitizeString($value, 255);
}

function sanitizeLike(?string $value): ?string
{
    return InputValidator::sanitizeLike($value);
}

// ── Rate Limiting ──────────────────────────────────────────────────────────

$rateLimiter = new RateLimiter(
    __DIR__ . '/../../data/rate_limits',
    maxAttempts: 20,
    windowSeconds: 60
);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!$rateLimiter->check('admin_api:' . $clientIp)) {
    http_response_code(429);
    header('Retry-After: 60');
    jsonError('Too many requests. Please try again later.', 429);
}

// ── Authentication ─────────────────────────────────────────────────────────

try {
    proxbet_require_env(['ADMIN_PASSWORD', 'DB_HOST', 'DB_USER', 'DB_NAME']);
    $adminPassword = (string) getenv('ADMIN_PASSWORD');
} catch (Throwable $e) {
    jsonError('Server misconfiguration: ' . $e->getMessage(), 500);
}

// Extract token from Authorization header ONLY (no query string for security)
$token = '';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

// Handle Apache/XAMPP stripping Authorization header
if ($authHeader === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
}

// ── Database Connection ────────────────────────────────────────────────────

try {
    $pdo = Db::connectFromEnv();
    $auditLogger = new AuditLogger($pdo);
} catch (\Throwable $e) {
    $sanitizedError = RequestValidator::sanitizeErrorMessage($e->getMessage());
    jsonError('Database connection failed: ' . $sanitizedError, 503);
}

// Security: No longer accept token from query string
// This prevents token leakage in server logs and browser history
if ($token === '') {
    jsonError('Missing Authorization header. Use: Authorization: Bearer <token>', 401);
}

if (!hash_equals($adminPassword, $token)) {
    $auditLogger->logAuthAttempt(false, null, $clientIp, 'Invalid token');
    jsonError('Unauthorized.', 401);
}

// Log successful authentication
$auditLogger->logAuthAttempt(true, 'admin', $clientIp);

// ── Router ─────────────────────────────────────────────────────────────────

$action = trim($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $action === 'list_bans' && $method === 'GET' => handleListBans($pdo),
    $action === 'add_ban'   && $method === 'POST' => handleAddBan($pdo),
    $action === 'update_ban' && $method === 'POST' => handleUpdateBan($pdo),
    $action === 'delete_ban' && $method === 'POST' => handleDeleteBan($pdo),
    $action === 'list_matches_stats' && $method === 'GET' => handleListMatchesStats($pdo),
    $action === 'get_match_stats' && $method === 'GET' => handleGetMatchStats($pdo),
    $action === 'refresh_match_stats' && $method === 'POST' => handleRefreshMatchStats(),
    $action === 'refresh_stats_batch' && $method === 'POST' => handleRefreshStatsBatch(),
    $action === 'stats_overview' && $method === 'GET' => handleStatsOverview($pdo),
    default => jsonError('Unknown action or method.', 404),
};

// ── Handlers ───────────────────────────────────────────────────────────────

function handleListBans(PDO $pdo): never
{
    $limit  = max(1, min(50, (int) ($_GET['limit']  ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $result = Db::listBans($pdo, $limit, $offset);
    jsonOk($result);
}

function handleAddBan(PDO $pdo): never
{
    global $auditLogger, $clientIp;
    
    $body = getBody();

    $data = [
        'country' => sanitizeString($body['country'] ?? null),
        'liga'    => sanitizeString($body['liga']    ?? null),
        'home'    => sanitizeString($body['home']    ?? null),
        'away'    => sanitizeString($body['away']    ?? null),
        'is_active' => isset($body['is_active']) ? (bool) $body['is_active'] : 1,
    ];

    // At least one field must be set
    if (array_filter([$data['country'], $data['liga'], $data['home'], $data['away']]) === []) {
        jsonError('At least one field (country, liga, home, away) is required.');
    }

    try {
        $id = Db::addBan($pdo, $data);
        $ban = Db::getBanById($pdo, $id);
        
        // Audit log
        $auditLogger->logAdminAction('add_ban', 'admin', 'ban', $id, $data, $clientIp);
        
        jsonOk($ban);
    } catch (\Throwable $e) {
        $sanitizedError = RequestValidator::sanitizeErrorMessage($e->getMessage());
        jsonError('Failed to add ban: ' . $sanitizedError, 500);
    }
}

function handleUpdateBan(PDO $pdo): never
{
    global $auditLogger, $clientIp;
    
    $body = getBody();
    $id   = (int) ($body['id'] ?? 0);

    if ($id <= 0) {
        jsonError('Invalid or missing "id".');
    }

    $existing = Db::getBanById($pdo, $id);
    if ($existing === null) {
        jsonError('Ban not found.', 404);
    }

    $data = [
        'country' => sanitizeString($body['country'] ?? null),
        'liga'    => sanitizeString($body['liga']    ?? null),
        'home'    => sanitizeString($body['home']    ?? null),
        'away'    => sanitizeString($body['away']    ?? null),
    ];

    // Handle is_active toggle separately
    $isActive = isset($body['is_active']) ? (bool) $body['is_active'] : null;

    try {
        Db::updateBan($pdo, $id, $data);

        if ($isActive !== null) {
            $stmt = $pdo->prepare('UPDATE `bans` SET `is_active`=? WHERE `id`=?');
            $stmt->execute([(int) $isActive, $id]);
        }

        $updated = Db::getBanById($pdo, $id);
        
        // Audit log
        $auditLogger->logAdminAction('update_ban', 'admin', 'ban', $id, $data, $clientIp);
        
        jsonOk($updated);
    } catch (\Throwable $e) {
        $sanitizedError = RequestValidator::sanitizeErrorMessage($e->getMessage());
        jsonError('Failed to update ban: ' . $sanitizedError, 500);
    }
}

function handleDeleteBan(PDO $pdo): never
{
    global $auditLogger, $clientIp;
    
    $body = getBody();
    $id   = (int) ($body['id'] ?? 0);

    if ($id <= 0) {
        jsonError('Invalid or missing "id".');
    }

    $existing = Db::getBanById($pdo, $id);
    if ($existing === null) {
        jsonError('Ban not found.', 404);
    }

    try {
        Db::deleteBan($pdo, $id);
        
        // Audit log
        $auditLogger->logAdminAction('delete_ban', 'admin', 'ban', $id, null, $clientIp);
        
        jsonOk(['deleted_id' => $id]);
    } catch (\Throwable $e) {
        $sanitizedError = RequestValidator::sanitizeErrorMessage($e->getMessage());
        jsonError('Failed to delete ban: ' . $sanitizedError, 500);
    }
}

function handleListMatchesStats(PDO $pdo): never
{
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $status = trim((string) ($_GET['status'] ?? 'all'));
    $query = sanitizeLike($_GET['q'] ?? null);

    $where = [];
    $params = [];

    if ($query !== null) {
        $where[] = '(`home` LIKE :q ESCAPE \'\\\' OR `away` LIKE :q ESCAPE \'\\\' OR `liga` LIKE :q ESCAPE \'\\\')';
        $params[':q'] = '%' . $query . '%';
    }

    if ($status === 'ok') {
        $where[] = '`stats_fetch_status` = \'ok\'';
    } elseif ($status === 'error') {
        $where[] = '`stats_fetch_status` = \'error\'';
    } elseif ($status === 'pending') {
        $where[] = '(`sgi` IS NOT NULL AND `sgi` <> \'\' AND (`stats_fetch_status` IS NULL OR `stats_fetch_status` NOT IN (\'ok\', \'error\') OR `stats_refresh_needed` = 1))';
    } elseif ($status === 'no_sgi') {
        $where[] = '(`sgi` IS NULL OR `sgi` = \'\')';
    }

    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `matches` ' . $whereSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $sql = 'SELECT `id`,`evid`,`sgi`,`country`,`liga`,`home`,`away`,`start_time`,`stats_updated_at`,`stats_fetch_status`,`stats_error`,`stats_source`,`stats_version`,`stats_refresh_needed`,'
        . '`ht_match_goals_1`,`ht_match_goals_2`,`h2h_ht_match_goals_1`,`h2h_ht_match_goals_2` '
        . 'FROM `matches` '
        . $whereSql
        . ' ORDER BY `id` DESC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonOk([
        'rows' => is_array($rows) ? $rows : [],
        'total' => $total,
    ]);
}

function handleGetMatchStats(PDO $pdo): never
{
    $matchId = (int) ($_GET['match_id'] ?? 0);
    if ($matchId <= 0) {
        jsonError('Invalid or missing "match_id".');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM `matches` WHERE `id` = ?'
    );
    $stmt->execute([$matchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        jsonError('Match not found.', 404);
    }

    jsonOk($row);
}

function handleRefreshMatchStats(): never
{
    $body = getBody();
    $matchId = (int) ($body['match_id'] ?? 0);
    if ($matchId <= 0) {
        jsonError('Invalid or missing "match_id".');
    }

    try {
        $service = StatisticServiceFactory::create();
        $result = $service->updateStatistics(1, 0, true, $matchId);
        jsonOk($result + ['match_id' => $matchId]);
    } catch (\Throwable $e) {
        jsonError('Failed to refresh match stats: ' . $e->getMessage(), 500);
    }
}

function handleRefreshStatsBatch(): never
{
    $body = getBody();
    $limit = max(1, min(1000, (int) ($body['limit'] ?? 100)));
    $offset = max(0, (int) ($body['offset'] ?? 0));
    $force = isset($body['force']) ? (bool) $body['force'] : false;

    try {
        $service = StatisticServiceFactory::create();
        $result = $service->updateStatistics($limit, $offset, $force);
        jsonOk($result + ['limit' => $limit, 'offset' => $offset, 'force' => $force]);
    } catch (\Throwable $e) {
        jsonError('Failed to refresh statistics batch: ' . $e->getMessage(), 500);
    }
}

function handleStatsOverview(PDO $pdo): never
{
    $sql = 'SELECT '
        . 'COUNT(*) AS total_matches, '
        . 'SUM(CASE WHEN `sgi` IS NOT NULL AND `sgi` <> \'\' THEN 1 ELSE 0 END) AS with_sgi, '
        . 'SUM(CASE WHEN `stats_fetch_status` = \'ok\' THEN 1 ELSE 0 END) AS stats_ok, '
        . 'SUM(CASE WHEN `stats_fetch_status` = \'error\' THEN 1 ELSE 0 END) AS stats_error, '
        . 'SUM(CASE WHEN `stats_refresh_needed` = 1 THEN 1 ELSE 0 END) AS pending_refresh, '
        . 'SUM(CASE WHEN `sgi` IS NULL OR `sgi` = \'\' THEN 1 ELSE 0 END) AS without_sgi '
        . 'FROM `matches`';
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    jsonOk(is_array($row) ? $row : []);
}
