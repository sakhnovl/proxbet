# Техническое задание и TODO по аудиту, рефакторингу и подготовке Proxbet к деплою

## 1. Цель документа

Подготовить проект `Proxbet` к безопасному, предсказуемому и поддерживаемому production-деплою.

Итог работ должен закрыть 4 направления:

1. Безопасность: убрать критические и высокие риски, выровнять аутентификацию, секреты, CORS, health endpoints, логи и доступ к инфраструктуре.
2. Рефакторинг: упростить структуру проекта, убрать дублирование и рассинхронизацию между legacy-скриптами, сервисами и документацией.
3. Производительность: уменьшить нагрузку на БД и runtime, стабилизировать batch-обработку, кэширование, cron/pipeline и мониторинг.
4. Деплойная готовность: сделать единый и воспроизводимый путь запуска, проверки, миграций, smoke-тестов и rollback.

## 2. Что было проанализировано

Проверены:

- структура репозитория;
- `composer.json`, `docker-compose.yml`, `backend/Dockerfile`, `README.md`, `.env.example`;
- основные entrypoint-файлы backend;
- security-слой в `backend/security/*`;
- модули `line`, `live`, `scanner`, `statistic`, `telegram`, `admin`, `api`;
- smoke/tests/phpstan;
- инфраструктурные конфиги в `config/*`;
- скрипты миграций, бэкапов и security-проверок.

Бысткая сводка по проекту:

- Язык: PHP 8.2/8.3 + Python для orchestration/scheduler.
- Архитектура: набор CLI/API entrypoint-скриптов без единой фронт-контроллерной схемы.
- Домен: ingestion матчей, live-обновления, статистика, сигналы, Telegram automation, admin/public API.
- Объём кода: примерно `263` PHP-файла.
- Тестовые файлы: примерно `57` `*Test.php`.

## 3. Текущее состояние проекта

### 3.1 Сильные стороны

- Есть разделение по доменам: `line`, `live`, `scanner`, `statistic`, `telegram`, `security`, `admin`, `api`.
- Есть PHPUnit-тесты по нескольким подсистемам.
- Уже присутствуют заготовки для security-слоя:
  - rate limiting;
  - CSRF;
  - JWT/auth helpers;
  - security headers;
  - API key auth;
  - audit logging;
  - encryption/secrets rotation.
- Есть Docker-сборка и docker-compose окружение.
- Есть мониторинг и observability-контур: Prometheus, Grafana, Loki, Alertmanager.

### 3.2 Основные проблемы

- Проект сочетает legacy procedural entrypoints и новую service/repository-архитектуру, из-за чего правила работы расходятся.
- Документация и пример конфигурации частично не соответствуют реальному коду.
- Не все security-компоненты реально встроены в entrypoints.
- Статический анализ показывает большой технический долг.
- Smoke и часть тестов уже сигнализируют о расхождении ожиданий и реализации.
- В проекте много operational scripts, но нет одного жёстко определённого production runbook.

## 4. Фактические результаты проверок

### 4.1 PHPUnit

Успешно:

- `composer test:line` -> OK, `30 tests`, `84 assertions`
- `composer test:statistic` -> OK, `20 tests`, `83 assertions`

Проблемы:

- `composer test:scanner` -> FAIL, `3` падения в `AlgorithmX`
  - ожидается ошибка по `status`, фактически приходит `minute 90 exceeds maximum 45`
- `composer test:telegram` -> тесты зелёные, но команда завершается с warning
  - `No code coverage driver available`

### 4.2 Smoke

- `composer test:smoke` -> FAIL
- проблема в кейсе `bet_checker`
- ожидаемый текст smoke-теста не совпадает с фактическим текстом ошибки
- это симптом рассинхронизации между runtime и тестовой спецификацией

### 4.3 PHPStan

- `vendor\bin\phpstan analyse --no-progress` -> FAIL
- найдено `290` ошибок статического анализа

Типовые проблемы:

- отсутствующие классы исключений;
- вызовы несуществующих методов;
- недоописанные типы;
- unreachable branches;
- рассинхрон интерфейсов и реализаций;
- ошибки вокруг security/core abstractions.

Это означает, что кодовая база ещё не находится в состоянии, пригодном для безопасного масштабирования и предсказуемого деплоя.

## 5. Критические и высокие риски

### 5.1 Критические

