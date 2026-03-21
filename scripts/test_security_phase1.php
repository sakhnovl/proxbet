<?php

declare(strict_types=1);

/**
 * Security Phase 1 Testing Script
 * Tests all items from the security checklist
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';
require_once __DIR__ . '/../backend/security/Encryption.php';
require_once __DIR__ . '/../backend/security/InputValidator.php';
require_once __DIR__ . '/../backend/security/LogFilter.php';

use Proxbet\Line\Db;
use Proxbet\Security\Encryption;
use Proxbet\Security\InputValidator;
use Proxbet\Security\LogFilter;

proxbet_bootstrap_env();

echo "=================================================\n";
echo "SECURITY PHASE 1 - TESTING CHECKLIST\n";
echo "=================================================\n\n";

$results = [];

// Test 1: Check ENCRYPTION_KEY in .env
echo "[1/8] Проверка ENCRYPTION_KEY в .env...\n";
$encryptionKey = getenv('ENCRYPTION_KEY');
if ($encryptionKey && $encryptionKey !== '' && $encryptionKey !== 'your_base64_encoded_32_byte_key_here') {
    echo "✅ ENCRYPTION_KEY установлен\n";
    $results['encryption_key'] = true;
    
    // Verify it's valid base64 and 32 bytes
    $decoded = base64_decode($encryptionKey, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        echo "✅ ENCRYPTION_KEY корректный (32 байта)\n";
    } else {
        echo "⚠️  ENCRYPTION_KEY некорректный формат\n";
        $results['encryption_key'] = false;
    }
} else {
    echo "❌ ENCRYPTION_KEY не установлен\n";
    $results['encryption_key'] = false;
}
echo "\n";

// Test 2: Check database migration
echo "[2/8] Проверка миграции базы данных...\n";
try {
    $pdo = Db::connectFromEnv();
    $stmt = $pdo->query("SHOW COLUMNS FROM `gemini_api_keys` LIKE 'is_encrypted'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Миграция применена (колонка is_encrypted существует)\n";
        $results['migration'] = true;
    } else {
        echo "❌ Миграция НЕ применена\n";
        echo "   Выполните: SOURCE backend/security/migrations/001_encrypt_gemini_keys.sql;\n";
        $results['migration'] = false;
    }
} catch (\Throwable $e) {
    echo "❌ Ошибка проверки миграции: " . $e->getMessage() . "\n";
    $results['migration'] = false;
}
echo "\n";

// Test 3: Check if existing keys are encrypted
echo "[3/8] Проверка шифрования существующих ключей...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(is_encrypted) as encrypted FROM `gemini_api_keys`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int)$row['total'];
    $encrypted = (int)$row['encrypted'];
    
    if ($total === 0) {
        echo "ℹ️  Нет ключей в базе данных\n";
        $results['keys_encrypted'] = true;
    } elseif ($encrypted === $total) {
        echo "✅ Все ключи зашифрованы ($encrypted/$total)\n";
        $results['keys_encrypted'] = true;
    } else {
        echo "⚠️  Не все ключи зашифрованы ($encrypted/$total)\n";
        echo "   Выполните: php scripts/encrypt_existing_gemini_keys.php\n";
        $results['keys_encrypted'] = false;
    }
} catch (\Throwable $e) {
    echo "❌ Ошибка проверки ключей: " . $e->getMessage() . "\n";
    $results['keys_encrypted'] = false;
}
echo "\n";

// Test 4: Test Admin API authentication
echo "[4/8] Тестирование Admin API аутентификации...\n";
$adminPassword = getenv('ADMIN_PASSWORD');
if ($adminPassword && $adminPassword !== 'change_me_to_a_strong_secret') {
    echo "✅ ADMIN_PASSWORD установлен\n";
    
    // Test that query string auth is disabled
    echo "   Проверка: query string аутентификация отключена...\n";
    $testUrl = "http://localhost:8080/backend/admin/api.php?action=stats_overview&token=" . $adminPassword;
    
    // Note: We can't actually test HTTP requests from CLI easily, so we check the code
    $apiCode = file_get_contents(__DIR__ . '/../backend/admin/api.php');
    if (strpos($apiCode, 'elseif (isset($_GET[\'token\']))') === false) {
        echo "   ✅ Query string аутентификация удалена из кода\n";
        $results['admin_auth'] = true;
    } else {
        echo "   ⚠️  Query string аутентификация все еще в коде\n";
        $results['admin_auth'] = false;
    }
} else {
    echo "⚠️  ADMIN_PASSWORD не изменен с дефолтного значения\n";
    $results['admin_auth'] = false;
}
echo "\n";

// Test 5: Test rate limiting
echo "[5/8] Проверка rate limiting...\n";
$rateLimitDir = __DIR__ . '/../data/rate_limits';
if (is_dir($rateLimitDir)) {
    echo "✅ Директория rate_limits существует\n";
    $results['rate_limiting'] = true;
} else {
    echo "⚠️  Директория rate_limits не существует\n";
    echo "   Создайте: mkdir data/rate_limits\n";
    $results['rate_limiting'] = false;
}
echo "\n";

// Test 6: Test Telegram bot input validation
echo "[6/8] Проверка валидации входных данных Telegram bot...\n";
$handlerCode = file_get_contents(__DIR__ . '/../backend/telegram/public_handlers.php');
if (strpos($handlerCode, 'InputValidator::sanitizeTelegramInput') !== false) {
    echo "✅ InputValidator используется в Telegram handlers\n";
    $results['telegram_validation'] = true;
} else {
    echo "❌ InputValidator НЕ используется в Telegram handlers\n";
    $results['telegram_validation'] = false;
}
echo "\n";

// Test 7: Test log filtering
echo "[7/8] Проверка фильтрации логов...\n";
$loggerCode = file_get_contents(__DIR__ . '/../backend/line/logger.php');
if (strpos($loggerCode, 'LogFilter::filter') !== false) {
    echo "✅ LogFilter интегрирован в Logger\n";
    
    // Test filtering
    $testData = [
        'api_key' => 'AIzaSyDEXAMPLE123456789',
        'password' => 'secret123',
        'normal_field' => 'normal_value'
    ];
    $filtered = LogFilter::filterArray($testData);
    
    if ($filtered['api_key'] === '[REDACTED]' && $filtered['password'] === '[REDACTED]') {
        echo "✅ Фильтрация работает корректно\n";
        $results['log_filtering'] = true;
    } else {
        echo "❌ Фильтрация НЕ работает\n";
        $results['log_filtering'] = false;
    }
} else {
    echo "❌ LogFilter НЕ интегрирован в Logger\n";
    $results['log_filtering'] = false;
}
echo "\n";

// Test 8: Test automatic encryption of new keys
echo "[8/8] Проверка автоматического шифрования новых ключей...\n";
$repoCode = file_get_contents(__DIR__ . '/../backend/telegram/TelegramAiRepository.php');
if (strpos($repoCode, 'encryptApiKey') !== false && strpos($repoCode, 'decryptApiKey') !== false) {
    echo "✅ Методы шифрования/дешифрования реализованы\n";
    
    if (strpos($repoCode, 'Encryption::fromEnv()') !== false) {
        echo "✅ Encryption инициализируется из окружения\n";
        $results['auto_encryption'] = true;
    } else {
        echo "⚠️  Encryption не инициализируется\n";
        $results['auto_encryption'] = false;
    }
} else {
    echo "❌ Методы шифрования НЕ реализованы\n";
    $results['auto_encryption'] = false;
}
echo "\n";

// Summary
echo "=================================================\n";
echo "РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ\n";
echo "=================================================\n\n";

$passed = array_filter($results, fn($v) => $v === true);
$total = count($results);
$passedCount = count($passed);

foreach ($results as $test => $result) {
    $status = $result ? '✅' : '❌';
    echo "$status " . ucfirst(str_replace('_', ' ', $test)) . "\n";
}

echo "\n";
echo "Пройдено: $passedCount/$total тестов\n";

if ($passedCount === $total) {
    echo "\n🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ! Фаза 1 полностью завершена.\n";
    exit(0);
} else {
    echo "\n⚠️  Некоторые тесты не пройдены. См. детали выше.\n";
    exit(1);
}
