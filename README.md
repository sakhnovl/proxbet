# Proxbet

Подробное техническое описание проекта по состоянию на анализа репозитория.

## Что это за проект

`Proxbet` это backend-система на PHP для футбольного live-сканинга и Telegram-автоматизации.
Проект не содержит полноценного web-frontend. Основные сценарии работы здесь такие:

1. Собирать предматчевые футбольные матчи из внешнего JSON-фида букмекера.
2. Сохранять матчи, коэффициенты и вспомогательные идентификаторы в MySQL.
3. Догружать live-статистику и live-статусы по уже известным матчам.
4. Догружать статистику по SGI/историческим матчам из внешнего сервиса `eventsstat.com`.
5. Вычислять сигналы на гол в первом тайме по двум независимым алгоритмам.
6. Публиковать сигналы в Telegram-канал.
7. Отслеживать исходы уже отправленных сигналов и менять статус ставок на `won/lost`.
8. Поддерживать Telegram-бота для:
   - управления банами матчей/лиг/команд;
   - управления балансом пользователей;
   - выдачи AI-разборов матчей;
   - управления пулом Gemini API-ключей и моделей.
9. Отдавать JSON API для внешнего интерфейса или панели.
10. Отдавать защищенный admin API для банов и статистики.

Иными словами, это не просто “парсер коэффициентов”, а связанная система из ingestion, live-обновления, аналитики, сигналинга, Telegram-продукта и служебного API.

## Главные подсистемы

В проекте есть несколько четко читаемых доменов.

### 1. Ingestion предматчевых матчей

Отвечает за загрузку обычной линии и первичное заполнение таблицы `matches`.

Ключевые файлы:

- `backend/parser.php`
- `backend/line/extractMatches.php`
- `backend/line/extractOdds.php`
- `backend/line/extractFm1.php`
- `backend/line/db.php`
- `backend/line/BanMatcher.php`

Что делает:

- читает `API_URL` из `.env`;
- получает JSON через общий HTTP-клиент;
- извлекает из фида матчи, команды, страну, лигу, время старта, SGI и коэффициенты;
- отбрасывает матчи без `SGI`;
- прогоняет матчи через список активных банов;
- делает `upsert` в таблицу `matches`.

Что именно сохраняется:

- `evid` матча из источника;
- `sgi` и позже `sgi_json`;
- `start_time`;
- `country`, `liga`, `home`, `away`;
- коэффициенты `home_cf`, `draw_cf`, `away_cf`;
- тоталы и коэффициенты по тоталам;
- BTTS;
- индивидуальные тоталы;
- форы `fm1/fm2` и коэффициенты по ним.

### 2. Live-обновление матчей

Отвечает за обновление уже известных матчей в реальном времени.

Ключевые файлы:

- `backend/live.php`
- `backend/live/service.php`
- `backend/live/client.php`
- `backend/live/extract.php`
- `backend/live/update.php`
- `backend/live/match.php`

Что делает:

- читает `API_URL_LIVE`;
- получает live JSON;
- находит узел `Value` в любом уровне вложенности;
- сопоставляет live-матч с матчом из БД по `home/away`;
- обновляет:
  - текущий счет;
  - счет первого тайма;
  - текущее время;
  - статус матча;
  - live-поля статистики;
  - `live_evid`, если он еще не заполнен;
  - `live_updated_at`.

Какие live-метрики поддерживаются:

- xG;
- атаки;
- опасные атаки;
- удары в створ;
- удары мимо;
- желтые карточки;
- сейвы;
- угловые.

Дополнительно `backend/live.php` содержит защитную логику: если матч дошел до 90+ минут, но не помечен завершенным и давно не обновлялся, проект принудительно ставит ему финальный статус.

### 3. Статистика по SGI / исторические метрики

Это отдельная подсистема, которая не работает по live-фиду. Она берет `sgi`, ходит во внешний статистический API и вычисляет half-time метрики для формы и H2H.

Ключевые файлы:

- `backend/stat.php`
- `backend/statistic/Config.php`
- `backend/statistic/EventsstatClient.php`
- `backend/statistic/StatisticRepository.php`
- `backend/statistic/HtMetricsCalculator.php`
- `backend/statistic/StatisticService.php`
- `backend/statistic/StatisticServiceFactory.php`
- `backend/statistic/StatCli.php`
- `backend/statistic/TeamNameNormalizer.php`

Что делает:

- выбирает матчи, которым нужна статистика;
- использует `sgi` для загрузки JSON с `eventsstat.com`;
- сохраняет сырой JSON в `matches.sgi_json`;
- вычисляет метрики по последним 5 матчам и H2H;
- пишет метрики обратно в `matches`;
- ведет статус обновления статистики и debug-информацию.