1. Конфигурация окружения рассинхронизирована.
   - `.env.example` использует `DB_PASSWORD`, `ADMIN_API_PASSWORD`
   - runtime использует `DB_PASS`, `ADMIN_PASSWORD`
   - часть bash-скриптов тоже ждёт `DB_PASSWORD`
   - результат: высокий риск ложной “готовности” окружения и падений после деплоя

2. Admin auth реализован через прямое сравнение bearer token с `ADMIN_PASSWORD`.
   - это лучше, чем query param, но всё ещё слабее нормальной схемы с отдельным admin identity, rotation и ограничением scope
   - в `README.md` до сих пор описаны query-параметры токена

3. Health endpoints раскрывают внутреннее состояние без гарантированной защиты.
   - есть `HealthEndpointAuth`, но `backend/healthz.php` и `backend/healthz_enhanced.php` не используют его явно
   - это создаёт риск утечки operational information наружу

4. В кодовой базе есть незавершённые и частично интегрированные security abstraction layers.
   - пример: `ApiKeyAuth`, `CsrfProtection`, `HealthEndpointAuth`, `SecretsRotation`
   - часть из них не встроена системно в публичные/admin entrypoints

### 5.2 Высокие

1. Статический анализ показывает сломанные контракты в `core/security`.
2. Security/infra логика распределена между procedural файлами и сервисами.
3. Нет единой матрицы доступа для public API, admin API, Telegram webhook/bot и health endpoints.
4. Docker compose включает слишком много сервисов сразу для базового деплоя.
5. Redis-кэш не является обязательной зависимостью, но код регулярно пишет ошибки вида `Class "Redis" not found`.
6. В README и smoke/tests зафиксированы уже устаревшие сценарии использования.
7. CI настроен недостаточно жёстко: в workflow есть шаги качества с `|| true`, поэтому pipeline может быть зелёным даже при реальных дефектах.
8. `backend/metrics.php` публикует Prometheus-метрики без явной защиты, rate limit и access policy.
9. `backend/alert_webhook.php` принимает Alertmanager payload без явной подписи, секретного токена или allowlist-ограничения источника.

### 5.3 Средние

1. Часть SQL строится динамически, пусть и в контролируемых местах.
2. Есть procedural duplicate entrypoints рядом с новыми handlers/services.
3. Scheduler в `back_start.py` выполняет длинную последовательную pipeline-цепочку, что может приводить к накоплению задержек.
4. Не видно единого миграционного механизма уровня production release.
5. Эксплуатационный контур раздвоен: проект одновременно предполагает запуск через Docker worker, `back_start.py`, `systemd` и `supervisor`, но единый canonical runtime не зафиксирован.

## 6. Целевое состояние проекта

После выполнения этого ТЗ проект должен соответствовать следующим условиям:

1. Любая production-конфигурация поднимается из одного понятного набора env-переменных без расхождений.
2. Все внешние точки входа имеют явную схему auth/authz, rate limit, logging и error handling.
3. Health endpoints безопасны и делятся на:
   - public liveness;
   - protected readiness/diagnostics.
4. Public/admin API имеют единый bootstrap и единые middleware/security policies.
5. Структура директорий отражает домены и слои, а не исторические сценарии.
6. Статический анализ, unit/integration/smoke проходят в CI.
7. Деплой описан как повторяемый runbook с rollback и post-deploy checks.

## 7. Полное техническое задание

### 7.1 Поток 1. Security hardening

#### 7.1.1 Конфигурация и секреты

Нужно:

- унифицировать naming env-переменных;
- выбрать один канонический набор переменных:
  - `DB_HOST`
  - `DB_PORT`
  - `DB_NAME`
  - `DB_USER`
  - `DB_PASS`
  - `ADMIN_PASSWORD` или, лучше, `ADMIN_API_TOKEN`
  - `TELEGRAM_BOT_TOKEN`
  - `ENCRYPTION_KEY`
  - `APP_URL`
  - `ALLOWED_ORIGINS`
- убрать параллельное использование `DB_PASSWORD`/`DB_PASS`, `ADMIN_API_PASSWORD`/`ADMIN_PASSWORD`;
- синхронизировать `.env.example`, `README.md`, shell scripts, smoke tests и runtime;
- описать обязательные и опциональные env;
- внедрить fail-fast validation при запуске контейнера/процесса.

Критерии приёмки:

