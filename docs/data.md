# Данные по матчу: что мы парсим, храним и можем отдавать ИИ

Этот документ описывает все данные, которыми система владеет по одному футбольному матчу, и названия колонок в БД, где эти данные лежат.

Ниже разделение на 5 слоев:

1. `matches` — главная таблица матча.
2. `live_match_snapshots` — история live-снимков по матчу.
3. `sgi_json` — сырой исторический JSON, который мы сохраняем внутри `matches`.
4. `bet_messages` — сигналы/ставки, связанные с матчем.
5. `ai_analysis_*` и `ai_analysis_requests` — связанные AI-данные по матчу.

## 1. Источники данных

По одному матчу у нас данные приходят из нескольких источников:

- Prematch feed (`backend/parser.php`): базовая карточка матча и prematch-коэффициенты.
- Live feed (`backend/live.php`): live-время, статус, счет и live-статистика.
- `eventsstat.com` по `sgi` (`backend/stat.php`): исторические матчи, H2H, табличные данные.
- Scanner (`backend/scanner/*`): сигналы и производные payload для стратегии.
- Telegram AI (`backend/telegram/*`): контекст анализа, тексты промптов, ответы и метрики AI.

## 2. Главная таблица матча: `matches`

Это центральная таблица проекта. Именно она содержит почти все данные, которые можно использовать для построения новой betting-стратегии.

### 2.1. Идентификация и базовая карточка матча

| Колонка | Источник | Что означает |
|---|---|---|
| `id` | БД | Внутренний ID матча в системе. |
| `evid` | prematch feed | Внешний ID матча из основной линии. Уникальный ключ матча. |
| `live_evid` | live feed | Внешний ID матча из live-ленты, если найдено соответствие. |
| `sgi` | prematch feed | ID матча для запроса статистики/истории из `eventsstat.com`. |
| `start_time` | prematch feed | Время начала матча. |
| `country` | prematch feed | Страна. |
| `liga` | prematch feed | Лига / турнир. |
| `home` | prematch feed | Название домашней команды. |
| `away` | prematch feed | Название гостевой команды. |
| `created_at` | БД | Когда запись матча была создана у нас. |
| `updated_at` | БД | Когда запись матча последний раз обновлялась. |

### 2.2. Live-состояние матча

| Колонка | Источник | Что означает |
|---|---|---|
| `time` | live feed | Текущее время матча в формате `mm:ss`. |
| `match_status` | live feed | Статус матча, например перерыв, идет игра, завершен. |
| `live_ht_hscore` | live feed | Голы хозяев к перерыву. |
| `live_ht_ascore` | live feed | Голы гостей к перерыву. |
| `live_hscore` | live feed | Текущий/финальный счет хозяев. |
| `live_ascore` | live feed | Текущий/финальный счет гостей. |
| `live_updated_at` | live feed / БД | Когда live-поля обновлялись последний раз. |

### 2.2.1. Производные live-trend поля в `matches`

Это не сырые поля из live feed, а вычисленные признаки, которые обновляются после сохранения live-снимка.

| Колонка | Источник | Что означает |
|---|---|---|
| `live_trend_shots_total_delta` | snapshot history -> `matches` | Насколько вырос общий объем ударов за окно live-истории. |
| `live_trend_shots_on_target_delta` | snapshot history -> `matches` | Насколько выросло число ударов в створ за окно live-истории. |
| `live_trend_danger_attacks_delta` | snapshot history -> `matches` | Насколько выросло число опасных атак за окно live-истории. |
| `live_trend_xg_delta` | snapshot history -> `matches` | Насколько вырос суммарный `xG` за окно live-истории. |
| `live_trend_window_seconds` | snapshot history -> `matches` | Размер окна, по которому посчитан trend, в секундах. |
| `live_trend_has_data` | snapshot history -> `matches` | Есть ли достаточно snapshot-данных для trend-анализа. |

### 2.3. Prematch-коэффициенты и рынки