Какие метрики появляются в `matches`:

- `ht_match_goals_1`
- `ht_match_missed_goals_1`
- `ht_match_goals_1_avg`
- `ht_match_missed_1_avg`
- `ht_match_goals_2`
- `ht_match_missed_goals_2`
- `ht_match_goals_2_avg`
- `ht_match_missed_2_avg`
- `h2h_ht_match_goals_1`
- `h2h_ht_match_missed_goals_1`
- `h2h_ht_match_goals_1_avg`
- `h2h_ht_match_missed_1_avg`
- `h2h_ht_match_goals_2`
- `h2h_ht_match_missed_goals_2`
- `h2h_ht_match_goals_2_avg`
- `h2h_ht_match_missed_2_avg`

Служебные поля статистики:

- `stats_updated_at`
- `stats_fetch_status`
- `stats_error`
- `stats_source`
- `stats_version`
- `stats_debug_json`
- `stats_refresh_needed`

### 4. Сканер сигналов на гол в первом тайме

Это ключевая бизнес-логика проекта.

Ключевые файлы:

- `backend/scanner/DataExtractor.php`
- `backend/scanner/ProbabilityCalculator.php`
- `backend/scanner/MatchFilter.php`
- `backend/scanner/Scanner.php`
- `backend/scanner/ScannerCli.php`
- `backend/scanner/TelegramNotifier.php`
- `backend/scanner/BetMessageRepository.php`
- `backend/scanner/BetChecker.php`
- `backend/scanner/README.md`
- `backend/bet_checker.php`
- `backend/bet_stats.php`

Подсистема работает по уже собранным матчам из БД и анализирует только активные матчи с `time` и `match_status`.

#### Алгоритм 1

Алгоритм 1 это вероятностная модель.

Источник данных:

- форма домашней и гостевой команды за последние 5 матчей;
- H2H за последние 5 встреч;
- текущая live-активность матча.

Формулы:

- `form_score = ((home_goals / 5) + (away_goals / 5)) / 2`
- `h2h_score = (h2h_home_goals + h2h_away_goals) / 10`
- `live_score` выбирается ступенчато:
  - `0.8`, если ударов >= 6, ударов в створ >= 2 и опасных атак >= 20;
  - `0.6`, если ударов >= 4 и опасных атак >= 15;
  - `0.4`, если ударов >= 2;
  - `0.2` во всех остальных случаях.
- `probability = form_score * 0.4 + h2h_score * 0.2 + live_score * 0.4`

Условия сигнала:

- в первом тайме счет еще `0:0`;
- минута от `15` до `30`;
- есть хотя бы один удар в створ;
- опасных атак не меньше `20`;
- есть данные по форме;
- есть данные по H2H;
- итоговая вероятность не ниже порога, по умолчанию `0.65`.

#### Алгоритм 2

Алгоритм 2 это не вероятностная модель, а набор жестких фильтров.

Используемые поля:

- `home_cf` как коэффициент на победу хозяев;
- `total_line` и `total_line_tb` для проверки тотала больше 2.5;
- `ht_match_goals_1` как число последних 5 матчей хозяев с голом в первом тайме;
- `sgi_json -> G` для подсчета H2H-матчей, где в первом тайме забивала любая команда.

Условия сигнала:

- счет первого тайма `0:0`;
- минута от `15` до `30`;
- `home_win_odd <= 1.5`;
- если линия тотала равна `2.5`, то `over_25_odd <= 1.5`;
- если линия тотала уже выше `2.5`, отдельная проверка коэффициента на ТБ 2.5 пропускается;
- хозяева забивали в первом тайме минимум в `3` из `5`;
- в H2H был гол в первом тайме минимум в `3` из `5`.

#### AlgorithmX (Алгоритм 4)

AlgorithmX это статистический алгоритм предсказания вероятности гола в оставшееся время первого тайма на основе live-статистики матча.

**Ключевые отличия от Алгоритмов 1-2:**

- Фокус исключительно на live-динамике матча (не использует историческую форму)
- Вероятностная модель с сигмоидной функцией
- Расчет Attack Intensity Score (AIS) из live-метрик
- Контекстные модификаторы (время, счет, сухой период)

**Используемые live-метрики:**

- Опасные атаки (вес 0.4)
- Удары (вес 0.3)
- Удары в створ (вес 0.2)
- Угловые (вес 0.1)

**Формула AIS:**
```
AIS = (dangerous_attacks × 0.4) + (shots × 0.3) + 
      (shots_on_target × 0.2) + (corners × 0.1)
AIS_rate = AIS_total / minute
```

**Базовая вероятность (сигмоида):**
```
base_prob = 1 / (1 + e^(-k × (AIS_rate - threshold)))
где k = 2.5, threshold = 1.8
```