- существует единый список env-переменных;
- `.env.example` полностью соответствует runtime;
- smoke-тесты валидируют актуальные сообщения и актуальные env names;
- не осталось файлов, использующих устаревшие env names.

#### 7.1.2 Аутентификация и авторизация

Нужно:

- формализовать отдельные модели доступа:
  - public API;
  - admin API;
  - Telegram webhook/bot;
  - health endpoints;
  - alert/webhook endpoints;
- убрать legacy-упоминания query-token auth из документации и всех примеров;
- решить, какая модель будет целевой для admin API:
  - short-lived JWT;
  - hashed static admin token;
  - API key c permissions;
- для admin API внедрить:
  - rotation;
  - audit trail;
  - rate limit per client/token;
  - чёткий 401/403 contract.

Критерии приёмки:

- admin API не зависит от “голого” пароля как bearer secret без описанной ротации;
- документация совпадает с реализацией;
- доступы покрыты тестами.

#### 7.1.3 Health/security boundaries

Нужно:

- разделить health endpoints:
  - `/backend/healthz.php` -> минимальный public liveness;
  - `/backend/healthz_enhanced.php` -> только под auth/IP allowlist;
- определить политику доступа к `/backend/metrics.php`:
  - internal-only;
  - reverse-proxy allowlist;
  - basic auth / bearer auth;
- внедрить `HealthEndpointAuth` или заменить на единый middleware;
- исключить раскрытие деталей подключения к БД, Redis, disk/memory без авторизации;
- проверить `alert_webhook.php` и иные webhooks на подпись и ограничение источников.

Критерии приёмки:

- неаутентифицированный запрос видит только безопасный минимальный статус;
- operational details видны только доверенным клиентам;
- metrics endpoint не доступен публично без явного решения и документации;
- есть тесты на 401/403/200 сценарии.

#### 7.1.4 Input validation, CSRF, headers, CORS

Нужно:

- определить, где CSRF реально нужен, а где используется stateless token auth и CSRF избыточен;
- убрать “мертвые” security layers или реально встроить их;
- унифицировать применение:
  - `SecurityHeaders`;
  - `RequestValidator`;
  - `RateLimiter`;
  - `InputValidator`;
  - CORS policy;
- проверить whitelist origins и политику credentials;
- формально описать разрешённые методы и headers по endpoint groups.

Критерии приёмки:

- все HTTP entrypoints используют единый bootstrap pipeline;
- нет security-классов, которые существуют, но не участвуют в реальном request lifecycle;
- CORS настроен только на нужные origin'ы.

#### 7.1.5 Логи, аудит, утечки данных

Нужно:

- проверить, что в логах не пишутся токены, ключи, сырой `.env`, SQL stack traces;
- централизовать логирование ошибок;
- ввести redaction policy;
- унифицировать audit logging для admin/security событий.

Критерии приёмки:

- чувствительные поля редактируются/маскируются;
- security-события можно отследить отдельно;
- лог-формат одинаковый для CLI/API.

#### 7.1.6 Webhook и observability security

Нужно:

- защитить `backend/alert_webhook.php`:
  - secret token в header/query только как временная мера;
  - лучше внутренний network boundary + allowlist + отдельный shared secret;
- проверить, не раскрывает ли `metrics.php` чувствительные operational данные;
- определить, какие monitoring endpoints доступны только из внутренней сети;
- зафиксировать схему безопасной интеграции `Prometheus -> Alertmanager -> app webhook -> Telegram`.

Критерии приёмки:

- webhook не принимает анонимные POST из внешней сети;
- observability endpoints не раскрывают лишние данные наружу;
- схема доступа описана в deploy docs.

### 7.2 Поток 2. Архитектурный рефакторинг

#### 7.2.1 Упорядочивание структуры проекта

Цель:

- сделать структуру понятной для нового разработчика и production support.

Нужно:

- описать и внедрить целевую структуру слоёв:
  - `bootstrap`
  - `Http`
  - `Cli`
  - `Domain`
  - `Infrastructure`
  - `Security`
  - `Support`
- минимизировать прямую работу entrypoints с глобальными функциями и raw PDO;
- постепенно переносить procedural логику в сервисы/handlers/repositories;
- определить, какие файлы считаются legacy и подлежат декомпозиции.

Критерии приёмки:

- у каждого entrypoint минимальный orchestration-код;
- бизнес-логика живёт в сервисах;
- структура описана в docs.