| Колонка | Источник | Что означает |
|---|---|---|
| `home_cf` | prematch feed | Коэффициент на победу хозяев (`П1`). |
| `draw_cf` | prematch feed | Коэффициент на ничью (`X`). |
| `away_cf` | prematch feed | Коэффициент на победу гостей (`П2`). |
| `total_line` | prematch feed | Линия тотала. |
| `total_line_tb` | prematch feed | Коэффициент на тотал больше по линии `total_line`. |
| `total_line_tm` | prematch feed | Коэффициент на тотал меньше по линии `total_line`. |
| `btts_yes` | prematch feed | Коэффициент на рынок "обе забьют: да". |
| `btts_no` | prematch feed | Коэффициент на рынок "обе забьют: нет". |
| `itb1` | prematch feed | Линия индивидуального тотала хозяев. |
| `itb1cf` | prematch feed | Коэффициент на индивидуальный тотал хозяев. |
| `itb2` | prematch feed | Линия индивидуального тотала гостей. |
| `itb2cf` | prematch feed | Коэффициент на индивидуальный тотал гостей. |
| `fm1` | prematch feed | Фора хозяев. |
| `fm1cf` | prematch feed | Коэффициент на фору хозяев. |
| `fm2` | prematch feed | Фора гостей. |
| `fm2cf` | prematch feed | Коэффициент на фору гостей. |

### 2.4. Исторические метрики по последним матчам команд

Это производные признаки, которые рассчитываются из `sgi_json` в `backend/statistic/HtMetricsCalculator.php`.

| Колонка | Источник | Что означает |
|---|---|---|
| `ht_match_goals_1` | `sgi_json` -> `Q` | В скольких из последних 5 матчей хозяева забивали в 1-м тайме. |
| `ht_match_missed_goals_1` | `sgi_json` -> `Q` | В скольких из последних 5 матчей хозяева пропускали в 1-м тайме. |
| `ht_match_goals_1_avg` | `sgi_json` -> `Q` | Среднее число голов хозяев в 1-м тайме за последние 5 матчей. |
| `ht_match_missed_1_avg` | `sgi_json` -> `Q` | Среднее число пропущенных хозяевами в 1-м тайме за последние 5 матчей. |
| `ht_match_goals_2` | `sgi_json` -> `Q` | В скольких из последних 5 матчей гости забивали в 1-м тайме. |
| `ht_match_missed_goals_2` | `sgi_json` -> `Q` | В скольких из последних 5 матчей гости пропускали в 1-м тайме. |
| `ht_match_goals_2_avg` | `sgi_json` -> `Q` | Среднее число голов гостей в 1-м тайме за последние 5 матчей. |
| `ht_match_missed_2_avg` | `sgi_json` -> `Q` | Среднее число пропущенных гостями в 1-м тайме за последние 5 матчей. |

### 2.5. Исторические H2H-метрики

Это тоже расчетные признаки из `sgi_json`, но по последним очным встречам.

| Колонка | Источник | Что означает |
|---|---|---|
| `h2h_ht_match_goals_1` | `sgi_json` -> `G` | В скольких из последних 5 H2H хозяева забивали в 1-м тайме. |
| `h2h_ht_match_missed_goals_1` | `sgi_json` -> `G` | В скольких из последних 5 H2H хозяева пропускали в 1-м тайме. |
| `h2h_ht_match_goals_1_avg` | `sgi_json` -> `G` | Среднее число голов хозяев в 1-м тайме по последним 5 H2H. |
| `h2h_ht_match_missed_1_avg` | `sgi_json` -> `G` | Среднее число пропущенных хозяевами в 1-м тайме по последним 5 H2H. |
| `h2h_ht_match_goals_2` | `sgi_json` -> `G` | В скольких из последних 5 H2H гости забивали в 1-м тайме. |
| `h2h_ht_match_missed_goals_2` | `sgi_json` -> `G` | В скольких из последних 5 H2H гости пропускали в 1-м тайме. |
| `h2h_ht_match_goals_2_avg` | `sgi_json` -> `G` | Среднее число голов гостей в 1-м тайме по последним 5 H2H. |
| `h2h_ht_match_missed_2_avg` | `sgi_json` -> `G` | Среднее число пропущенных гостями в 1-м тайме по последним 5 H2H. |

### 2.6. Табличные / турнирные метрики

Это расчетные признаки из `backend/statistic/TableMetricsCalculator.php`.

