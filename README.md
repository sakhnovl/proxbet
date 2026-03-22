# Proxbet

`Proxbet` это PHP backend для футбольного live-сканинга, Telegram-автоматизации и служебных HTTP/CLI entrypoint'ов. После P3-декомпозиции корневой `README` стал короткой точкой входа, а детальные материалы разнесены по тематическим документам.

## Что здесь есть

- ingestion прематч-линии и upsert матчей в MySQL;
- live-обновление матчей и live-статистики;
- историческая статистика по SGI и расчёт half-time метрик;
- scanner сигналов на гол в первом тайме;
- Telegram-бот, AI-анализ и служебные admin/public API;
- Docker runtime, deploy/runbook и security hardening docs.

## Карта документации

### Базовые документы

- [Обзор проекта](docs/project-overview.md)
- [Runtime и архитектура](docs/runtime-architecture.md)
- [HTTP API и operational endpoints](docs/http-api-reference.md)
- [Модель данных](docs/data-model.md)
- [Developer workflow и task runner](docs/developer-workflow.md)

### Эксплуатация и hardening

- [Deploy, recovery и rollback runbook](docs/deploy-recovery-runbook.md)
- [Security matrix для HTTP entrypoints](docs/http-security-matrix.md)
- [Logging redaction policy](docs/logging-redaction-policy.md)
- [DB performance review](docs/db-performance-review.md)

### Архитектурные решения

- [ADR index](docs/adr/README.md)

### Живые рабочие заметки

- [TODO и аудит проекта](docs/todo.md)
- [Update notes](docs/update.md)

## Быстрый старт

### Через Docker

1. Скопировать `.env.example` в `.env`.
2. Заполнить минимум:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `API_URL`, `API_URL_LIVE`
   - `ADMIN_API_TOKEN`
   - `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_IDS`, `TELEGRAM_CHANNEL_ID`
3. Поднять базовый стек:

```bash
docker compose up -d --build
```

4. Проверить liveness:

```text
http://localhost:8080/backend/healthz.php
```

5. Открыть developer workflow:

```bash
php scripts/task.php help
```

### Без Docker

Нужны `PHP 8.1+`, `pdo_mysql`, `curl`, `mbstring`, `intl`, `Composer`, `Python 3`, `MySQL/MariaDB`.

```bash
composer install
php backend/parser.php
php backend/live.php
php backend/stat.php
php backend/scanner/ScannerCli.php --verbose
php backend/bet_checker.php
python back_start.py --once
```

## Часто используемые команды

```bash
php scripts/task.php test
php scripts/task.php validate
php scripts/task.php test:security
php scripts/task.php test:e2e
```

Полный список команд и сценариев находится в [docs/developer-workflow.md](docs/developer-workflow.md).