#### 7.2.2 Устранение дублирования и рассинхронизации

Нужно:

- сравнить и консолидировать логику в:
  - `backend/admin/api.php` и `backend/admin/Handlers/*`
  - `backend/line/Db.php`, `backend/line/MatchRepository.php`, `backend/core/Services/*`
  - security helpers и фактические вызовы
- убрать дублированные SQL/валидации/response helpers;
- определить единый подход к ошибкам и JSON responses.

Критерии приёмки:

- не остается двух параллельных реализаций одной и той же операции без веской причины;
- уменьшается количество procedural helper blocks внутри entrypoints.

#### 7.2.3 Исправление contracts/core слоя

Нужно:

- привести `core` и `security` к согласованным интерфейсам;
- устранить отсутствующие классы исключений и несуществующие методы;
- добить строгую типизацию там, где PHPStan уже показывает реальные нарушения;
- очистить недоступный код и неверные PHPDoc.

Критерии приёмки:

- PHPStan перестаёт падать на сломанных символах и контрактах;
- core слой становится базой для дальнейшего безопасного рефакторинга.

### 7.3 Поток 3. Производительность и снижение нагрузки

#### 7.3.1 База данных

Нужно:

- провести ревизию запросов к таблицам:
  - `matches`
  - `bans`
  - `bet_messages`
  - `telegram_users`
  - `ai_analysis_requests`
  - `gemini_api_keys`
  - `gemini_models`
- проверить фактическое покрытие индексами под:
  - активные live-матчи;
  - выборки по `stats_fetch_status`, `stats_refresh_needed`;
  - обновления по `evid`;
  - bet checking;
  - AI-related access patterns;
- запретить неограниченные тяжёлые select'ы на production paths;
- формализовать batch sizes и limits.

Критерии приёмки:

- для hot queries есть таблица “query -> index -> justification”;
- нет тяжёлых запросов без лимитов на публичных маршрутах;
- миграции индексов вынесены в повторяемый deploy step.

#### 7.3.2 Scheduler/pipeline

Нужно:

- проанализировать последовательность `live -> scanner -> bet_checker -> cleanup`;
- измерить длительность каждого шага;
- решить, что можно распараллелить, а что нужно сериализовать;
- исключить overlap и накопление хвоста задач;
- ввести timeout, retry policy и dead-letter/recovery policy для критичных шагов.

Критерии приёмки:

- pipeline не блокирует сам себя;
- длительность шага и статус выполнения наблюдаемы;
- при падении одного шага система не зависает бесконтрольно.

#### 7.3.3 Кэш и Redis

Нужно:

- решить, Redis в проекте обязательный или optional;
- если optional:
  - убрать шумные ошибки в тестах и runtime;
  - ввести явный graceful fallback;
- если mandatory:
  - добавить расширение/проверку на старте;
  - сделать health/readiness зависимым от него там, где нужно;
- описать cache invalidation policy.

Критерии приёмки:

- отсутствие Redis не приводит к засорению логов и не маскирует реальные ошибки;
- кэш ведёт себя предсказуемо в dev/test/prod.

### 7.4 Поток 4. Подготовка к production deployment

#### 7.4.1 Docker и инфраструктура

Нужно:

- разделить профили окружений:
  - local/dev;
  - prod-minimal;
  - prod-observability;
- не тащить весь мониторинговый стек в обязательный базовый запуск;
- проверить образ на:
  - размер;
  - запуск под non-root где возможно;
  - необходимые php extensions;
  - reproducible build;
- описать persistent volumes и backup boundaries.

Критерии приёмки:

- есть минимальный production compose/profile;
- можно поднять приложение без phpMyAdmin/Grafana/Loki, если это не нужно;
- образ стабильно стартует с валидированным env.

#### 7.4.2 CI/CD и quality gates

Нужно:

- внедрить pipeline проверок:
  - `phpunit`
  - `phpstan`
  - smoke
  - security regression checks
- убрать permissive-паттерны вида `|| true` из quality-критичных шагов;
- выровнять GitHub Actions с реальными composer-командами проекта;
- проверить актуальность coverage-шага и его зависимостей;
- разделить обязательные и informational проверки;
- запретить деплой при падении критичных quality gates.

Критерии приёмки:

- перед релизом есть формальный список успешных проверок;
- деплой не происходит при красном smoke/phpstan/unit critical.

#### 7.4.4 Runtime orchestration