| Колонка | Источник | Что означает |
|---|---|---|
| `table_games_1` | `sgi_json` -> `S` | Сколько матчей сыграли хозяева в таблице турнира. |
| `table_goals_1` | `sgi_json` -> `S` | Сколько голов забили хозяева в таблице турнира. |
| `table_missed_1` | `sgi_json` -> `S` | Сколько голов пропустили хозяева в таблице турнира. |
| `table_games_2` | `sgi_json` -> `S` | Сколько матчей сыграли гости в таблице турнира. |
| `table_goals_2` | `sgi_json` -> `S` | Сколько голов забили гости в таблице турнира. |
| `table_missed_2` | `sgi_json` -> `S` | Сколько голов пропустили гости в таблице турнира. |
| `table_avg` | `sgi_json` -> `S` | Средний тотал голов по таблице турнира. |

### 2.7. Live-статистика матча

Это сырые live-метрики, которые обновляются из live feed.

| Колонка | Источник | Что означает |
|---|---|---|
| `live_xg_home` | live feed | xG хозяев. |
| `live_xg_away` | live feed | xG гостей. |
| `live_att_home` | live feed | Атаки хозяев. |
| `live_att_away` | live feed | Атаки гостей. |
| `live_danger_att_home` | live feed | Опасные атаки хозяев. |
| `live_danger_att_away` | live feed | Опасные атаки гостей. |
| `live_shots_on_target_home` | live feed | Удары в створ хозяев. |
| `live_shots_on_target_away` | live feed | Удары в створ гостей. |
| `live_shots_off_target_home` | live feed | Удары мимо хозяев. |
| `live_shots_off_target_away` | live feed | Удары мимо гостей. |
| `live_yellow_cards_home` | live feed | Желтые карточки хозяев. |
| `live_yellow_cards_away` | live feed | Желтые карточки гостей. |
| `live_safe_home` | live feed | Сейвы хозяев. |
| `live_safe_away` | live feed | Сейвы гостей. |
| `live_corner_home` | live feed | Угловые хозяев. |
| `live_corner_away` | live feed | Угловые гостей. |

### 2.7.1. Как live-данные теперь используются в scanner

Для алгоритма 1 live-метрики теперь используются не только как суммарный snapshot по матчу, но и как источник:

- side-based давления хозяев и гостей;
- optional `xG`-сигнала;
- short-term trend-сигнала по окну snapshot-истории.

Это позволяет считать не только общий темп, но и понимать:

- какая сторона реально давит;
- растет ли интенсивность именно в последние минуты;
- есть ли у матча ускорение по ударам, опасным атакам и `xG`.

## 3. История live-снимков: `live_match_snapshots`

Это отдельная таблица, которая хранит промежуточные состояния live-матча. Она нужна не для UI, а для аналитики и расчета динамики.

### 3.1. Что лежит в snapshot-таблице

| Колонка | Что означает |
|---|---|
| `id` | Внутренний ID snapshot-записи. |
| `match_id` | Ссылка на `matches.id`. |
| `evid` | Внешний ID матча. |
| `minute` | Минута матча на момент snapshot. |
| `time` | Время матча в формате `mm:ss`. |
| `match_status` | Статус матча на момент snapshot. |
| `live_ht_hscore` | Голы хозяев к перерыву на момент snapshot. |
| `live_ht_ascore` | Голы гостей к перерыву на момент snapshot. |
| `live_hscore` | Текущий счет хозяев. |
| `live_ascore` | Текущий счет гостей. |
| `live_xg_home` | xG хозяев в момент snapshot. |
| `live_xg_away` | xG гостей в момент snapshot. |
| `live_att_home` | Атаки хозяев. |
| `live_att_away` | Атаки гостей. |
| `live_danger_att_home` | Опасные атаки хозяев. |
| `live_danger_att_away` | Опасные атаки гостей. |
| `live_shots_on_target_home` | Удары в створ хозяев. |
| `live_shots_on_target_away` | Удары в створ гостей. |
| `live_shots_off_target_home` | Удары мимо хозяев. |
| `live_shots_off_target_away` | Удары мимо гостей. |
| `live_yellow_cards_home` | Желтые карточки хозяев. |
| `live_yellow_cards_away` | Желтые карточки гостей. |
| `live_safe_home` | Сейвы хозяев. |
| `live_safe_away` | Сейвы гостей. |
| `live_corner_home` | Угловые хозяев. |
| `live_corner_away` | Угловые гостей. |
| `captured_at` | Когда snapshot был сохранен. |

