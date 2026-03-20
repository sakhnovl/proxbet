# Сканер гола в первом тайме

Сканер анализирует live-матчи и выпускает сигналы на гол в первом тайме по двум независимым алгоритмам.

## Состав

- `DataExtractor.php` — извлечение и нормализация данных из БД
- `ProbabilityCalculator.php` — расчёт score и вероятности для алгоритма 1
- `MatchFilter.php` — правила отбора для алгоритма 1 и алгоритма 2
- `Scanner.php` — оркестрация анализа и выпуск результатов по алгоритмам
- `ScannerCli.php` — CLI-интерфейс запуска
- `TelegramNotifier.php` — отправка уведомлений в Telegram с дедупликацией
- `BetMessageRepository.php` — сохранение отправленных сигналов в `bet_messages`

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

Переопределение минимального порога вероятности алгоритма 1:

```bash
php ScannerCli.php --min-probability=0.70
```

## Алгоритм 1

Алгоритм 1 сохраняет текущую вероятностную модель.

### Компоненты

- Форма команд за последние 5 матчей: вес `40%`
- H2H за последние 5 встреч: вес `20%`
- Текущая live-статистика матча: вес `40%`

### Формулы

```text
form_score = (home_goals / 5 + away_goals / 5) / 2
h2h_score = (h2h_home_goals + h2h_away_goals) / 10
probability = form_score * 0.4 + h2h_score * 0.2 + live_score * 0.4
```

### Правила сигнала

Сигнал создаётся, если одновременно выполнены условия:

- минута матча от `15` до `30`;
- вероятность не ниже `65%` по умолчанию;
- есть хотя бы один удар в створ;
- опасные атаки не ниже `20`;
- счёт первого тайма `0:0`;
- есть данные по форме и H2H.

## Алгоритм 2

Алгоритм 2 не использует вероятностную модель. Он работает по жёстким условиям:

```text
score = 0:0
AND minute between 15 and 30
AND home_win_odd <= 1.5
AND (total_line > 2.5 OR over_25_odd <= 1.5 when total_line = 2.5)
AND home_first_half_goals_in_last_5 >= 3
AND h2h_first_half_goals_in_last_5 >= 3
```

### Источники данных

- `home_cf` — коэффициент на победу хозяев
- `total_line_tb` при `total_line = 2.5` — коэффициент на ТБ 2.5
- если `total_line > 2.5`, проверка коэффициента на ТБ 2.5 пропускается и условие считается выполненным
- `ht_match_goals_1` — матчи хозяев с голом в первом тайме за последние 5
- `sgi_json -> G` — H2H-матчи за последние 5, где в первом тайме забивала любая команда

## Формат результата

Каждый результат сканера содержит:

- `algorithm_id`
- `algorithm_name`
- `decision.reason`
- `signal_type`

Один и тот же матч может вернуть два результата: отдельно по алгоритму 1 и отдельно по алгоритму 2.

## Telegram

Для уведомлений нужны:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHANNEL_ID=@your_channel
```

Формат уведомления включает:

- матч, турнир и время;
- номер алгоритма;
- либо вероятность и score-компоненты для алгоритма 1;
- либо жёсткие условия алгоритма 2;
- live-статистику;
- форму и H2H;
- причину сигнала.

Для алгоритма 2 сообщение отправляется только если матч идёт при счёте `0:0` и время находится в окне `15-30` минут.

## Дедупликация

Состояние хранится в `scanner_state.json`.

- сигнал по одному матчу и одному алгоритму отправляется только один раз;
- сигналы алгоритма 1 и алгоритма 2 для одного матча считаются разными;
- старые ключи вида `match_<id>` и `match_<id>_min_<minute>` автоматически нормализуются в `match_<id>_algorithm_1`.

Новый формат ключа:

```text
match_<id>_algorithm_<algorithm_id>
```

## Хранение сообщений

Таблица `bet_messages` теперь хранит:

- `algorithm_id`
- `algorithm_name`

Это позволяет отдельно отслеживать результат сигналов по каждому алгоритму.

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
use Proxbet\Scanner\MatchFilter;
use Proxbet\Scanner\ProbabilityCalculator;
use Proxbet\Scanner\Scanner;

$db = Db::connectFromEnv();
$extractor = new DataExtractor($db);
$calculator = new ProbabilityCalculator();
$filter = new MatchFilter();
$scanner = new Scanner($extractor, $calculator, $filter);

$result = $scanner->scan();
$signals = array_filter($result['results'], static fn(array $row): bool => (bool) ($row['decision']['bet'] ?? false));
```

## Требования

- PHP 8.1+
- MySQL/MariaDB
- таблица `matches` с live-данными и коэффициентами
- предварительно собранная статистика формы/H2H
- Telegram bot token и channel id, если нужны уведомления