Нужно:

- выбрать один canonical production-способ запуска фоновых процессов:
  - Docker worker;
  - `systemd`;
  - `supervisor`;
  - отказ от части вариантов;
- описать ownership для:
  - `telegram_bot.php`;
  - scheduler/pipeline;
  - long-running worker processes;
- убрать противоречия между `back_start.py`, docker-entrypoints и сервисными unit-файлами;
- определить стратегию graceful shutdown и restart policy для каждого процесса.

Критерии приёмки:

- у проекта есть один официальный production runtime path;
- остальные варианты либо удалены, либо помечены как dev/legacy;
- эксплуатация не требует догадок, какой процесс считается основным.

#### 7.4.3 Миграции, backup, rollback

Нужно:

- определить единый порядок:
  - backup;
  - schema migration;
  - app deploy;
  - smoke;
  - rollback on failure;
- выровнять SQL migrations и PHP migration scripts;
- документировать совместимость версий схемы и кода.

Критерии приёмки:

- релиз можно воспроизвести по runbook;
- rollback не зависит от ручной импровизации.

## 8. Подробный TODO с приоритетами

## P0. Блокеры перед деплоем

- [x] Синхронизировать env naming во всех слоях: `.env.example`, runtime, `README.md`, smoke, shell scripts.
  - ✅ `.env.example` обновлён: `DB_PASS` вместо `DB_PASSWORD`
  - ✅ Скрипты backup/restore обновлены
  - ⚠️ `README.md` требует обновления (см. P1)
- [x] Устранить расхождения вокруг `ADMIN_PASSWORD`/`ADMIN_API_PASSWORD`, `DB_PASS`/`DB_PASSWORD`.
  - ✅ Все используют `ADMIN_PASSWORD` и `DB_PASS`
- [x] Исправить `composer test:smoke` для актуального runtime behavior.
  - ✅ `scripts/smoke/cli_entrypoints.php` обновлён под актуальную fail-fast ошибку `bet_checker`
- [x] Исправить падения `composer test:scanner` по `AlgorithmX`.
  - ✅ `AlgorithmX\DataValidator` теперь считает `Finished/full time/FT` невалидным статусом до minute-check
- [x] Закрыть раскрытие internal health diagnostics без авторизации.
  - ✅ `healthz.php` - минимальный public endpoint
  - ✅ `healthz_enhanced.php` - защищён опциональной аутентификацией
- [x] Защитить `metrics.php` и `alert_webhook.php` от анонимного внешнего доступа.
  - ✅ `metrics.php` - опциональная аутентификация через `METRICS_AUTH_ENABLED`
  - ✅ `alert_webhook.php` - защита через `ALERT_WEBHOOK_SECRET`
- [x] Убрать устаревшие примеры query-token auth из `README.md`.
  - ✅ `README.md` и docblock `backend/admin/api.php` переведены на Bearer-only auth
- [x] Убрать permissive quality steps из CI, где ошибки сейчас маскируются через `|| true`.
  - ✅ `.github/workflows/tests.yml` больше не маскирует падения PHPStan и PHP CS Fixer
- [x] Сформировать production security matrix по всем HTTP entrypoints.
  - ✅ Создан `docs/http-security-matrix.md`

## P1. Высокий приоритет

- [x] Свести `admin/api.php` и `admin/Handlers/*` к одной архитектурной модели.
  - ✅ `backend/admin/api.php` теперь делегирует CRUD/stats в `admin/Handlers/*`, а не дублирует бизнес-логику
- [x] Выбрать и внедрить целевую auth-схему для admin API.
  - ✅ canonical auth теперь `Authorization: Bearer <ADMIN_API_TOKEN>` с временным fallback на `ADMIN_PASSWORD`
- [x] Встроить единый HTTP bootstrap/middleware pipeline.
  - ✅ Добавлен общий `backend/bootstrap/http.php`, через него проходят public/admin/health/metrics/webhook entrypoints
- [x] Привести `core/security` к консистентным интерфейсам.
  - ✅ Выровнены `AuditLogger`, `RateLimiter`, `TelegramRateLimiter`, `DatabaseQueryGuard`, `StructuredLogger`
- [x] Убрать PHPStan ошибки класса “missing symbols / wrong method calls / broken contracts”.
  - ✅ Убраны missing symbols / wrong method calls / broken contracts; полный долг PHPStan снижен с `290` до `148`, но типовые предупреждения ещё остались
