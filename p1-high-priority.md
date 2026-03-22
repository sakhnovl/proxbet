# P1 High Priority Closure

## Goal
Закрыть все пункты `P1. Высокий приоритет` из `docs/todo.md` без регрессий в текущем runtime.

## Tasks
- [ ] Выравнять HTTP bootstrap и admin API вокруг общих middleware/helpers -> Verify: `backend/api.php`, `backend/admin/api.php`, health/ops endpoints используют общие функции
- [ ] Закрыть admin auth и архитектурный разрыв между `admin/api.php` и `admin/Handlers/*` -> Verify: роутер делегирует в handlers/services, auth работает через один контракт
- [ ] Исправить broken contracts в `core/security` и убрать ключевые PHPStan missing symbol/wrong call ошибки -> Verify: классы исключений и недостающие методы существуют, связанные ошибки уходят из `phpstan`
- [ ] Зафиксировать политику Redis и canonical runtime для background pipeline -> Verify: код и docs явно описывают optional/mandatory Redis и основной runtime
- [ ] Подготовить production-ready Docker profile -> Verify: `docker compose --profile minimal config` собирается без ошибок
- [ ] Обновить `docs/todo.md` с отметками выполнения -> Verify: все пункты P1 отмечены и снабжены краткими пояснениями

## Done When
- [ ] Все пункты `P1` в `docs/todo.md` отмечены выполненными на основании кода и документации
- [ ] Релевантные тесты/статический анализ прогнаны и остаточные риски зафиксированы
