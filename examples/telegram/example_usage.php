<?php

declare(strict_types=1);

/**
 * Примеры использования модуля Telegram AI Analysis
 * 
 * Этот файл демонстрирует, как интегрировать новые возможности
 * в существующий код telegram_bot.php
 */

require_once __DIR__ . '/../../backend/line/db.php';
require_once __DIR__ . '/../../backend/telegram/TelegramAiRepository.php';
require_once __DIR__ . '/../../backend/telegram/GeminiMatchAnalyzer.php';
require_once __DIR__ . '/../../backend/telegram/GeminiPoolAnalyzer.php';
require_once __DIR__ . '/../../backend/telegram/AnalysisCache.php';
require_once __DIR__ . '/../../backend/telegram/AnalysisMetrics.php';
require_once __DIR__ . '/../../backend/telegram/RateLimiter.php';

use Proxbet\Line\Db;
use Proxbet\Telegram\TelegramAiRepository;
use Proxbet\Telegram\GeminiPoolAnalyzer;
use Proxbet\Telegram\AnalysisCache;
use Proxbet\Telegram\AnalysisMetrics;
use Proxbet\Telegram\RateLimiter;

// ============================================================================
// ПРИМЕР 1: Использование кэша для повторных запросов
// ============================================================================

function analyzeWithCache(int $matchId, int $algorithmId, int $telegramUserId): string
{
    $db = Db::connectFromEnv();
    $cache = new AnalysisCache($db, (int) (getenv('AI_CACHE_TTL') ?: 3600));
    
    // Проверяем кэш
    $cached = $cache->get($matchId, $algorithmId);
    if ($cached !== null) {
        echo "✓ Использован кэш для матча #{$matchId}\n";
        return $cached['response'];
    }
    
    // Если нет в кэше - делаем запрос к AI
    $repository = new TelegramAiRepository($db);
    $context = $repository->getAnalysisContext($matchId, $algorithmId);
    
    if ($context === null) {
        throw new \RuntimeException('Match not found');
    }
    
    $poolAnalyzer = new GeminiPoolAnalyzer($repository);
    $analysis = $poolAnalyzer->analyze($context);
    
    // Сохраняем в кэш
    $cache->set($matchId, $algorithmId, $analysis['response']);
    
    return $analysis['response'];
}

// ============================================================================
// ПРИМЕР 2: Rate limiting перед обработкой запроса
// ============================================================================

function handleAnalysisRequestWithRateLimit(int $telegramUserId, int $matchId): array
{
    $db = Db::connectFromEnv();
    $rateLimiter = new RateLimiter(
        $db,
        (int) (getenv('AI_RATE_LIMIT_PER_USER') ?: 10),
        (int) (getenv('AI_RATE_LIMIT_WINDOW') ?: 3600)
    );
    
    // Проверяем rate limit
    $limitStatus = $rateLimiter->check($telegramUserId);
    
    if (!$limitStatus['allowed']) {
        return [
            'success' => false,
            'error' => 'Rate limit exceeded',
            'message' => $rateLimiter->getInfo($telegramUserId),
        ];
    }
    
    // Продолжаем обработку...
    return [
        'success' => true,
        'remaining' => $limitStatus['limit'] - $limitStatus['current'] - 1,
    ];
}

// ============================================================================
// ПРИМЕР 3: Сбор метрик для каждого запроса
// ============================================================================

function analyzeWithMetrics(int $telegramUserId, int $matchId, int $algorithmId): array
{
    $db = Db::connectFromEnv();
    $metrics = new AnalysisMetrics($db);
    $repository = new TelegramAiRepository($db);
    
    $startTime = microtime(true);
    $success = false;
    $errorType = null;
    $provider = 'gemini';
    $model = 'unknown';
    
    try {
        $context = $repository->getAnalysisContext($matchId, $algorithmId);
        if ($context === null) {
            throw new \RuntimeException('Match not found');
        }
        
        $poolAnalyzer = new GeminiPoolAnalyzer($repository);
        $analysis = $poolAnalyzer->analyze($context);
        
        $success = true;
        $provider = $analysis['provider'];
        $model = $analysis['model'];
        
        return [
            'success' => true,
            'response' => $analysis['response'],
        ];
        
    } catch (\Throwable $e) {
        $errorType = get_class($e);
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
        
    } finally {
        // Всегда записываем метрику
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
        $metrics->track(
            $telegramUserId,
            $matchId,
            $algorithmId,
            $provider,
            $model,
            $success,
            $responseTimeMs,
            $errorType
        );
    }
}