**Модификаторы:**

- Временной фактор: учитывает оставшееся время до конца тайма
- Модификатор счета: ×1.05 (ничья), ×1.10 (разница 1 гол), ×0.90 (разница 2+)
- Модификатор сухого периода: ×0.92 если 0:0 после 30-й минуты

**Условия сигнала:**

- Минута от 5 до 45
- Вероятность >= 60% (высокая уверенность)
- Или вероятность 40-60% с дополнительными проверками

**Калибровка:**

Параметры `k` и `threshold` могут быть откалиброваны на исторических данных для улучшения точности. Целевые метрики:
- Brier Score < 0.20
- ROC-AUC > 0.68

Подробная документация: `backend/scanner/Algorithms/AlgorithmX/README.md`

#### Что возвращает сканер

Сканер возвращает не просто “подходит/не подходит”, а полноценную структуру результата:

- `match_id`
- `country`
- `liga`
- `home`
- `away`
- `minute`
- `time`
- `score_home`
- `score_away`
- `algorithm_id`
- `algorithm_name`
- `signal_type`
- `probability` для алгоритма 1 или `null` для алгоритма 2
- `form_score`
- `h2h_score`
- `live_score`
- `decision`
- `stats`
- `form_data`
- `h2h_data`
- `algorithm_data`

Проект позволяет одному и тому же матчу породить два независимых результата: по алгоритму 1 и по алгоритму 2.

### 5. Telegram-нотификатор сигналов

Файл `backend/scanner/TelegramNotifier.php` отвечает за публикацию сигналов в Telegram-канал.

Что делает:

- форматирует разные тексты для алгоритма 1 и алгоритма 2;
- добавляет inline-кнопку для AI-анализа матча;
- защищает от повторной отправки;
- сохраняет отправленное сообщение в таблицу `bet_messages`.

Дедупликация работает через ключ:

- `match_<id>_algorithm_<algorithm_id>`

Состояние хранится в JSON-файле, путь к которому задается через `SCANNER_STATE_PATH`.
В Docker-окружении по умолчанию используется `/data/scanner_state.json` во внешнем volume.

Есть миграционная нормализация старых ключей:

- `match_<id>`
- `match_<id>_min_<minute>`

Они автоматически переводятся в формат алгоритма 1.

### 6. Проверка исхода отправленных ставок

После публикации сигналов проект отдельно отслеживает, зашла ставка или нет.

Ключевые файлы:

- `backend/scanner/BetChecker.php`
- `backend/bet_checker.php`
- `backend/bet_stats.php`

Как определяется результат:

- если в первом тайме до или на 45-й минуте был забит хотя бы один мяч, ставка считается `won`;
- если на перерыве счет `0:0`, ставка считается `lost`;
- иначе ставка остается `pending`.

Что происходит при фиксации результата:

- обновляется текст исходного Telegram-сообщения;
- в таблице `bet_messages` меняется `bet_status`;
- проставляется `checked_at`.

### 7. Telegram-бот как пользовательский и админский интерфейс

Это отдельный long-polling бот, который работает постоянно.

Ключевые файлы:

- `backend/telegram_bot.php`
- `backend/bans/router.php`
- `backend/bans/handlers_message.php`
- `backend/bans/handlers_callback.php`
- `backend/telegram/public_handlers.php`
- `backend/telegram/TelegramAiRepository.php`
- `backend/telegram/GeminiMatchAnalyzer.php`
- `backend/telegram/GeminiPoolAnalyzer.php`

#### Публичные команды пользователя

Поддерживаются:

- `/start`
- `/balance`
- `/buy`

Что умеет пользователь:

- зарегистрироваться в таблице `telegram_users`;
- получить стартовый trial-баланс;
- посмотреть текущий AI-баланс;
- запросить AI-анализ матча через кнопку под сигналом;
- получить анализ в личные сообщения.

#### Админские команды в Telegram

Поддерживаются:

- `/bans`
- `/bans_list`
- `/bans_add`
- `/bans_edit <id>`
- `/bans_del <id>`
- `/grant_balance <telegram_user_id> <amount>`
- `/gemini_key_add <key>`
- `/gemini_key_list`
- `/gemini_key_on <id>`
- `/gemini_key_off <id>`
- `/gemini_model_add <model>`
- `/gemini_model_list`
- `/gemini_model_on <id>`
- `/gemini_model_off <id>`

Для банов сделан wizard/stateful-режим с inline-кнопками и хранением промежуточного состояния.

Состояние бота хранится в JSON-файле, путь к которому задается через `TELEGRAM_BOT_STATE_PATH`.
В Docker-окружении по умолчанию используется `/data/telegram_state.json` во внешнем volume.
Файл содержит:

