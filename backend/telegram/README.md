# Telegram AI Analysis Module

Модуль интеграции с Gemini AI для анализа футбольных матчей в Telegram-боте.

## Архитектура

### Основные компоненты

```
backend/telegram/
├── GeminiMatchAnalyzer.php    # Анализ одного матча через Gemini API
├── GeminiPoolAnalyzer.php     # Ротация ключей/моделей с failover
├── TelegramAiRepository.php   # Работа с БД (пользователи, запросы, ключи)
└── public_handlers.php         # Обработчики команд и callback'ов
```

### Поток работы

1. **Пользователь нажимает кнопку "AI-анализ"** в сообщении о матче
2. **Проверка баланса** - списание кредитов (по умолчанию 1)
3. **Сбор контекста** - данные матча + статистика сканера
4. **Ротация API** - перебор активных ключей и моделей
5. **Запрос к Gemini** - отправка промпта с контекстом
6. **Сохранение результата** - в БД для повторного использования
7. **Доставка в ЛС** - отправка анализа пользователю

## Алгоритмы анализа

### Алгоритм 1 (вероятностный)
- Основан на вероятности сканера (форма + H2H + live-метрики)
- Учитывает xG, удары в створ, опасные атаки
- Рекомендация: Подходит / Сомнительно / Не подходит

### Алгоритм 2 (фаворит забьет в 1T)
- Жесткие фильтры: коэф. П1 < 2.0, ТБ2.5 < 1.9
- Проверка истории голов хозяев в первом тайме
- Фокус на давлении фаворита-хозяев

### Алгоритм 3 (индивидуальный тотал)
- Табличная статистика (голы/игры, пропущенные/игры)
- Выбор команды с лучшим соотношением атака/оборона
- Ставка: ИТБ команды > 0.5

## Использование

### Добавление API-ключа Gemini

```bash
# В Telegram боте (только для админов)
/gemini_key_add YOUR_API_KEY_HERE
```

### Добавление модели

```bash
/gemini_model_add gemini-2.0-flash
/gemini_model_add gemma-3-27b-it
```

### Управление ключами/моделями

```bash
/gemini_key_list          # Список всех ключей
/gemini_key_on 1          # Включить ключ #1
/gemini_key_off 1         # Выключить ключ #1

/gemini_model_list        # Список всех моделей
/gemini_model_on 1        # Включить модель #1
/gemini_model_off 1       # Выключить модель #1
```

### Начисление кредитов пользователю

```bash
/grant_balance 123456789 10   # Дать 10 кредитов пользователю
```

## Конфигурация (.env)

```env
# Gemini API (устаревший способ, используйте БД)
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-2.0-flash

# Telegram AI настройки
TELEGRAM_AI_BUTTON_TEXT=🤖 Анализ Gemini
AI_ANALYSIS_COST=1
TELEGRAM_CREDITS_TOPUP_URL=https://t.me/your_payment_bot

# Новые пользователи получают пробный баланс
# (настраивается в TelegramAiRepository::NEW_USER_TRIAL_BALANCE)
```

## База данных

### Таблицы

**telegram_users** - пользователи бота
- `telegram_user_id` - ID в Telegram
- `ai_balance` - баланс кредитов
- `username`, `first_name`, `last_name`

**ai_analysis_requests** - история запросов
- `telegram_user_id`, `match_id`, `bet_message_id`
- `provider`, `model_name`, `status`
- `prompt_text`, `response_text`, `error_text`
- `cost_charged`

**gemini_api_keys** - пул API-ключей
- `api_key`, `is_active`
- `fail_count`, `last_error`, `last_used_at`

**gemini_models** - пул моделей
- `model_name`, `is_active`
- `fail_count`, `last_error`, `last_used_at`

## Failover и ротация

Система автоматически перебирает все комбинации ключ+модель:
1. Берет первый активный ключ
2. Перебирает все активные модели для этого ключа
3. При успехе - сбрасывает счетчики ошибок
4. При ошибке - инкрементирует fail_count, переходит к следующей модели
5. Если все модели для ключа упали - переходит к следующему ключу
6. Если все комбинации упали - возвращает ошибку и возврат средств

## Промпты

Промпты генерируются динамически в `GeminiMatchAnalyzer`:
- `buildAlgorithmOnePrompt()` - вероятностный анализ
- `buildAlgorithmTwoPrompt()` - фаворит в 1T
- `buildAlgorithmThreePrompt()` - индивидуальный тотал

Каждый промпт включает:
- Инструкции для AI
- Данные матча (команды, лига, счет, время)
- Live-метрики (xG, удары, атаки, угловые)
- Статистику сканера
- Формат ответа

## Безопасность

⚠️ **TODO:**
- [ ] Шифрование API-ключей в БД
- [ ] Rate limiting на пользователя
- [ ] Валидация ответов от Gemini
- [ ] Защита от SQL-инъекций (частично есть через prepared statements)

## Мониторинг

⚠️ **TODO:**
- [ ] Метрики использования API
- [ ] Статистика по алгоритмам
- [ ] Дашборд расхода кредитов
- [ ] Алерты при высоком fail_count

## Тестирование

⚠️ **TODO:**
- [ ] Unit-тесты для каждого класса
- [ ] Интеграционные тесты с моками Gemini API
- [ ] Тесты failover-логики

## Производительность

⚠️ **TODO:**
- [ ] Кэширование повторных анализов
- [ ] Webhook вместо long polling
- [ ] Очередь задач для асинхронной обработки
- [ ] Connection pooling для cURL

## Разработка

### Локальный запуск

```bash
# Запуск бота
php backend/telegram_bot.php

# Или через Docker
docker-compose up -d
```

### Добавление нового алгоритма

1. Добавить `buildAlgorithmFourPrompt()` в `GeminiMatchAnalyzer`
2. Обновить `buildPrompt()` для выбора промпта
3. Добавить логику в `enrichAnalysisContextWithScanner()`
4. Обновить `bet_messages.algorithm_id` при отправке

### Отладка

```php
// Включить детальное логирование
Logger::setLevel('debug');

// Проверить контекст анализа
$context = $repository->getAnalysisContext($matchId, $algorithmId);
var_dump($context);
```

## Лицензия

Proprietary - Proxbet Project