// ============================================================================
// ПРИМЕР 4: Полная интеграция всех компонентов
// ============================================================================

function handleAnalysisRequestComplete(
    int $telegramUserId,
    int $matchId,
    int $algorithmId
): array {
    $db = Db::connectFromEnv();
    
    // 1. Проверка rate limit
    $rateLimiter = new RateLimiter($db, 10, 3600);
    $limitStatus = $rateLimiter->check($telegramUserId);
    
    if (!$limitStatus['allowed']) {
        return [
            'success' => false,
            'error' => 'rate_limit',
            'message' => $rateLimiter->getInfo($telegramUserId),
        ];
    }
    
    // 2. Проверка кэша
    $cache = new AnalysisCache($db, 3600);
    $cached = $cache->get($matchId, $algorithmId);
    
    if ($cached !== null) {
        return [
            'success' => true,
            'response' => $cached['response'],
            'cached' => true,
            'cached_at' => $cached['created_at'],
        ];
    }
    
    // 3. Выполнение анализа с метриками
    $metrics = new AnalysisMetrics($db);
    $repository = new TelegramAiRepository($db);
    
    $startTime = microtime(true);
    $success = false;
    $errorType = null;
    $provider = 'gemini';
    $model = 'unknown';
    $response = null;
    
    try {
        $context = $repository->getAnalysisContext($matchId, $algorithmId);
        if ($context === null) {
            throw new \RuntimeException('Match not found');
        }
        
        $poolAnalyzer = new GeminiPoolAnalyzer($repository);
        $analysis = $poolAnalyzer->analyze($context);
        
        $success = true;
        $provider = $analysis['provider'];
        $model = $analysis['model'];
        $response = $analysis['response'];
        
        // 4. Сохранение в кэш
        $cache->set($matchId, $algorithmId, $response);
        
        return [
            'success' => true,
            'response' => $response,
            'cached' => false,
            'provider' => $provider,
            'model' => $model,
        ];
        
    } catch (\Throwable $e) {
        $errorType = get_class($e);
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_type' => $errorType,
        ];
        
    } finally {
        // 5. Запись метрики
        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
        $metrics->track(
            $telegramUserId,
            $matchId,
            $algorithmId,
            $provider,
            $model,
            $success,
            $responseTimeMs,
            $errorType
        );
    }
}

// ============================================================================
// ПРИМЕР 5: Получение статистики для админ-панели
// ============================================================================

function getAdminDashboardStats(): array
{
    $db = Db::connectFromEnv();
    $metrics = new AnalysisMetrics($db);
    $cache = new AnalysisCache($db);
    $rateLimiter = new RateLimiter($db);
    
    return [
        'overall' => $metrics->getOverallStats(7),
        'by_algorithm' => $metrics->getAlgorithmStats(7),
        'by_model' => $metrics->getModelStats(7),
        'top_users' => $metrics->getTopUsers(10, 7),
        'errors' => $metrics->getErrorDistribution(7),
        'cache' => $cache->getStats(),
        'rate_limit' => $rateLimiter->getStats(),
        'blocked_users' => $rateLimiter->getBlockedUsers(10),
    ];
}

// ============================================================================
// ПРИМЕР 6: Очистка старых данных (cron job)
// ============================================================================

function cleanupOldData(): array
{
    $db = Db::connectFromEnv();
    
    $cache = new AnalysisCache($db);
    $cacheDeleted = $cache->cleanup();
    
    $metrics = new AnalysisMetrics($db);
    $metricsDeleted = $metrics->cleanup(30);
    
    return [
        'cache_deleted' => $cacheDeleted,
        'metrics_deleted' => $metricsDeleted,
    ];
}

// ============================================================================
// Примеры вызова
// ============================================================================

if (php_sapi_name() === 'cli') {
    echo "=== Примеры использования Telegram AI Module ===\n\n";
    
    // Раскомментируйте для тестирования:
    
    // $result = handleAnalysisRequestComplete(123456789, 1, 1);
    // print_r($result);
    
    // $stats = getAdminDashboardStats();
    // print_r($stats);
    
    // $cleanup = cleanupOldData();
    // print_r($cleanup);
}