- `last_update_id` для long polling;
- per-user состояние wizard’ов для банов.

#### AI-анализ матча

Это отдельный продуктовый слой поверх сигналов.

Логика:

1. Пользователь нажимает кнопку “AI-анализ”.
2. Из БД собирается контекст матча и последнего сигнала.
3. Контекст дополнительно обогащается локальным scanner score.
4. Проверяется баланс пользователя.
5. Из БД выбираются активные Gemini API-ключи.
6. Из БД выбираются активные Gemini модели.
7. `GeminiPoolAnalyzer` перебирает комбинации ключ/модель, пока не получит успешный ответ.
8. Ответ сохраняется в `ai_analysis_requests`.
9. Пользователь получает разбор в личные сообщения.

Особенности AI-слоя:

- у новых пользователей есть trial-баланс;
- анализ стоит `AI_ANALYSIS_COST`;
- при ошибке анализа баланс возвращается;
- ответы Gemini синхронизируются с внутренним scanner verdict, чтобы AI не “улетал” слишком далеко от базовой модели;
- ключи и модели живут в БД, а не только в `.env`.

### 8. Public API

Файл `backend/api.php` это публичный JSON API.

Поддерживаемые action:

- `GET ?action=get_matches&filter=all|live|finished&limit=100`
- `GET ?action=get_match_details&id=<match_id>`

Что возвращает API:

- группировку матчей по лигам;
- базовую карточку матча;
- live-статус;
- признак, является ли матч кандидатом для ставки по локальной модели API;
- вероятности гола в первом тайме;
- коэффициенты;
- live-статистику;
- в деталях матча расширенные odds и расширенную live-статистику;
- `telegramBetStatus`, если по матчу уже есть отправленный bet message.

Дополнительно:

- включен CORS;
- разрешенные origins берутся из `APP_URL` и `ALLOWED_ORIGINS`;
- API отдает только GET;
- формат ответа унифицирован: `{"ok": true|false, ...}`.

### 9. Admin API

Файл `backend/admin/api.php` это защищенный JSON API для административных сценариев.

Авторизация:

- `Authorization: Bearer <ADMIN_PASSWORD>`
- либо `?token=<ADMIN_PASSWORD>`

Поддерживаемые action:

- `GET ?action=list_bans`
- `POST ?action=add_ban`
- `POST ?action=update_ban`
- `POST ?action=delete_ban`
- `GET ?action=list_matches_stats`
- `GET ?action=get_match_stats&match_id=<id>`
- `POST ?action=refresh_match_stats`
- `POST ?action=refresh_stats_batch`
- `GET ?action=stats_overview`

Что через него можно делать:

- управлять таблицей банов;
- искать матчи по статистическому статусу;
- смотреть все сырые поля матча;
- принудительно обновлять статистику для одного матча или пачки матчей;
- смотреть общее покрытие статистики по БД.

### 10. Health endpoint

Файл `backend/healthz.php` делает простую проверку доступности БД и возвращает JSON-статус сервиса.

### 11. Docker и orchestration

Проект подготовлен для запуска через Docker Compose.

Ключевые файлы:

- `docker-compose.yml`
- `backend/Dockerfile`
- `docker/app-entrypoint.sh`
- `docker/worker-entrypoint.sh`
- `docker/bootstrap-schema.php`
- `.github/workflows/docker-build.yml`

Сервисы:

- `app`
  - Apache + PHP;
  - публикует HTTP backend;
  - использует volume `/data` для runtime-state.
- `worker`
  - запускает `back_start.py`;
  - ждет БД;
  - bootstrap’ит схему;
  - гоняет фоновые задачи.
- `db`
  - MySQL 8.4.
- `phpmyadmin`
  - визуальная админка БД.

GitHub Actions:

- на push/PR собирает Docker image;
- на `main` пушит образ в GHCR.

## Главный runtime-поток проекта

Проект связывается главным образом через `back_start.py`.

Этот файл:

- умеет сам найти PHP-интерпретатор;
- запускает долгоживущий Telegram-бот;
- каждые 1 минуту выполняет pipeline:
  - `backend/live.php`
  - `backend/scanner/ScannerCli.php`
  - `backend/bet_checker.php`
- каждые 5 минут выполняет:
  - `backend/parser.php`
  - затем `backend/stat.php`

То есть жизненный цикл матча в системе выглядит так:

1. `parser.php` кладет матч в БД.
2. `stat.php` догружает историческую статистику и SGI JSON.
3. `live.php` обновляет счет, время и live-метрики.
4. `ScannerCli.php` решает, есть ли сигнал.
5. `TelegramNotifier` публикует сигнал в канал.
6. Пользователь может запросить AI-анализ.
7. `bet_checker.php` позже проверяет, зашла ставка или нет.