### 3.2. Зачем нужна эта таблица

`live_match_snapshots` позволяет строить признаки, которых нет в одном текущем срезе:

- прирост ударов за последние минуты;
- прирост ударов в створ;
- прирост опасных атак;
- прирост суммарного `xG`;
- реальный pace матча на коротком окне.

Практически это означает, что алгоритм 1 теперь может использовать не только "что есть сейчас", но и "как быстро матч разгоняется".

### 2.8. Сырой JSON и служебные поля статистики

| Колонка | Источник | Что означает |
|---|---|---|
| `sgi_json` | `eventsstat.com` | Сырой JSON по матчу, откуда рассчитываются исторические и табличные признаки. |
| `stats_updated_at` | statistic worker | Когда историческая статистика обновлялась последний раз. |
| `stats_fetch_status` | statistic worker | Статус загрузки статистики: обычно `ok` или `error`. |
| `stats_error` | statistic worker | Текст ошибки, если статистика не загрузилась или не разобралась. |
| `stats_source` | statistic worker | Источник статистики: из БД (`db`) или заново скачан (`remote`). |
| `stats_version` | statistic worker | Версия расчета статистики. |
| `stats_debug_json` | statistic worker | Debug JSON по тому, как считались метрики. |
| `stats_refresh_needed` | БД / worker | Флаг принудительного перерасчета статистики. |

## 3. Что именно лежит внутри `matches.sgi_json`

Это не отдельные колонки, а сырой JSON, который мы сохраняем целиком. Для новой стратегии это важный источник дополнительных признаков, потому что из него можно извлечь больше, чем сейчас извлекает текущий scanner.

На текущий момент код явно использует такие блоки:

| Путь в JSON | Где используется | Что содержит |
|---|---|---|
| `Q` | `HtMetricsCalculator` | Последние матчи команд. Может содержать общий список или раздельно `H` и `A`. |
| `Q.H` | `HtMetricsCalculator` | Последние матчи домашней команды. |
| `Q.A` | `HtMetricsCalculator` | Последние матчи гостевой команды. |
| `Q.G` | fallback в части H2H | H2H-список внутри `Q`, если нет верхнего `G`. |
| `G` | `HtMetricsCalculator`, `DataExtractor`, `public_analysis` | Последние очные встречи H2H. |
| `P[0].H`, `P[0].A` | HT calculators | Счет первого тайма внутри исторического матча. |
| `H`, `A` внутри матча | HT calculators | Команды исторического матча. |
| `S.A.C[*].R[*]` | `TableMetricsCalculator` | Табличные строки турнира. |
| `S.A.C[*].R[*].T` | `TableMetricsCalculator` | Название команды в таблице. |
| `S.A.C[*].R[*].C` | `TableMetricsCalculator` | Количество игр. |
| `S.A.C[*].R[*].S` | `TableMetricsCalculator` | Забитые голы. |
| `S.A.C[*].R[*].F` | `TableMetricsCalculator` | Пропущенные голы. |

Практически это значит, что `sgi_json` можно использовать как расширенный сырой исторический источник, а не только как источник текущих 23 вычисленных колонок.

## 4. Связанные с матчем данные в `bet_messages`

Таблица `bet_messages` хранит не сам матч, а опубликованные сигналы/ставки по матчу. Это важно для обучения новых стратегий на исходах и для анализа того, какие сигналы реально были отправлены.

| Колонка | Что означает |
|---|---|
| `id` | Внутренний ID записи сигнала. |
| `match_id` | Ссылка на `matches.id`. |
| `message_id` | ID сообщения в Telegram. |
| `chat_id` | Канал / чат, куда отправлен сигнал. |
| `message_text` | Текст отправленного сигнала. |
| `algorithm_id` | ID алгоритма, который породил сигнал (`1`, `2`, `3`). |
| `algorithm_name` | Человекочитаемое имя алгоритма. |
| `algorithm_payload_json` | Дополнительные данные сигнала, особенно важные для алгоритма 3. |
| `bet_status` | Статус ставки: `pending`, `won`, `lost`. |
| `sent_at` | Когда сигнал был отправлен. |
| `updated_at` | Когда запись обновлялась. |
| `checked_at` | Когда исход ставки был проверен. |

