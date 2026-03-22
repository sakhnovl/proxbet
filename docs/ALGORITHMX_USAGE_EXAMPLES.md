# AlgorithmX: Примеры использования

## Содержание

1. [Базовое использование](#базовое-использование)
2. [Интеграция в Scanner](#интеграция-в-scanner)
3. [Примеры сценариев](#примеры-сценариев)
4. [Калибровка параметров](#калибровка-параметров)
5. [Мониторинг и отладка](#мониторинг-и-отладка)

---

## Базовое использование

### Создание экземпляра алгоритма

```php
<?php

use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

// Создание зависимостей
$config = new Config();
$extractor = new DataExtractor();
$validator = new DataValidator();

// Создание калькуляторов
$aisCalculator = new AisCalculator();
$modifierCalculator = new ModifierCalculator();
$interpretationGenerator = new InterpretationGenerator();

$probabilityCalculator = new ProbabilityCalculator(
    $aisCalculator,
    $modifierCalculator,
    $interpretationGenerator
);

// Создание фильтра решений
$decisionFilter = new DecisionFilter();

// Создание экземпляра алгоритма
$algorithmX = new AlgorithmX(
    $config,
    $extractor,
    $validator,
    $probabilityCalculator,
    $decisionFilter
);
```

### Анализ матча

```php
<?php

// Данные матча из БД
$matchData = [
    'id' => 12345,
    'home' => 'Manchester City',
    'away' => 'Liverpool',
    'time' => '28:15',
    'live_ht_hscore' => 0,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 14,
    'live_danger_att_away' => 6,
    'live_shots_on_target_home' => 3,
    'live_shots_on_target_away' => 1,
    'live_shots_off_target_home' => 4,
    'live_shots_off_target_away' => 2,
    'live_corner_home' => 4,
    'live_corner_away' => 1,
    'match_status' => '1st Half',
];

// Анализ
$result = $algorithmX->analyze(['live_data' => $matchData]);

// Результат
if ($result['bet']) {
    echo "✅ Рекомендуется ставка!\n";
    echo "Вероятность: " . round($result['confidence'] * 100, 1) . "%\n";
    echo "Причина: {$result['reason']}\n";
    
    // Debug информация
    $debug = $result['debug'];
    echo "\nДетали:\n";
    echo "- AIS Rate: {$debug['ais_rate']}\n";
    echo "- Базовая вероятность: " . round($debug['base_prob'] * 100, 1) . "%\n";
    echo "- Финальная вероятность: " . round($debug['prob_final'] * 100, 1) . "%\n";
    echo "- Интерпретация: {$debug['interpretation']}\n";
} else {
    echo "❌ Ставка не рекомендуется\n";
    echo "Причина: {$result['reason']}\n";
}
```

---

## Интеграция в Scanner

### Добавление в AlgorithmFactory

```php
<?php
// backend/scanner/AlgorithmFactory.php

namespace Proxbet\Scanner;

use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataExtractor;
use Proxbet\Scanner\Algorithms\AlgorithmX\DataValidator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ModifierCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\InterpretationGenerator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmX\Filters\DecisionFilter;

class AlgorithmFactory
{
    public function create(int $algorithmId): AlgorithmInterface
    {
        return match ($algorithmId) {
            1 => $this->createAlgorithmOne(),
            2 => new AlgorithmTwo($this->filter),
            3 => new AlgorithmThree($this->filter),
            4 => $this->createAlgorithmX(),  // AlgorithmX
            default => throw new \InvalidArgumentException("Unknown algorithm ID: {$algorithmId}"),
        };
    }
    
    private function createAlgorithmX(): AlgorithmX
    {
        $config = new Config();
        $extractor = new DataExtractor();
        $validator = new DataValidator();
        
        $aisCalculator = new AisCalculator();
        $modifierCalculator = new ModifierCalculator();
        $interpretationGenerator = new InterpretationGenerator();
        
        $probabilityCalculator = new ProbabilityCalculator(
            $aisCalculator,
            $modifierCalculator,
            $interpretationGenerator
        );
        
        $decisionFilter = new DecisionFilter();
        
        return new AlgorithmX(
            $config,
            $extractor,
            $validator,
            $probabilityCalculator,
            $decisionFilter
        );
    }
}
```

### Использование в Scanner

```php
<?php
// backend/scanner/Scanner.php

public function scanMatch(array $match): array
{
    $results = [];
    
    // ... Algorithm 1, 2, 3 ...
    
    // AlgorithmX (ID=4)
    if (Config::isEnabled()) {
        $algorithmX = $this->factory->create(4);
        $algorithmXData = $this->extractor->extractAlgorithmXData($match);
        $algorithmXDecision = $algorithmX->analyze([
            'live_data' => $algorithmXData,
        ]);
        
        $results[] = $this->formatter->formatAlgorithmX(
            $this->extractor->extractBase($match),
            $this->extractor->extractLiveData($match),
            $algorithmXData,
            $algorithmXDecision
        );
    }
    
    return $results;
}
```

---

## Примеры сценариев

### Сценарий 1: Высокая активность, ничья

```php
<?php

$matchData = [
    'time' => '28:00',
    'live_ht_hscore' => 0,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 14,
    'live_danger_att_away' => 6,
    'live_shots_on_target_home' => 3,
    'live_shots_on_target_away' => 1,
    'live_shots_off_target_home' => 4,
    'live_shots_off_target_away' => 2,
    'live_corner_home' => 4,
    'live_corner_away' => 1,
    'match_status' => '1st Half',
];

$result = $algorithmX->analyze(['live_data' => $matchData]);

// Ожидаемый результат:
// AIS_home = 14*0.4 + 7*0.3 + 3*0.2 + 4*0.1 = 8.7
// AIS_away = 6*0.4 + 3*0.3 + 1*0.2 + 1*0.1 = 3.6
// AIS_total = 12.3
// AIS_rate = 12.3 / 28 = 0.439
// base_prob ≈ 3.2% (низкая активность)
// bet = false
```

### Сценарий 2: Очень высокая активность

```php
<?php

$matchData = [
    'time' => '20:00',
    'live_ht_hscore' => 1,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 25,
    'live_danger_att_away' => 18,
    'live_shots_on_target_home' => 6,
    'live_shots_on_target_away' => 4,
    'live_shots_off_target_home' => 6,
    'live_shots_off_target_away' => 4,
    'live_corner_home' => 5,
    'live_corner_away' => 3,
    'match_status' => '1st Half',
];

$result = $algorithmX->analyze(['live_data' => $matchData]);

// Ожидаемый результат:
// AIS_total = 28.8
// AIS_rate = 28.8 / 20 = 1.44
// base_prob ≈ 40%
// time_factor = 25/45 = 0.556
// prob_adjusted ≈ 29%
// score_modifier = 1.10 (разница 1 гол)
// prob_final ≈ 32%
// bet = зависит от дополнительных проверок
```

### Сценарий 3: Сухой период

```php
<?php

$matchData = [
    'time' => '35:00',
    'live_ht_hscore' => 0,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 20,
    'live_danger_att_away' => 15,
    'live_shots_on_target_home' => 3,
    'live_shots_on_target_away' => 2,
    'live_shots_off_target_home' => 5,
    'live_shots_off_target_away' => 4,
    'live_corner_home' => 4,
    'live_corner_away' => 2,
    'match_status' => '1st Half',
];

$result = $algorithmX->analyze(['live_data' => $matchData]);

// Применяется модификатор сухого периода (0.92)
// prob_final *= 0.92
```

### Сценарий 4: Конец первого тайма

```php
<?php

$matchData = [
    'time' => '43:00',
    'live_ht_hscore' => 0,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 30,
    'live_danger_att_away' => 25,
    'live_shots_on_target_home' => 8,
    'live_shots_on_target_away' => 6,
    'live_shots_off_target_home' => 10,
    'live_shots_off_target_away' => 8,
    'live_corner_home' => 7,
    'live_corner_away' => 5,
    'match_status' => '1st Half',
];

$result = $algorithmX->analyze(['live_data' => $matchData]);

// time_remaining = 2 минуты
// time_factor = 2/45 = 0.044
// Вероятность значительно снижается из-за малого оставшегося времени
```

---

## Калибровка параметров

### Сбор исторических данных

```php
<?php
// scripts/algorithmx_collect_historical_data.php

require_once __DIR__ . '/../backend/bootstrap/autoload.php';

use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;

$db = Db::connectFromEnv();

// Выбрать завершенные матчи первого тайма
$stmt = $db->query("
    SELECT * FROM matches 
    WHERE match_status = 'Half Time' 
    AND live_updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    LIMIT 1000
");

$data = [];

while ($match = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Для каждой минуты от 5 до 44
    for ($minute = 5; $minute <= 44; $minute++) {
        // Симулировать состояние на этой минуте
        $liveData = simulateMatchStateAtMinute($match, $minute);
        
        // Получить предсказание
        $result = $algorithmX->analyze(['live_data' => $liveData]);
        
        // Определить фактический исход
        $actualGoal = wasGoalScoredAfterMinute($match, $minute);
        
        $data[] = [
            'match_id' => $match['id'],
            'minute' => $minute,
            'predicted_prob' => $result['confidence'],
            'actual_outcome' => $actualGoal ? 1 : 0,
            'ais_rate' => $result['debug']['ais_rate'],
        ];
    }
}

// Сохранить данные
file_put_contents('calibration_data.json', json_encode($data, JSON_PRETTY_PRINT));
```

### Оптимизация параметров

```php
<?php
// scripts/algorithmx_calibrate_parameters.php

$data = json_decode(file_get_contents('calibration_data.json'), true);

// Grid search для k и threshold
$bestBrierScore = PHP_FLOAT_MAX;
$bestParams = null;

foreach (range(1.5, 4.0, 0.5) as $k) {
    foreach (range(1.0, 2.5, 0.2) as $threshold) {
        $predictions = [];
        $actuals = [];
        
        foreach ($data as $row) {
            // Пересчитать вероятность с новыми параметрами
            $aisRate = $row['ais_rate'];
            $prob = 1 / (1 + exp(-$k * ($aisRate - $threshold)));
            
            $predictions[] = $prob;
            $actuals[] = $row['actual_outcome'];
        }
        
        // Рассчитать Brier Score
        $brierScore = calculateBrierScore($predictions, $actuals);
        
        if ($brierScore < $bestBrierScore) {
            $bestBrierScore = $brierScore;
            $bestParams = ['k' => $k, 'threshold' => $threshold];
        }
    }
}

echo "Лучшие параметры:\n";
echo "k = {$bestParams['k']}\n";
echo "threshold = {$bestParams['threshold']}\n";
echo "Brier Score = {$bestBrierScore}\n";

function calculateBrierScore(array $predictions, array $actuals): float
{
    $sum = 0;
    $n = count($predictions);
    
    for ($i = 0; $i < $n; $i++) {
        $sum += pow($predictions[$i] - $actuals[$i], 2);
    }
    
    return $sum / $n;
}
```

### Валидация результатов

```php
<?php
// scripts/algorithmx_report_calibration.php

$data = json_decode(file_get_contents('calibration_data.json'), true);

// Разделить на бины по вероятности
$bins = array_fill(0, 10, ['predicted' => 0, 'actual' => 0, 'count' => 0]);

foreach ($data as $row) {
    $binIndex = min(9, floor($row['predicted_prob'] * 10));
    $bins[$binIndex]['predicted'] += $row['predicted_prob'];
    $bins[$binIndex]['actual'] += $row['actual_outcome'];
    $bins[$binIndex]['count']++;
}

// Calibration plot
echo "Calibration Plot:\n";
echo "Bin\tPredicted\tActual\t\tCount\n";

foreach ($bins as $i => $bin) {
    if ($bin['count'] > 0) {
        $avgPred = $bin['predicted'] / $bin['count'];
        $avgActual = $bin['actual'] / $bin['count'];
        
        echo sprintf(
            "%d-%d%%\t%.3f\t\t%.3f\t\t%d\n",
            $i * 10,
            ($i + 1) * 10,
            $avgPred,
            $avgActual,
            $bin['count']
        );
    }
}

// ROC-AUC
$rocAuc = calculateRocAuc($data);
echo "\nROC-AUC: " . round($rocAuc, 3) . "\n";
```

---

## Мониторинг и отладка

### Логирование решений

```php
<?php

use Psr\Log\LoggerInterface;

class AlgorithmXLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }
    
    public function logDecision(array $match, array $result): void
    {
        $this->logger->info('AlgorithmX decision', [
            'match_id' => $match['id'],
            'home' => $match['home'],
            'away' => $match['away'],
            'minute' => $match['time'],
            'bet' => $result['bet'],
            'probability' => $result['confidence'],
            'reason' => $result['reason'],
            'ais_rate' => $result['debug']['ais_rate'],
            'base_prob' => $result['debug']['base_prob'],
            'final_prob' => $result['debug']['prob_final'],
        ]);
    }
}
```

### Метрики для мониторинга

```php
<?php

class AlgorithmXMetrics
{
    private array $decisions = [];
    
    public function recordDecision(array $result): void
    {
        $this->decisions[] = [
            'bet' => $result['bet'],
            'probability' => $result['confidence'],
            'timestamp' => time(),
        ];
    }
    
    public function getStats(): array
    {
        $total = count($this->decisions);
        $betTrue = count(array_filter($this->decisions, fn($d) => $d['bet']));
        
        $probabilities = array_column($this->decisions, 'probability');
        
        return [
            'total_analyzed' => $total,
            'bet_rate' => $total > 0 ? $betTrue / $total : 0,
            'avg_probability' => $total > 0 ? array_sum($probabilities) / $total : 0,
            'min_probability' => $total > 0 ? min($probabilities) : 0,
            'max_probability' => $total > 0 ? max($probabilities) : 0,
        ];
    }
}
```

### Отладочный вывод

```php
<?php

function debugAlgorithmX(array $result): void
{
    echo "=== AlgorithmX Debug ===\n";
    echo "Decision: " . ($result['bet'] ? 'BET' : 'NO BET') . "\n";
    echo "Confidence: " . round($result['confidence'] * 100, 1) . "%\n";
    echo "Reason: {$result['reason']}\n\n";
    
    $d = $result['debug'];
    echo "AIS Breakdown:\n";
    echo "  Home: {$d['ais_home']}\n";
    echo "  Away: {$d['ais_away']}\n";
    echo "  Total: {$d['ais_total']}\n";
    echo "  Rate: {$d['ais_rate']}\n\n";
    
    echo "Probability Calculation:\n";
    echo "  Base: " . round($d['base_prob'] * 100, 1) . "%\n";
    echo "  After time factor: " . round($d['prob_adjusted'] * 100, 1) . "%\n";
    echo "  Score modifier: {$d['score_modifier']}\n";
    echo "  Dry period: " . ($d['dry_period_applied'] ? 'YES' : 'NO') . "\n";
    echo "  Final: " . round($d['prob_final'] * 100, 1) . "%\n\n";
    
    echo "Interpretation: {$d['interpretation']}\n";
    echo "========================\n";
}
```

---

## Конфигурация через .env

```bash
# Включить/выключить алгоритм
ALGORITHMX_ENABLED=true

# Параметры сигмоиды (для калибровки)
ALGORITHMX_SIGMOID_K=2.5
ALGORITHMX_SIGMOID_THRESHOLD=1.8

# Пороги принятия решений
ALGORITHMX_DECISION_THRESHOLD_HIGH=0.60
ALGORITHMX_DECISION_THRESHOLD_LOW=0.20

# Временные ограничения
ALGORITHMX_MIN_MINUTE=5
ALGORITHMX_MAX_MINUTE=45
```

---

## Тестирование

### Unit-тесты

```bash
# Запустить все тесты AlgorithmX
vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmX/tests/

# Только калькуляторы
vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmX/tests/Calculators/

# Интеграционные тесты
vendor/bin/phpunit backend/scanner/Algorithms/AlgorithmX/tests/Integration/
```

### Smoke test

```php
<?php
// scripts/test/test_algorithmx.php

require_once __DIR__ . '/../../backend/bootstrap/autoload.php';

use Proxbet\Scanner\AlgorithmFactory;

$factory = new AlgorithmFactory(/* ... */);
$algorithmX = $factory->create(4);

$testMatch = [
    'time' => '25:00',
    'live_ht_hscore' => 0,
    'live_ht_ascore' => 0,
    'live_danger_att_home' => 20,
    'live_danger_att_away' => 15,
    'live_shots_on_target_home' => 4,
    'live_shots_on_target_away' => 3,
    'live_shots_off_target_home' => 6,
    'live_shots_off_target_away' => 4,
    'live_corner_home' => 5,
    'live_corner_away' => 3,
    'match_status' => '1st Half',
];

$result = $algorithmX->analyze(['live_data' => $testMatch]);

assert($result['bet'] !== null, 'Decision must be boolean');
assert($result['confidence'] >= 0 && $result['confidence'] <= 1, 'Confidence must be 0-1');
assert(!empty($result['reason']), 'Reason must not be empty');
assert(isset($result['debug']['ais_rate']), 'Debug must contain ais_rate');

echo "✅ AlgorithmX smoke test passed!\n";
```

---

## Дополнительные ресурсы

- **Документация алгоритма**: `backend/scanner/Algorithms/AlgorithmX/README.md`
- **Спецификация**: `docs/goal_probability_agent_prompt.md`
- **Калибровка**: `backend/scanner/Algorithms/AlgorithmX/CALIBRATION.md`
- **Тесты**: `backend/scanner/Algorithms/AlgorithmX/tests/`