## Структура репозитория

### Корень

- `AGENTS.md`
  - локальные инструкции для агентного режима работы по этому репозиторию;
  - не влияет на runtime приложения.
- `back_start.py`
  - главный планировщик фоновых задач и процесса Telegram-бота.
- `docker-compose.yml`
  - локальная инфраструктура проекта.
- `.env.example`
  - шаблон конфигурации.
- `.github/workflows/docker-build.yml`
  - CI для Docker-образа.

### `.agent/`

Это не часть продуктового runtime.
Это набор AI-правил, skill’ов, agent profiles и внутренних workflow для разработки репозитория.

### `backend/`

Главный код продукта.

#### `backend/line/`

Зона ingestion и схемы БД.

- `db.php`
  - подключение к БД;
  - автоматическое создание БД;
  - автоматическое создание и миграция таблиц;
  - upsert матчей;
  - CRUD по банам.
- `extractMatches.php`
  - сборка плоской структуры матча из внешнего фида.
- `extractOdds.php`
  - разбор коэффициентов и линий.
- `extractFm1.php`
  - разбор фор по специальным кодам событий.
- `http.php`
  - устаревшая обертка над новым `HttpClient`.
- `logger.php`
  - простой stdout-логгер с временем по `Europe/Moscow`.
- `env.php`
  - мини-loader `.env`.
- `BanMatcher.php`
  - логика матчинга банов.
- `normalize.php`, `time.php`
  - вспомогательные функции нормализации и времени.
- `scripts/dev/test_extractFm1.php`
  - локальный smoke test разбора фор.

#### `backend/live/`

Live-подсистема.

- `client.php`
  - загрузка live JSON.
- `extract.php`
  - извлечение команд, score, time/status и статистики.
- `update.php`
  - запись live-полей в БД.
- `match.php`
  - сравнение матча из БД и матча из live-фида.
- `json.php`
  - безопасная навигация по вложенным узлам JSON.
- `service.php`
  - orchestration live pipeline.

#### `backend/scanner/`

Сканер сигналов и пост-обработка.

- `DataExtractor.php`
  - вытаскивает form/H2H/live/algorithm2 данные из строки `matches`.
- `ProbabilityCalculator.php`
  - вычисляет score и итоговую вероятность алгоритма 1.
- `MatchFilter.php`
  - условия допуска к ставке по алгоритму 1 и 2.
- `Scanner.php`
  - объединяет вычисления по всем алгоритмам.
- `ScannerCli.php`
  - CLI-вход для запуска сканера.
- `TelegramNotifier.php`
  - Telegram-доставка и дедупликация.
- `BetMessageRepository.php`
  - доступ к `bet_messages`.
- `BetChecker.php`
  - фиксация исходов ставок.
- `README.md`
  - локальная документация подсистемы scanner.
- `scanner_state.json` / `SCANNER_STATE_PATH`
  - runtime state дедупликации; в production должен жить в volume или ином ignored path.

#### `backend/statistic/`

Подсистема статистики по SGI.

- `Config.php`
  - читает настройки статистики из env.
- `Http.php`
  - HTTP wrapper для statistics.
- `EventsstatClient.php`
  - получает SGI JSON.
- `StatisticRepository.php`
  - выбирает матчи и пишет метрики обратно.
- `HtMetricsCalculator.php`
  - вычисляет HT-метрики формы и H2H.
- `TeamNameNormalizer.php`
  - нормализация имен команд.
- `StatisticService.php`
  - основной сервис обновления статистики.
- `StatisticServiceFactory.php`
  - сборка зависимостей.
- `StatCli.php`
  - CLI-вход.

#### `backend/telegram/`

AI и пользовательские Telegram-сценарии.

- `public_handlers.php`
  - публичные команды, callback AI-кнопки, кредитный UI, enrichment scanner context.
- `TelegramAiRepository.php`
  - пользователи Telegram, балансы, запросы анализа, ключи, модели.
- `GeminiMatchAnalyzer.php`
  - прямой вызов Gemini API.
- `GeminiPoolAnalyzer.php`
  - ротация по ключам и моделям.

#### `backend/bans/`

Админская Telegram-подсистема для банов и служебных команд.

- `router.php`
  - роутинг Telegram update.
- `handlers_message.php`
  - обработка текстовых команд.
- `handlers_callback.php`
  - обработка inline callback.
- `auth.php`
  - проверка админа.
- `context.php`
  - контекст бота.
- `state.php`
  - загрузка/сохранение bot state.
- `ui.php`
  - кнопки и тексты wizard-интерфейса.
- `constants.php`
  - callback-константы.
- `validation.php`
  - нормализация полей wizard.
- `tg_api.php`
  - низкоуровневый транспорт Telegram Bot API.