### 4.1. Что может лежать в `bet_messages.algorithm_payload_json`

#### Для алгоритма 2

| Ключ | Что означает |
|---|---|
| `home_win_odd` | Коэффициент на победу хозяев. |
| `over_25_odd` | Коэффициент на ТБ 2.5, если релевантен. |
| `total_line` | Текущая линия тотала. |
| `over_25_odd_check_skipped` | Флаг, что проверка `ТБ 2.5` пропущена из-за линии выше 2.5. |
| `home_first_half_goals_in_last_5` | В скольких из 5 последних матчей хозяева забивали в 1-м тайме. |
| `h2h_first_half_goals_in_last_5` | В скольких из 5 последних H2H был гол в 1-м тайме. |
| `has_data` | Хватило ли данных для алгоритма 2. |

#### Для алгоритма 3

| Ключ | Что означает |
|---|---|
| `selected_team_side` | Какая сторона выбрана: `home` или `away`. |
| `selected_team_name` | Название выбранной команды. |
| `selected_team_goals_current` | Сколько голов у выбранной команды на момент сигнала. |
| `selected_team_target_bet` | Сформированный рынок, обычно `ИТБ <команда> больше 0.5`. |
| `triggered_rule` | Машинное имя сработавшего правила. |
| `triggered_rule_label` | Человекочитаемое описание причины. |
| `home_attack_ratio` | Коэффициент атаки хозяев. |
| `away_defense_ratio` | Коэффициент пропускаемости гостей. |
| `away_attack_ratio` | Коэффициент атаки гостей. |
| `home_defense_ratio` | Коэффициент пропускаемости хозяев. |
| `table_games_1` | Игры хозяев в таблице. |
| `table_goals_1` | Голы хозяев в таблице. |
| `table_missed_1` | Пропущенные хозяев хозяев в таблице. |
| `table_games_2` | Игры гостей в таблице. |
| `table_goals_2` | Голы гостей в таблице. |
| `table_missed_2` | Пропущенные голы гостей в таблице. |
| `match_status` | Статус матча на момент сигнала. |

## 5. Связанные AI-данные по матчу

### 5.1. `ai_analysis_requests`

Это журнал пользовательских AI-разборов по матчу.

| Колонка | Что означает |
|---|---|
| `id` | Внутренний ID запроса. |
| `telegram_user_id` | Пользователь Telegram, запросивший разбор. |
| `match_id` | Ссылка на `matches.id`. |
| `bet_message_id` | Связанный сигнал из `bet_messages`, если анализ запускался из сигнала. |
| `provider` | AI-провайдер, сейчас обычно `gemini`. |
| `model_name` | Название модели. |
| `status` | `pending`, `completed`, `failed`. |
| `cost_charged` | Сколько кредитов списано за разбор. |
| `prompt_text` | Полный промпт, который отправлялся модели. |
| `response_text` | Ответ модели. |
| `error_text` | Текст ошибки, если анализ не удался. |
| `created_at` | Когда запрос создан. |
| `updated_at` | Когда запрос обновлен. |

Дополнительные колонки из `backend/telegram/migrations.sql`, если эта миграция применена:

| Колонка | Что означает |
|---|---|
| `cache_hit` | Был ли ответ взят из кэша. |
| `response_time_ms` | Время ответа AI в миллисекундах. |

### 5.2. `ai_analysis_cache`

Кэш AI-ответов по матчу и алгоритму.

| Колонка | Что означает |
|---|---|
| `id` | Внутренний ID записи кэша. |
| `match_id` | Ссылка на матч. |
| `algorithm_id` | Для какого алгоритма сохранен ответ. |
| `response_text` | Сохраненный AI-ответ. |
| `created_at` | Когда ответ закэширован. |

### 5.3. `ai_analysis_metrics`

Метрики использования AI по матчу.