- [x] Разделить public liveness и protected readiness endpoints.
  - ✅ `healthz.php` оставлен минимальным public liveness, `healthz_enhanced.php` - protected readiness/diagnostics
- [x] Сформировать production-ready Docker profile.
  - ✅ Базовый `docker compose up -d --build` теперь поднимает minimal runtime (`app`, `worker`, `db`), а `dev`/`cache`/`observability` вынесены в profiles
- [x] Определить политику Redis: mandatory или optional.
  - ✅ Политика зафиксирована как optional: отсутствие расширения `Redis` больше не ломает health/state contracts, кэш включается отдельным profile
- [x] Зафиксировать один canonical runtime для фоновых процессов и cron/pipeline.
  - ✅ canonical runtime зафиксирован как Docker `worker` -> `docker/worker-entrypoint.sh` -> `back_start.py`; ручной запуск оставлен только для локальной диагностики

## P2. Средний приоритет

- [x] Рефакторинг procedural entrypoints в thin controllers/commands.
- [x] Консолидация query building и DB access patterns.
- [x] Ревизия индексов и hot queries.
- [x] Ревизия batch sizes и pipeline scheduling.
- [x] Очистка логирования и redaction policy.
- [x] Обновление документации по реальному запуску, деплою и recovery.
  - ✅ entrypoints `parser/live/stat/bet_checker/cleanup` переведены в thin command wrappers, cleanup вынесен в `CleanupService`
  - ✅ добавлен общий `backend/core/Database/PdoQueryHelper.php` и применён в `MatchRepository`, `StatisticRepository`, `AuditLogger`
  - ✅ ревизия hot queries оформлена в `docs/db-performance-review.md`, schema bootstrap и performance migration обновлены новыми индексами
  - ✅ scanner и upsert получили управляемые batch size через `SCANNER_MATCH_BATCH_SIZE` и `MATCH_UPSERT_BATCH_SIZE`
  - ✅ scheduler получил env-настройки интервалов и initial delay: `BACK_START_*_INTERVAL_SEC`, `BACK_START_*_INITIAL_DELAY_SEC`
  - ✅ redaction policy оформлена в `docs/logging-redaction-policy.md`, фильтрация усилена для audit/performance/runtime логов
  - ✅ runbook по deploy/recovery/rollback оформлен в `docs/deploy-recovery-runbook.md`

## P3. Низкий, но желательный приоритет

- [x] Декомпозиция `README.md` на smaller docs.
- [x] Введение ADR по ключевым архитектурным решениям.
- [x] Улучшение developer experience: make-like commands или unified task runner.
- [x] Расширение e2e/security regression набора.

## 9. План выполнения по этапам

### Этап 1. Stabilize before refactor

Задачи:

- закрыть P0-блокеры;
- починить env consistency;
- добиться зелёного smoke;
- устранить критичные security gaps на внешних endpoints.

Результат этапа:

- окружение запускается предсказуемо;
- документация не врёт;
- deploy не ломается на базовой конфигурации.

### Этап 2. Security and contracts cleanup

Задачи:

- выровнять auth/authz;
- внедрить protected health;
- привести `core/security` к рабочим контрактам;
- сократить критичные PHPStan ошибки.

Результат этапа:

- security-модель проекта прозрачна и проверяема;
- core слой не содержит явных structural defects.

### Этап 3. Architecture and performance refactor

Задачи:

- вытащить бизнес-логику из entrypoints;
- консолидировать repositories/services;
- провести query/index optimization;
- стабилизировать scheduler/pipeline.

Результат этапа:

- скрипты становятся легче;
- структура проекта становится понятнее;
- нагрузка на runtime и БД снижается.

### Этап 4. Deployment hardening

Задачи:

- сделать production profile;
- собрать CI/CD quality gates;
- оформить runbook, backup, rollback, post-deploy checks.

Результат этапа:

- проект готов к воспроизводимому деплою и сопровождению.

## 10. Критерии готовности проекта к деплою

Проект считается готовым к production deployment только если выполнены все условия:

- [x] `.env.example` полностью совпадает с runtime expectations.
- [x] `README.md` и `docs/*` не содержат устаревших способов auth/run.
- [x] `composer test:line` green.
- [x] `composer test:scanner` green.
- [x] `composer test:statistic` green.
- [x] `composer test:telegram` green без блокирующих warning/error.
- [x] `composer test:smoke` green.
- [ ] `phpstan` проходит на согласованном уровне.
- [x] public/admin/health endpoints покрыты тестами безопасности.
- [x] есть backup + rollback runbook.
- [x] есть production compose/profile и post-deploy checklist.

## 11. Рекомендуемые команды верификации после выполнения работ

```powershell
composer test:line
composer test:scanner
composer test:statistic
composer test:telegram
composer test:smoke
vendor\bin\phpstan analyse --no-progress
```

Дополнительно для pre-deploy:

```powershell
docker compose config
docker compose up -d --build
curl http://localhost:8080/backend/healthz.php
```

## 12. Примечания по текущим найденным несоответствиям

### ✅ ИСПРАВЛЕНО (2026-03-22)

- ✅ `.env.example` синхронизирован: теперь использует `DB_PASS` вместо `DB_PASSWORD`
- ✅ Скрипты `backup_database.sh` и `restore_database.sh` обновлены для использования `DB_PASS`
- ✅ `backend/healthz.php` переделан в минимальный public liveness endpoint
- ✅ `backend/healthz_enhanced.php` защищён через `HealthEndpointAuth` с опциональной аутентификацией
- ✅ `backend/metrics.php` защищён опциональной аутентификацией через `METRICS_AUTH_ENABLED` и `METRICS_SECRET_TOKEN`
- ✅ `backend/alert_webhook.php` защищён через `ALERT_WEBHOOK_SECRET` с проверкой Bearer token или X-Webhook-Secret header
- ✅ `scripts/smoke/cli_entrypoints.php` синхронизирован с актуальным fail-fast сообщением `bet_checker`
- ✅ `AlgorithmX\DataValidator` снова валидирует завершённый матч раньше minute-check, `composer test:scanner` зелёный
- ✅ `README.md` и `backend/admin/api.php` очищены от legacy query-token auth примеров
- ✅ `.github/workflows/tests.yml` больше не скрывает проблемы через `|| true`
- ✅ Создан `docs/http-security-matrix.md` с production access policy для HTTP entrypoints
- ✅ `composer test:telegram` больше не падает из-за `No code coverage driver available`
- ✅ `tests/security/*` и `tests/e2e/*` корректно пропускают HTTP regression-проверки, если локальный runtime не поднят
- ✅ `scripts/test_security_phase1.php` синхронизирован с canonical admin Bearer auth (`ADMIN_API_TOKEN` с fallback на `ADMIN_PASSWORD`)
- ✅ Добавлены новые переменные окружения в `.env.example`:
  - `HEALTH_AUTH_ENABLED`, `HEALTH_AUTH_USERNAME`, `HEALTH_AUTH_PASSWORD`, `HEALTH_ALLOWED_IPS`
  - `METRICS_AUTH_ENABLED`, `METRICS_SECRET_TOKEN`
  - `ALERT_WEBHOOK_SECRET`
  - `API_URL_LIVE` (было пропущено)

### ⚠️ ТРЕБУЕТ ВНИМАНИЯ

- ⚠️ PHPStan всё ещё находит **149 ошибок** статического анализа:
  - в основном `missingType.*`, `nullCoalesce.offset`, `ternary.elseUnreachable`
  - class/method contract blockers из security/core слоя уже устранены
- ✅ README и `docs/*` синхронизированы по canonical runtime, deploy runbook и rollback-документ вынесены в `docs/deploy-recovery-runbook.md`
- ✅ `README.md` декомпозирован: добавлены `docs/project-overview.md`, `docs/runtime-architecture.md`, `docs/http-api-reference.md`, `docs/data-model.md`, `docs/developer-workflow.md`
- ✅ Введён ADR-архив в `docs/adr/*` для ключевых решений по runtime, health endpoints, admin auth и optional Redis
- ✅ Добавлен unified task runner `php scripts/task.php ...` и composer alias-команды `test:security`, `test:e2e`, `test:regression`, `validate`
- ✅ Расширены e2e/security regression тесты для `healthz.php`, `healthz_enhanced.php`, `metrics.php`, `alert_webhook.php`

## 13. Детальный анализ PHPStan ошибок (290 найдено)

### Критические проблемы (требуют создания классов)