#### Другие backend entry points

- `api.php`
  - public JSON API.
- `admin/api.php`
  - admin JSON API.
- `healthz.php`
  - health endpoint.
- `parser.php`
  - ingestion линии.
- `live.php`
  - live update.
- `stat.php`
  - статистика SGI.
- `bet_checker.php`
  - проверка исходов ставок.
- `bet_stats.php`
  - CLI статистика ставок.
- `telegram_bot.php`
  - long polling Telegram-бот.

### `docker/`

- `app-entrypoint.sh`
  - стартует Apache.
- `worker-entrypoint.sh`
  - ждет БД, bootstrap’ит схему, запускает `back_start.py`.
- `bootstrap-schema.php`
  - форсирует создание/миграцию схемы БД через `Db::connectFromEnv()`.

### `docs/`

Служебные заметки и ТЗ, а не основная пользовательская документация.

- `todo.md`
- `update.md`

## Схема БД

Схема создается кодом, без внешних миграционных файлов.
Главный источник истины по таблицам это `backend/line/db.php`.

### Таблица `matches`

Главная таблица проекта.

Содержит:

- идентификаторы матча;
- команды и лигу;
- коэффициенты прематча;
- SGI и сырые SGI-данные;
- live-счет и live-статус;
- live-статистику;
- статистические HT-метрики;
- статусы обновления статистики;
- временные метки создания/обновления.

Используется почти всеми подсистемами:

- parser пишет базовые матчи;
- live пишет live-поля;
- statistic пишет historical metrics;
- scanner читает;
- public API читает;
- admin API читает и триггерит обновления;
- AI-сервис берет оттуда контекст матча.

### Таблица `bans`

Используется для исключения матчей из ingestion и для админского управления.

Поля логики:

- `country`
- `liga`
- `home`
- `away`
- `is_active`

Баны применяются во время `parser.php`, до записи матчей в БД.

Как сейчас работает сопоставление:

- строки нормализуются;
- удаляются некоторые служебные stopwords;
- применяется сравнение по exact/substring после нормализации.

### Таблица `bet_messages`

Хранит все отправленные сигналы.

Ключевые поля:

- `match_id`
- `message_id`
- `chat_id`
- `message_text`
- `algorithm_id`
- `algorithm_name`
- `bet_status`
- `sent_at`
- `checked_at`

Нужна для:

- истории сигналов;
- статуса `pending/won/lost`;
- редактирования исходных Telegram-постов;
- AI-контекста для разбора матча;
- статистики по ставкам.

### Таблица `telegram_users`

Хранит пользователей Telegram и их AI-баланс.

Поля:

- `telegram_user_id`
- `username`
- `first_name`
- `last_name`
- `ai_balance`
- `last_interaction_at`

### Таблица `ai_analysis_requests`

Хранит историю AI-запросов.

Поля:

- `telegram_user_id`
- `match_id`
- `bet_message_id`
- `provider`
- `model_name`
- `status`
- `cost_charged`
- `prompt_text`
- `response_text`
- `error_text`

### Таблица `gemini_api_keys`

Хранит Gemini API keys с operational статусом.

Поля:

- `api_key`
- `is_active`
- `last_error`
- `fail_count`
- `last_used_at`

### Таблица `gemini_models`

Хранит список моделей Gemini, которые можно использовать в пуле.

Поля:

- `model_name`
- `is_active`
- `last_error`
- `fail_count`
- `last_used_at`

## Конфигурация через `.env`

Шаблон лежит в `.env.example`.

### HTTP и приложение

- `APP_URL`
- `APP_PORT`
- `ALLOWED_ORIGINS`

Нужны для:

- CORS;
- публикации backend;
- интеграции с внешним интерфейсом.

### База данных

- `DB_HOST`
- `DB_PORT`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`

### Docker-специфичные переменные

- `DOCKER_DB_NAME`
- `DOCKER_DB_USER`
- `DOCKER_DB_PASS`
- `DOCKER_DB_ROOT_PASSWORD`
- `DOCKER_DB_FORWARD_PORT`
- `PHPMYADMIN_PORT`

### Telegram

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_ADMIN_IDS`
- `TELEGRAM_CHANNEL_ID`
- `TELEGRAM_REQUIRED_CHANNEL_URL`
- `TELEGRAM_BOT_STATE_PATH`
- `SCANNER_STATE_PATH`
- `TELEGRAM_CUSTOM_EMOJI_WIN_IDS`
- `TELEGRAM_CUSTOM_EMOJI_LOSE_IDS`
- `TELEGRAM_API_ID`
- `TELEGRAM_API_HASH`
- `TELEGRAM_CREDITS_TOPUP_URL`
- `TELEGRAM_AI_BUTTON_TEXT`