| Колонка | Что означает |
|---|---|
| `id` | Внутренний ID записи. |
| `telegram_user_id` | Пользователь Telegram. |
| `match_id` | Ссылка на матч. |
| `algorithm_id` | Какой алгоритм анализировали. |
| `provider` | AI-провайдер. |
| `model_name` | Модель AI. |
| `success` | Успех/неуспех вызова. |
| `response_time_ms` | Время ответа в миллисекундах. |
| `error_type` | Тип ошибки, если была. |
| `created_at` | Когда метрика записана. |

## 6. Какие данные реально попадают в AI-контекст матча

При формировании AI-анализа репозиторий `backend/telegram/TelegramAiRepository.php` напрямую отдает в контекст матча такие поля:

- база матча: `match_id`, `country`, `liga`, `home`, `away`, `start_time`
- live: `time`, `match_status`, `live_ht_hscore`, `live_ht_ascore`, `live_hscore`, `live_ascore`
- prematch odds: `home_cf`, `draw_cf`, `away_cf`, `total_line`, `total_line_tb`, `total_line_tm`
- historical HT: `ht_match_goals_1`, `ht_match_goals_2`, `h2h_ht_match_goals_1`, `h2h_ht_match_goals_2`
- table stats: `table_games_1`, `table_goals_1`, `table_missed_1`, `table_games_2`, `table_goals_2`, `table_missed_2`
- live stats: `live_xg_home`, `live_xg_away`, `live_att_home`, `live_att_away`, `live_danger_att_home`, `live_danger_att_away`, `live_shots_on_target_home`, `live_shots_on_target_away`, `live_shots_off_target_home`, `live_shots_off_target_away`, `live_corner_home`, `live_corner_away`
- live trend: `live_trend_shots_total_delta`, `live_trend_shots_on_target_delta`, `live_trend_danger_attacks_delta`, `live_trend_xg_delta`, `live_trend_window_seconds`, `live_trend_has_data`
- raw history: `sgi_json`
- signal linkage: `bet_message_id`, `algorithm_id`, `algorithm_name`, `message_text`, `algorithm_payload_json`, `bet_sent_at`

Это означает, что для ИИ у нас уже есть не только карточка матча, но и prematch, live, history, H2H, table data и история самого сигнала.

## 7. Что считать полным набором данных по матчу для новой стратегии

Если делать новую стратегию ставок, минимально полезный полный набор по матчу у нас такой:

- идентификаторы: `id`, `evid`, `live_evid`, `sgi`
- карточка матча: `country`, `liga`, `home`, `away`, `start_time`
- live-состояние: `time`, `match_status`, `live_ht_hscore`, `live_ht_ascore`, `live_hscore`, `live_ascore`
- prematch odds: все поля от `home_cf` до `fm2cf`
- history-form: все поля от `ht_match_goals_1` до `ht_match_missed_2_avg`
- h2h-history: все поля от `h2h_ht_match_goals_1` до `h2h_ht_match_missed_2_avg`
- table stats: `table_games_1`, `table_goals_1`, `table_missed_1`, `table_games_2`, `table_goals_2`, `table_missed_2`, `table_avg`
- live stats: все поля от `live_xg_home` до `live_corner_away`
- live trend stats: `live_trend_shots_total_delta`, `live_trend_shots_on_target_delta`, `live_trend_danger_attacks_delta`, `live_trend_xg_delta`, `live_trend_window_seconds`, `live_trend_has_data`
- snapshot history: `live_match_snapshots.*`
- raw source: `sgi_json`
- signal history: `bet_messages.*`
- AI history: `ai_analysis_requests.*`, `ai_analysis_cache.*`, `ai_analysis_metrics.*`

## 8. Что важно понимать

- Основная таблица для новой стратегии — это `matches`.
- Для short-term live-динамики теперь важна еще и таблица `live_match_snapshots`.
- Самый богатый сырой источник дополнительных признаков — `matches.sgi_json`.
- `bet_messages` дает разметку по реально отправленным сигналам и исходам.
- AI-таблицы дают тексты, reasoning и реакцию модели по тем же матчам.
- Не все колонки являются "спарсенными" в чистом виде: часть полей сырые, часть вычисляется из сырого JSON, часть относится к сигналам и AI-обработке.

Если нужен следующий шаг, логично сделать еще один документ: `docs/strategy-dataset.md`, где собрать уже готовый flat-слой "feature name -> SQL column/json path -> тип -> можно ли использовать до матча / только live / только post-signal".
