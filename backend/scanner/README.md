# Сканер live-сигналов

Сканер анализирует live-матчи и выпускает сигналы по трем независимым алгоритмам:

- Алгоритм 1: гол в первом тайме по вероятностной модели.
- Алгоритм 2: гол фаворита-хозяев в первом тайме по жестким фильтрам.
- Алгоритм 3: индивидуальный тотал выбранной команды больше `0.5` по табличной статистике.

## Состав

- `DataExtractor.php` - извлечение и нормализация данных из БД.
- `ProbabilityCalculator.php` - расчет score и вероятности для алгоритма 1.
- `MatchFilter.php` - правила отбора для алгоритмов 1, 2 и 3.
- `Scanner.php` - оркестрация анализа и формирование результатов.
- `ScannerCli.php` - CLI-запуск и читаемый вывод по всем алгоритмам.
- `TelegramNotifier.php` - отправка сигналов в Telegram с дедупликацией.
- `BetMessageRepository.php` - сохранение сигналов и payload в `bet_messages`.
- `BetChecker.php` - расчет исхода ставки и редактирование Telegram-сообщения.

## Запуск

```bash
php ScannerCli.php
php ScannerCli.php --json
php ScannerCli.php --verbose
php ScannerCli.php --no-telegram
php ScannerCli.php --min-probability=0.70
```

## Алгоритм 1

Алгоритм 1 использует вероятностную модель по форме, H2H и live-статистике.

Сигнал создается, если одновременно выполнены условия:

- минута матча от `15` до `30`;
- вероятность не ниже `65%` по умолчанию;
- есть хотя бы один удар в створ;
- опасные атаки не ниже `20`;
- счет первого тайма `0:0`;
- доступны данные по форме и H2H.

## Алгоритм 2

Алгоритм 2 не использует вероятностную модель. Он работает по жестким условиям:

```text
score = 0:0
AND minute between 15 and 30
AND home_win_odd <= 1.5
AND (total_line > 2.5 OR over_25_odd <= 1.5 when total_line = 2.5)
AND home_first_half_goals_in_last_5 >= 3
AND h2h_first_half_goals_in_last_5 >= 3
```

## Алгоритм 3

Алгоритм 3 использует только табличные поля из `matches`:

- `table_games_1`, `table_goals_1`, `table_missed_1`
- `table_games_2`, `table_goals_2`, `table_missed_2`
- `match_status`
- `live_hscore`, `live_ascore`

Базовая валидация:

- `table_games_1 > 10`
- `table_games_2 > 10`
- все шесть табличных метрик заполнены

Правила выбора команды:

```text
home candidate:
(table_goals_1 / 2) / table_games_1 > 1.5
AND
(table_missed_2 / 2) / table_games_2 > 1.5

away candidate:
(table_goals_2 / 2) / table_games_2 > 1.5
AND
(table_missed_1 / 2) / table_games_1 > 1.5
```

Сигнал публикуется только если:

- статус матча `Перерыв`;
- выбранная команда еще не забила;
- сформирована ставка `ИТБ <команда> больше 0.5`.

Если проходят обе стороны, сканер выбирает команду с более сильной суммой:

```text
attack ratio + opponent defense ratio
```

## Формат результата

Каждый результат сканера содержит:

- `algorithm_id`
- `algorithm_name`
- `signal_type`
- `decision.bet`
- `decision.reason`
- `algorithm_data`

Для алгоритма 3 в `algorithm_data` дополнительно сохраняются:

- `selected_team_side`
- `selected_team_name`
- `selected_team_goals_current`
- `selected_team_target_bet`
- `triggered_rule`
- `table_games_1`, `table_goals_1`, `table_missed_1`
- `table_games_2`, `table_goals_2`, `table_missed_2`
- `home_attack_ratio`, `away_defense_ratio`
- `away_attack_ratio`, `home_defense_ratio`
- `match_status`

## Telegram

Для уведомлений нужны:

```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHANNEL_ID=@your_channel
```

Сообщения в Telegram:

- отправляются отдельно для каждого `algorithm_id`;
- имеют отдельный шаблон для алгоритма 3;
- содержат AI-кнопку;
- сохраняются в `bet_messages`.

Для алгоритма 3 сообщение явно показывает:

- выбранную команду;
- ставку `ИТБ команды больше 0.5`;
- счет и статус `Перерыв`;
- табличные показатели обеих команд;
- причину сигнала;
- подсказку для AI анализировать именно эту ставку.

## Хранение сообщений

Таблица `bet_messages` хранит:

- `algorithm_id`
- `algorithm_name`
- `algorithm_payload_json`
- `bet_status`

`algorithm_payload_json` нужен прежде всего для алгоритма 3, чтобы надежно сохранить:

- на какую именно команду была ставка;
- какой рынок был выбран;
- какие табличные метрики легли в основу сигнала.

## Расчет исхода ставки

`BetChecker.php` обрабатывает pending-сигналы отдельно по алгоритмам:

- алгоритмы 1 и 2: логика гола в первом тайме остается прежней;
- алгоритм 3: расчет идет по выбранной команде из `algorithm_payload_json`.

Правило расчета алгоритма 3:

- выигрыш: выбранная команда забила хотя бы `1` гол;
- проигрыш: матч завершен (`Игра завершена`), а выбранная команда так и не забила.

При расчете Telegram-сообщение редактируется и показывает:

- итоговый статус ставки;
- финальный счет матча;
- выбранную команду;
- сколько голов она забила.

## Дедупликация

Состояние хранится в JSON-файле, путь к которому задается через `SCANNER_STATE_PATH`.
Если переменная не задана, локальный fallback-сценарий использует `data/scanner_state.json` в корне проекта.

Новый формат ключа:

```text
match_<id>_algorithm_<algorithm_id>
```

Это позволяет не смешивать сигналы алгоритмов 1, 2 и 3 для одного матча.