### AI / Gemini

- `AI_ANALYSIS_COST`
- `GEMINI_API_KEY`
- `GEMINI_MODEL`

Важно:

- пользовательский AI-пул фактически хранится в БД;
- `GEMINI_API_KEY` и `GEMINI_MODEL` скорее выглядят как базовые или запасные переменные окружения;
- текущая рабочая логика пользовательского AI-анализа ориентирована именно на таблицы `gemini_api_keys` и `gemini_models`.

### Источники данных

- `API_URL`
- `API_URL_LIVE`

### Админка

- `ADMIN_PASSWORD`

### Статистический сервис

- `STAT_API_BASE_URL`
- `STAT_REQUEST_TIMEOUT_MS`
- `STAT_RETRY_COUNT`
- `STAT_SLEEP_MS`
- `STAT_BATCH_LIMIT`
- `STAT_STALE_AFTER_SECONDS`
- `STAT_VERSION`

### Общий HTTP клиент

- `HTTP_TIMEOUT`
- `HTTP_RETRIES`

### Debug/worker

- `DEBUG_BANS`
- `DEBUG_BANS_LIMIT`
- `WORKER_NO_BOT`
- `BACK_START_ARGS`

## CLI-команды проекта

### Общий оркестратор

```bash
python back_start.py
```

Полезные флаги:

- `--once`
- `--no-live`
- `--no-scanner`
- `--no-bet-checker`
- `--no-parserstat`
- `--no-bot`

### Парсер линии

```bash
php backend/parser.php
```

### Live-обновление

```bash
php backend/live.php
```

### Обновление статистики

```bash
php backend/stat.php
```

Дополнительные флаги `StatCli`:

- `--limit`
- `--offset`
- `--force`
- `--match_id`
- `--local_json`
- `--home`
- `--away`

### Сканер

```bash
php backend/scanner/ScannerCli.php
```

Флаги:

- `--json`
- `--verbose`
- `--min-probability=0.70`
- `--no-telegram`

### Проверка исходов ставок

```bash
php backend/bet_checker.php
```

### Статистика ставок

```bash
php backend/bet_stats.php
```

Флаги:

- `--json`
- `--period=today|week|month|all`
- `--recent=10`

### Локальный smoke test extractFm1

```bash
php scripts/dev/test_extractFm1.php
```

## HTTP API и URL’ы

При Docker-запуске по умолчанию:

- backend: `http://localhost:8080`
- health: `http://localhost:8080/backend/healthz.php`
- phpMyAdmin: `http://localhost:8081`

Примеры public API:

```text
GET /backend/api.php?action=get_matches
GET /backend/api.php?action=get_matches&filter=live&limit=50
GET /backend/api.php?action=get_match_details&id=123
```

Примеры admin API:

```text
GET /backend/admin/api.php?action=list_bans&token=YOUR_ADMIN_PASSWORD
GET /backend/admin/api.php?action=stats_overview&token=YOUR_ADMIN_PASSWORD
```

## Как проект реально работает по шагам

### Шаг 1. Поднимается инфраструктура

- Apache/PHP готов отдавать HTTP endpoints.
- Worker ждет готовности MySQL.
- `bootstrap-schema.php` вызывает `Db::connectFromEnv()` и создает/дополняет таблицы.

### Шаг 2. Запускается фоновой цикл

`back_start.py` стартует Telegram-бота и cron-подобные задачи.

### Шаг 3. В БД попадает линия

`parser.php` заполняет `matches` базовыми матчами и коэффициентами.

### Шаг 4. В БД попадает историческая статистика

`stat.php` запрашивает SGI JSON и рассчитывает half-time форму и H2H.

### Шаг 5. В БД попадают live-поля

`live.php` обновляет время, счет и live-метрики.

### Шаг 6. Scanner принимает решение

Для каждого подходящего live-матча сканер прогоняет:

- алгоритм 1;
- алгоритм 2.

Каждый алгоритм формирует свою причину и свой verdict.

### Шаг 7. Telegram получает сигнал

Если хотя бы один алгоритм дал `bet=true`, в канал отправляется пост.

### Шаг 8. Пользователь может запросить AI-разбор

По inline-кнопке бот:

- собирает контекст;
- списывает кредиты;
- обращается к Gemini;
- сохраняет и доставляет ответ.

### Шаг 9. После перерыва определяется исход сигнала

`bet_checker.php` переводит сообщение в `won/lost` и правит исходный Telegram-пост.

## Что в проекте отсутствует

Важно явно зафиксировать, чего в репозитории нет.