1. **Отсутствующие классы исключений**:
   - ✅ `Proxbet\Core\Exceptions\SecurityException` - создан
   - ✅ `Proxbet\Core\Exceptions\NotFoundException` - создан
   - ✅ `Proxbet\Core\Exceptions\ValidationException` - приведён к рабочему контракту
   - ✅ `Proxbet\Core\Exceptions\ProxbetException` - дополнен методами `getDetails()`, `isUserFriendly()`, `getHttpStatusCode()`

2. **Несуществующие методы**:
   - ✅ `RateLimiter::checkLimit()` - добавлен
   - ✅ `RateLimiter::getRemainingRequests()` - добавлен
   - ✅ `TelegramRateLimiter::checkLimit()` - добавлен
   - ✅ `DatabaseQueryGuard::executeQuery()` - добавлен
   - ✅ `DDoSProtection::checkRequest()` - использование выровнено по актуальной сигнатуре
   - ✅ `Logger::debug()` - добавлен

### Средние проблемы (не блокируют работу)

3. **Unreachable code** (50+ случаев):
   - Ternary операторы с always true условиями
   - Недостижимые else ветки
   - Недостижимые match arms

4. **Type issues** (100+ случаев):
   - Missing parameter types (особенно в PSR-3 логгерах)
   - Missing return types
   - Array key type mismatches
   - Comparison operations с несовместимыми типами

5. **Unused code** (10+ случаев):
   - Неиспользуемые константы классов
   - Неиспользуемые методы
   - Write-only свойства

### Рекомендации по исправлению

**Приоритет 1** (блокирует качество кода):
- Создать недостающие классы исключений в `backend/core/Exceptions/`
- Исправить сигнатуры методов в security классах
- Убрать `|| true` из CI workflow

**Приоритет 2** (улучшает надёжность):
- Добавить типы параметров и возвращаемых значений
- Убрать unreachable code
- Исправить логику с always true/false условиями

**Приоритет 3** (code cleanup):
- Удалить unused код
- Исправить PHPDoc несоответствия

## 14. Анализ Docker Compose конфигурации

### Текущее состояние

Docker Compose включает **13 сервисов**:
- `app` - Apache + PHP backend
- `worker` - фоновые задачи (back_start.py)
- `db` - MySQL 8.4
- `phpmyadmin` - веб-интерфейс БД
- `redis` - кэширование
- `prometheus` - сбор метрик
- `grafana` - визуализация
- `loki` - агрегация логов
- `promtail` - отправка логов
- `alertmanager` - маршрутизация алертов
- `node-exporter` - системные метрики
- `mysql-exporter` - метрики БД
- `redis-exporter` - метрики кэша

### Проблемы

1. **Профили для окружений внедрены**:
   - Базовый compose поднимает только `app`, `worker`, `db`
   - `dev` добавляет `phpmyadmin`
   - `cache` включает optional Redis
   - `observability` включает Prometheus/Grafana/Loki/Alertmanager/exporters

2. **Рекомендации**:
   - Использовать базовый `docker compose up -d --build` как minimal production-friendly stack
   - При необходимости добавлять `docker compose --profile cache up -d --build`
   - Для разработки использовать `docker compose --profile dev up -d --build`
   - Для мониторинга использовать `docker compose --profile observability up -d --build`

## 15. Приоритет следующего практического шага

### ✅ P0 закрыт, этап 1 продолжается (2026-03-22)

Выполнено:
- ✅ Синхронизированы env переменные
- ✅ Защищены health/metrics/webhook endpoints
- ✅ Обновлены backup/restore скрипты

Осталось:
- ⚠️ Довести общий PHPStan quality gate до зелёного состояния

### Следующие шаги

**Немедленно** (можно сделать за 1-2 часа):
1. Добить остаточные PHPStan type warnings в shared/core слоях
2. Проверить compose-профили на целевой инфраструктуре
3. Прогнать `test:security` и `test:e2e` на поднятом HTTP runtime с реальными test secrets

**Краткосрочно** (1-2 дня):
4. Сократить `missingType.*` и `nullCoalesce.*` предупреждения PHPStan
5. Проверить security/e2e regression на CI или staging-окружении
6. Зафиксировать post-deploy checklist на целевой инфраструктуре

**Среднесрочно** (неделя):
7. Исправить критические PHPStan ошибки и оставшиеся проблемные контракты
8. Добавить типы параметров в критичных местах
9. Провести security audit с проверкой SQL injection
12. Оптимизировать тяжёлые запросы к БД
