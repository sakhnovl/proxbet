# P2 Medium Priority

## Goal
Закрыть все пункты `P2. Средний приоритет` из `docs/todo.md` без ломающих изменений в runtime.

## Tasks
- [ ] Вынести procedural CLI entrypoints в thin commands/services и оставить файлы `backend/*.php` тонкими оболочками.
- [ ] Унифицировать динамический query building и binding через общий PDO helper и применить его в hot-path репозиториях.
- [ ] Пересмотреть hot queries и индексы: обновить schema bootstrap, migration SQL и добавить короткий performance review doc.
- [ ] Снизить нагрузку scanner/runtime: ввести управляемые batch sizes и более гибкое scheduler/staggering поведение.
- [ ] Ужесточить redaction policy для runtime/audit/performance логов и описать правила в отдельном doc.
- [ ] Обновить эксплуатационную документацию по запуску, deploy/recovery и отметить выполненные P2-пункты в `docs/todo.md`.

## Done When
- [ ] Все пункты `P2. Средний приоритет` отмечены как выполненные и подтверждены кодом/документацией.