- Нет web-frontend приложения на React/Vue/Next.
- Нет отдельного mobile-клиента.
- Нет полноценного production dependency graph в Composer; `composer.json` пока используется как bootstrap для autoload и dev-инструментов.
- Нет полного набора unit/regression-тестов для всех критичных сценариев; smoke-проверка CLI entrypoint уже добавлена, но глубокое покрытие пока есть только у частей `statistic` и `telegram`.
- Нет миграций в отдельной папке; схема живет в коде `Db::ensureSchema()`.
- Нет отдельного domain layer или framework уровня Laravel/Symfony.

Это в первую очередь процедурный PHP backend с ручной модульной структурой.

## Уровень зрелости и архитектурные выводы

По коду видно, что проект уже решает реальные эксплуатационные задачи, а не является только прототипом.

Признаки этого:

- автоматическое создание и доращивание схемы БД;
- отдельный worker и отдельный HTTP app;
- отдельный admin API;
- хранение операционного состояния в JSON и БД;
- дедупликация сигналов;
- учет жизненного цикла ставки;
- ротация Gemini ключей и моделей;
- возврат пользовательского баланса при ошибках AI;
- health endpoint;
- Docker и GitHub Actions.

При этом архитектура остается сравнительно простой:

- procedural entry points;
- вручную собранные зависимости через `require_once`;
- бизнес-логика группируется по папкам, а не через контейнер DI;
- значительная часть схемы и миграций зашита в `db.php`.

Это делает систему понятной для сопровождения без фреймворка, но увеличивает роль ручной дисциплины в изменениях.

## Что полезно знать перед доработкой проекта

Если планируются изменения, критично помнить следующее.

### Изменения в `matches` влияют почти на все

Таблица `matches` это центральный контракт для:

- parser;
- live;
- statistic;
- scanner;
- public API;
- admin API;
- Telegram AI.

Любое переименование поля здесь почти наверняка затронет несколько подсистем.

### Scanner завязан на статистику

Алгоритм 1 и алгоритм 2 оба сильно зависят от данных, которые готовит `stat.php`.
Если статистика не обновляется, часть scanner логики будет постоянно работать в режиме “данных недостаточно”.

### Telegram AI завязан на БД, а не только на env

Даже если в `.env` есть `GEMINI_API_KEY`, пользовательский runtime-анализ ищет активные ключи и модели именно в таблицах:

- `gemini_api_keys`
- `gemini_models`

### Дедупликация сигналов зависит от `algorithm_id`

Один и тот же матч может породить два сигнала.
Это уже заложено в state key и в `bet_messages`.

### Бот совмещает публичные и админские сценарии

`telegram_bot.php` и handlers работают и как публичный продуктовый бот, и как инструмент администрирования.
Изменения в роутинге сообщений нужно делать аккуратно, чтобы не сломать одну из ролей.

## Быстрый старт

### Через Docker

1. Скопировать `.env.example` в `.env`.
2. Заполнить как минимум:
   - `DB_*`
   - `API_URL`
   - `API_URL_LIVE`
   - `ADMIN_PASSWORD`
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_ADMIN_IDS`
   - `TELEGRAM_CHANNEL_ID`
3. Поднять проект:

```bash
docker compose up -d --build
```

4. Проверить health:

```bash
http://localhost:8080/backend/healthz.php
```

5. Проверить public API:

```bash
http://localhost:8080/backend/api.php?action=get_matches
```

6. При необходимости прогнать локальные dev-проверки:

```bash
composer install
composer test:scanner
composer test:smoke
composer test:statistic
composer test:telegram
```

### Без Docker

Нужны:

- PHP 8.1+ с `pdo_mysql`, `curl`, `mbstring`, `intl`;
- MySQL/MariaDB;
- Composer;
- Python 3 для `back_start.py`.

Дальше стоит сначала установить dev-зависимости:

```bash
composer install
```

После этого можно запускать отдельные entry point’ы вручную:

```bash
php backend/parser.php
php backend/live.php
php backend/stat.php
php backend/scanner/ScannerCli.php --verbose
php backend/bet_checker.php
php backend/telegram_bot.php
python back_start.py --once
```

Отдельно можно прогнать текущие PHPUnit-наборы:

```bash
composer test:smoke
composer test:scanner
composer test:statistic
composer test:telegram
```

## Итог

`Proxbet` это backend-платформа для live-аналитики футбольных матчей и Telegram-сигналинга.
Ее центральная идея: собирать матч из нескольких источников данных, объединять prematch-линию, live-статистику и исторические half-time метрики, а затем конвертировать это в:

- алгоритмические сигналы;
- Telegram-уведомления;
- AI-разборы;
- статистику результата сигналов;
- API для внешнего использования.

Если кратко по сути проекта, то он делает полный цикл:

`внешний фид -> БД матчей -> live/stat enrichment -> scanner -> Telegram signal -> AI analysis -> итог ставки`.
