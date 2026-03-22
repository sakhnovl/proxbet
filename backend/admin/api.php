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
 * Auth: Bearer token in Authorization header only.
 * Canonical secret: ADMIN_API_TOKEN (legacy fallback: ADMIN_PASSWORD).
 */

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../bootstrap/runtime.php';
require_once __DIR__ . '/../bootstrap/http.php';
require_once __DIR__ . '/../security/RateLimiter.php';
require_once __DIR__ . '/../security/InputValidator.php';
require_once __DIR__ . '/../security/RequestValidator.php';
require_once __DIR__ . '/../security/AuditLogger.php';
require_once __DIR__ . '/AdminAuthenticator.php';
require_once __DIR__ . '/Handlers/BanHandler.php';
require_once __DIR__ . '/Handlers/StatsHandler.php';

use Proxbet\Admin\AdminAuthenticator;
use Proxbet\Admin\Handlers\BanHandler;
use Proxbet\Admin\Handlers\StatsHandler;
use Proxbet\Line\Db;
use Proxbet\Security\AuditLogger;
use Proxbet\Security\InputValidator;
use Proxbet\Security\RateLimiter;
use Proxbet\Security\RequestValidator;

proxbet_bootstrap_http_endpoint(['GET', 'POST', 'OPTIONS'], ['Authorization', 'Content-Type']);

function admin_sanitize_like(?string $value): ?string
{
    return InputValidator::sanitizeLike($value);
}

$rateLimiter = new RateLimiter(
    __DIR__ . '/../../data/rate_limits',
    maxAttempts: 20,
    windowSeconds: 60
);

$clientIp = proxbet_get_client_ip();
if (!$rateLimiter->check('admin_api:' . $clientIp)) {
    header('Retry-After: 60');
    proxbet_json_error('Too many requests. Please try again later.', 429);
}

try {
    proxbet_require_env(['DB_HOST', 'DB_USER', 'DB_NAME']);
} catch (Throwable $e) {
    proxbet_json_error('Server misconfiguration: ' . $e->getMessage(), 500);
}

try {
    $pdo = Db::connectFromEnv();
    $auditLogger = new AuditLogger($pdo);
} catch (Throwable $e) {
    proxbet_json_error(
        'Database connection failed: ' . RequestValidator::sanitizeErrorMessage($e->getMessage()),
        503
    );
}

try {
    $authenticator = new AdminAuthenticator($auditLogger, $clientIp);
    $authenticator->authenticate(proxbet_extract_bearer_token());
} catch (Throwable $e) {
    proxbet_json_error(RequestValidator::sanitizeErrorMessage($e->getMessage()), 401);
}

$banHandler = new BanHandler($pdo, $auditLogger, $clientIp);
$statsHandler = new StatsHandler($pdo);
$action = trim((string) ($_GET['action'] ?? ''));
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

match (true) {
    $action === 'list_bans' && $method === 'GET' => admin_handle_list_bans($banHandler),
    $action === 'add_ban' && $method === 'POST' => admin_handle_add_ban($banHandler),
    $action === 'update_ban' && $method === 'POST' => admin_handle_update_ban($banHandler),
    $action === 'delete_ban' && $method === 'POST' => admin_handle_delete_ban($banHandler),
    $action === 'list_matches_stats' && $method === 'GET' => admin_handle_list_matches_stats($statsHandler),
    $action === 'get_match_stats' && $method === 'GET' => admin_handle_get_match_stats($statsHandler),
    $action === 'refresh_match_stats' && $method === 'POST' => admin_handle_refresh_match_stats($statsHandler),
    $action === 'refresh_stats_batch' && $method === 'POST' => admin_handle_refresh_stats_batch($statsHandler),
    $action === 'stats_overview' && $method === 'GET' => admin_handle_stats_overview($statsHandler),
    default => proxbet_json_error('Unknown action or method.', 404),
};

function admin_handle_list_bans(BanHandler $handler): never
{
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    proxbet_json_ok($handler->list($limit, $offset));
}

function admin_handle_add_ban(BanHandler $handler): never
{
    try {
        proxbet_json_ok($handler->add(proxbet_read_json_body()));
    } catch (Throwable $e) {
        admin_fail_write_operation('Failed to add ban', $e);
    }
}

function admin_handle_update_ban(BanHandler $handler): never
{
    $body = proxbet_read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        proxbet_json_error('Invalid or missing "id".');
    }

    try {
        proxbet_json_ok($handler->update($id, $body));
    } catch (Throwable $e) {
        admin_fail_write_operation('Failed to update ban', $e);
    }
}

function admin_handle_delete_ban(BanHandler $handler): never
{
    $body = proxbet_read_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        proxbet_json_error('Invalid or missing "id".');
    }

    try {
        proxbet_json_ok($handler->delete($id));
    } catch (Throwable $e) {
        admin_fail_write_operation('Failed to delete ban', $e);
    }
}

function admin_handle_list_matches_stats(StatsHandler $handler): never
{
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $status = trim((string) ($_GET['status'] ?? 'all'));
    $query = admin_sanitize_like($_GET['q'] ?? null);

    proxbet_json_ok($handler->listMatches($limit, $offset, $status, $query));
}

function admin_handle_get_match_stats(StatsHandler $handler): never
{
    $matchId = (int) ($_GET['match_id'] ?? 0);
    if ($matchId <= 0) {
        proxbet_json_error('Invalid or missing "match_id".');
    }

    try {
        proxbet_json_ok($handler->getMatch($matchId));
    } catch (Throwable $e) {
        proxbet_json_error(RequestValidator::sanitizeErrorMessage($e->getMessage()), 404);
    }
}

function admin_handle_refresh_match_stats(StatsHandler $handler): never
{
    $body = proxbet_read_json_body();
    $matchId = (int) ($body['match_id'] ?? 0);
    if ($matchId <= 0) {
        proxbet_json_error('Invalid or missing "match_id".');
    }

    try {
        proxbet_json_ok($handler->refreshMatch($matchId));
    } catch (Throwable $e) {
        proxbet_json_error(
            'Failed to refresh match stats: ' . RequestValidator::sanitizeErrorMessage($e->getMessage()),
            500
        );
    }
}

function admin_handle_refresh_stats_batch(StatsHandler $handler): never
{
    $body = proxbet_read_json_body();
    $limit = max(1, min(1000, (int) ($body['limit'] ?? 100)));
    $offset = max(0, (int) ($body['offset'] ?? 0));
    $force = isset($body['force']) ? (bool) $body['force'] : false;

    try {
        proxbet_json_ok($handler->refreshBatch($limit, $offset, $force));
    } catch (Throwable $e) {
        proxbet_json_error(
            'Failed to refresh statistics batch: ' . RequestValidator::sanitizeErrorMessage($e->getMessage()),
            500
        );
    }
}

function admin_handle_stats_overview(StatsHandler $handler): never
{
    proxbet_json_ok($handler->getOverview());
}

function admin_fail_write_operation(string $prefix, Throwable $e): never
{
    $message = RequestValidator::sanitizeErrorMessage($e->getMessage());
    $status = str_contains(strtolower($message), 'not found') ? 404 : 500;

    proxbet_json_error($prefix . ': ' . $message, $status);
}
