# Сканер вероятности гола в первом тайме

Система анализирует live-матчи и ищет сигналы на гол в первом тайме по трём группам данных:

- Форма команд за последние 5 матчей: вес 40%
- Head-to-Head за последние 5 встреч: вес 20%
- Текущая live-статистика матча: вес 40%

## Состав

- `DataExtractor.php` — извлечение и нормализация данных из БД
- `ProbabilityCalculator.php` — расчёт form/H2H/live score и итоговой вероятности
- `MatchFilter.php` — фильтрация матчей по правилам сигнала
- `Scanner.php` — оркестрация анализа
- `ScannerCli.php` — CLI-интерфейс
- `TelegramNotifier.php` — уведомления в Telegram с защитой от дублей

## Запуск

Базовый запуск:

```bash
php ScannerCli.php
```

JSON-вывод:

```bash
php ScannerCli.php --json
```

Подробный режим:

```bash
php ScannerCli.php --verbose
```

Без Telegram:

```bash
php ScannerCli.php --no-telegram
```

Переопределение минимального порога вероятности:

```bash
php ScannerCli.php --min-probability=0.70
```

`--min-probability` принимает значение от `0` до `1` и влияет только на текущий запуск.

## Алгоритм

### 1. Form score

```text
form_score = (home_goals / 5 + away_goals / 5) / 2
```

### 2. H2H score

```text
h2h_score = (h2h_home_goals + h2h_away_goals) / 10
```

### 3. Live score

Правила:

- Удары >= 6, удары в створ >= 2, опасные атаки >= 20 => `0.8`
- Удары >= 4, опасные атаки >= 15 => `0.6`
- Удары >= 2 => `0.4`
- Иначе => `0.2`

### 4. Итоговая вероятность

```text
probability = form_score * 0.4 + h2h_score * 0.2 + live_score * 0.4
```

## Правила сигнала

Сигнал создаётся, если выполнены все условия:

- Минута матча от 15 до 30
- Вероятность не ниже 65% по умолчанию
- Есть хотя бы один удар в створ
- Опасные атаки не ниже 20
- В первом тайме счёт 0:0
- Есть данные по форме и H2H

## Telegram

Для уведомлений нужны:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_ADMIN_IDS=123456789,987654321
```

Формат уведомления включает:

- матч и время
- текущий счёт
- вероятность и её компоненты
- live-статистику
- форму и H2H
- причину решения

## Дедупликация

Состояние хранится в `scanner_state.json`.

- Один матч отправляется только один раз за матч
- Состояние сохраняется между запусками
- Старые ключи вида `match_<id>_min_<minute>` автоматически нормализуются
- При большом размере состояния остаются последние записи

## Интеграция

Пример использования из PHP:

```php
require_once __DIR__ . '/backend/line/db.php';
require_once __DIR__ . '/backend/scanner/DataExtractor.php';
require_once __DIR__ . '/backend/scanner/ProbabilityCalculator.php';
require_once __DIR__ . '/backend/scanner/MatchFilter.php';
require_once __DIR__ . '/backend/scanner/Scanner.php';

use Proxbet\Line\Db;
use Proxbet\Scanner\DataExtractor;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\Scanner;

$db = Db::connectFromEnv();
$extractor = new DataExtractor($db);
$calculator = new ProbabilityCalculator();
$filter = new MatchFilter();
$scanner = new Scanner($extractor, $calculator, $filter);

$result = $scanner->scan();
$signals = array_filter($result['results'], fn($m) => $m['decision']['bet']);
```

## Требования

- PHP 8.1+
- MySQL/MariaDB
- Таблица `matches` с live-данными
- Предварительно собранная статистика формы/H2H
- Настроенный Telegram bot token, если нужны уведомления
