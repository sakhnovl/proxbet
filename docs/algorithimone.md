# Алгоритм 1: Процесс поиска и анализа матчей

## Общий процесс работы сканера

### 1. Получение активных матчей из БД

**Файл**: `backend/scanner/DataExtractor.php` → `getActiveMatches()`

**SQL запрос**:
```sql
SELECT * FROM `matches` 
WHERE `time` IS NOT NULL 
  AND `time` != "" 
  AND `match_status` IS NOT NULL
ORDER BY `id` ASC
```

**Условия выборки**:
- Поле `time` должно быть заполнено (не NULL и не пустая строка)
- Поле `match_status` должно быть заполнено
- Сортировка по ID (по возрастанию)

**Результат**: Список всех live-матчей из таблицы `matches`

---

### 2. Фильтрация матчей на уровне сканера

**Файл**: `backend/scanner/Scanner.php` → `scanMatch()`

#### Фильтр 1: Проверка минуты матча

```php
if ($liveData['minute'] === 0) {
    return [];  // Пропустить матч
}
```

**Условие**: Если минута = 0, матч пропускается (матч еще не начался или данные некорректны)

#### Фильтр 2: Проверка окончания первого тайма

```php
if ($liveData['minute'] > 45 && trim($liveData['match_status']) !== 'Перерыв') {
    return [];  // Пропустить матч
}
```

**Условие**: Если минута > 45 И статус НЕ "Перерыв", матч пропускается (второй тайм уже идет)

**Итог**: После этих фильтров остаются только матчи в диапазоне **1-45 минут** первого тайма или на **перерыве**.

---

### 3. Извлечение данных для анализа

Для каждого прошедшего фильтры матча извлекаются:

#### 3.1. Live данные (текущая статистика матча)

**Источник**: Таблица `matches`, поля с префиксом `live_*`

**Извлекаемые данные**:
- `minute` - текущая минута матча (парсится из поля `time` формата "mm:ss")
- `shots_total` - общее количество ударов (в створ + мимо)
- `shots_on_target` - удары в створ
- `shots_on_target_home` / `shots_on_target_away` - удары в створ по командам
- `dangerous_attacks` - опасные атаки (сумма home + away)
- `dangerous_attacks_home` / `dangerous_attacks_away` - опасные атаки по командам
- `corners` - угловые (сумма home + away)
- `xg_home` / `xg_away` - Expected Goals (xG)
- `yellow_cards_home` / `yellow_cards_away` - желтые карточки
- `red_cards_home` / `red_cards_away` - красные карточки (если есть)
- `ht_hscore` / `ht_ascore` - счет первого тайма
- `live_hscore` / `live_ascore` - текущий счет
- `match_status` - статус матча ("1-й тайм", "Перерыв", и т.д.)

**Trend данные** (для v2):
- `trend_shots_total_delta` - изменение ударов за окно
- `trend_dangerous_attacks_delta` - изменение атак за окно
- `trend_xg_delta` - изменение xG за окно
- `trend_window_seconds` - размер окна в секундах
- `has_trend_data` - флаг наличия трендовых данных

#### 3.2. Form данные (форма команд)

**Источник**: Таблица `matches`, поля `ht_match_goals_1` и `ht_match_goals_2`

**Извлекаемые данные**:
- `home_goals` - голы хозяев в первом тайме за последние 5 матчей
- `away_goals` - голы гостей в первом тайме за последние 5 матчей
- `has_data` - флаг наличия данных

**Для v2 дополнительно**:
- `weighted` - взвешенные метрики (атака/защита) из `sgi_json`

#### 3.3. H2H данные (личные встречи)

**Источник**: Таблица `matches`, поля `h2h_ht_match_goals_1` и `h2h_ht_match_goals_2`

**Извлекаемые данные**:
- `home_goals` - голы хозяев в первом тайме в H2H матчах
- `away_goals` - голы гостей в первом тайме в H2H матчах
- `has_data` - флаг наличия данных

#### 3.4. League данные (для v2)

**Источник**: Таблица `matches`, поле `table_avg`

**Извлекаемые данные**:
- `league_avg_home_goals` - средние голы хозяев в лиге
- `league_avg_away_goals` - средние голы гостей в лиге

---

### 4. Анализ алгоритмом

**Файл**: `backend/scanner/Algorithms/AlgorithmOne.php` → `analyze()`

Алгоритм получает подготовленные данные:
```php
$algorithmOneResult = $this->algorithmOne->analyze([
    'form_data' => $formData,
    'h2h_data' => $h2hData,
    'live_data' => $liveData,
]);
```

#### Внутренние фильтры алгоритма (Gating Conditions)

**Legacy (v1)** - проверки в `LegacyFilter`:
1. ✅ Есть данные формы (`has_data === true`)
2. ✅ Есть H2H данные (`has_data === true`)
3. ✅ Счет 0:0 в первом тайме (`ht_hscore === 0 && ht_ascore === 0`)
4. ✅ Минута >= 15
5. ✅ Минута <= 30
6. ✅ Удары в створ >= 1
7. ✅ Опасные атаки >= 20
8. ✅ Вероятность >= 0.55 (55%)

**V2** - проверки в `ProbabilityCalculatorV2`:
1. ✅ Есть данные формы
2. ✅ Есть H2H данные
3. ✅ Счет 0:0 в первом тайме
4. ✅ Минута в диапазоне 15-30
5. ✅ Удары в створ >= 1
6. ✅ Темп атак >= 1.5 атак/минуту (`dangerous_attacks / minute >= 1.5`)
7. ✅ Нет красных флагов (low_accuracy, ineffective_pressure)
8. ✅ Вероятность >= 0.55 (55%)

**Если все условия выполнены** → `bet: true` (сигнал на ставку)  
**Если хотя бы одно не выполнено** → `bet: false` (отклонить)

---

### 5. Результат анализа

**Возвращаемая структура**:
```php
[
    'bet' => bool,              // true = делать ставку, false = пропустить
    'reason' => string,         // Причина решения
    'confidence' => float,      // Вероятность (0.0-1.0)
    'dual_run' => [...]        // Опционально: сравнение версий
]
```

---

### 6. Отправка уведомления в Telegram

**Файл**: `backend/scanner/TelegramNotifier.php` → `notifySignal()`

#### Условия отправки

Сообщение отправляется в Telegram канал **ТОЛЬКО если**:

1. ✅ `bet === true` (алгоритм дал сигнал на ставку)
2. ✅ Настроены переменные окружения:
   - `TELEGRAM_BOT_TOKEN` - токен бота
   - `TELEGRAM_CHANNEL_ID` - ID канала
3. ✅ Флаг `--no-telegram` НЕ установлен при запуске
4. ✅ Матч еще не был отправлен (защита от дубликатов)

#### Защита от дубликатов

**Механизм**:
- Каждое отправленное сообщение сохраняется в файл состояния (`data/scanner_state.json`)
- Ключ: `match_{match_id}_algorithm_{algorithm_id}`
- Перед отправкой проверяется наличие ключа в файле
- Если ключ существует → сообщение пропускается

**Пример ключа**: `match_12345_algorithm_1`

#### Формат сообщения для Алгоритма 1

**Legacy (v1)**:
```
🔥 СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ
🧠 Алгоритм 1

⚽ Манчестер Сити - Ливерпуль
🏆 Premier League
⏱ Время: 22:15
⚽ Счет: 0:0

📊 Вероятность: 62%
├ Форма: 0.68 (35%)
├ H2H: 0.45 (15%)
└ Live: 0.72 (50%)

📈 Статистика матча
├ Удары: 12 (в створ: 5)
├ Опасные атаки: 28
└ Угловые: 4

📋 Форма 1T: дома 8/5, гости 6/5
🤝 H2H 1T: дома 5/5, гости 4/5
```

**V2**:
```
🔥 СИГНАЛ: ГОЛ В ПЕРВОМ ТАЙМЕ
🧠 Алгоритм 1
🧠 Алгоритм 1 v2 (улучшенная версия)

⚽ Манчестер Сити - Ливерпуль
🏆 Premier League
⏱ Время: 25:30
⚽ Счет: 0:0

📊 Вероятность: 67%
├ Форма: 0.68 (25%)
├ H2H: 0.45 (10%)
└ Live: 0.75 (65%)

🔬 Компоненты Live Score v2:
├ PDI (баланс давления): 0.75
├ Качество ударов: 0.68
├ Ускорение трендов: 0.72
├ xG давление: 0.80
├ Временное давление: 0.85
├ Фактор лиги: 1.10
└ Фактор карточек: 1.00

✅ xG несоответствие (усилитель)

📈 Статистика матча
├ Удары: 15 (в створ: 6)
├ Опасные атаки: 35
└ Угловые: 5

📋 Форма 1T: дома 8/5, гости 6/5
🤝 H2H 1T: дома 5/5, гости 4/5
```

#### Кнопка "Анализ"

К каждому сообщению добавляется inline-кнопка:
- Текст: "📊 Анализ"
- Callback data: `analysis_{match_id}_{algorithm_id}`
- Позволяет получить детальный анализ матча

#### Сохранение в БД

После успешной отправки сообщение сохраняется в таблицу `bet_messages`:
- `match_id` - ID матча
- `message_id` - ID сообщения в Telegram
- `chat_id` - ID канала
- `message_text` - текст сообщения
- `algorithm_id` - ID алгоритма (1, 2, 3)
- `algorithm_name` - название алгоритма
- `algorithm_payload_json` - JSON с данными алгоритма
- `status` - статус ставки ('pending', 'won', 'lost')

**Назначение**: Для последующей проверки результатов ставок через `BetChecker`

#### Логирование

**При успешной отправке**:
```php
Logger::info('Scanner notification sent to channel', [
    'match_id' => 12345,
    'algorithm_id' => 1,
    'channel_id' => '@my_channel',
    'message_id' => 67890,
]);
```

**При пропуске дубликата**:
```php
Logger::info('Scanner notification skipped (already sent)', [
    'match_id' => 12345,
    'algorithm_id' => 1,
    'key' => 'match_12345_algorithm_1',
]);
```

**При ошибке**:
```php
Logger::error('Scanner notification failed', [
    'match_id' => 12345,
    'algorithm_id' => 1,
    'response' => [...],
]);
```

---

## Блок-схема процесса поиска матчей

```
┌─────────────────────────────────────┐
│  1. SQL запрос к таблице matches    │
│     WHERE time IS NOT NULL          │
│     AND match_status IS NOT NULL    │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  2. Для каждого матча:              │
│     Проверка minute                 │
└──────────────┬──────────────────────┘
               │
               ▼
       ┌───────────────┐
       │ minute == 0?  │──── ДА ──→ ПРОПУСТИТЬ
       └───────┬───────┘
               │ НЕТ
               ▼
       ┌───────────────────────────────┐
       │ minute > 45 AND               │
       │ status != "Перерыв"?          │──── ДА ──→ ПРОПУСТИТЬ
       └───────┬───────────────────────┘
               │ НЕТ
               ▼
┌─────────────────────────────────────┐
│  3. Извлечение данных:              │
│     - Live статистика               │
│     - Форма команд (5 матчей)       │
│     - H2H (личные встречи)          │
│     - League данные (для v2)        │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  4. Анализ алгоритмом:              │
│     AlgorithmOne.analyze()          │
└──────────────┬──────────────────────┘
               │
               ▼
       ┌───────────────┐
       │ Gating        │
       │ Conditions    │
       └───────┬───────┘
               │
       ┌───────▼────────┐
       │ Все условия    │
       │ выполнены?     │
       └───────┬────────┘
               │
       ┌───────┴────────┐
       │                │
      ДА               НЕТ
       │                │
       ▼                ▼
┌─────────────┐  ┌─────────────┐
│ Расчет      │  │ bet: false  │
│ вероятности │  │ + причина   │
└──────┬──────┘  └─────────────┘
       │
       ▼
┌──────────────────┐
│ probability      │
│ >= 0.55?         │
└──────┬───────────┘
       │
   ┌───┴────┐
   │        │
  ДА       НЕТ
   │        │
   ▼        ▼
┌────────┐ ┌────────┐
│bet:true│ │bet:    │
│        │ │false   │
└────────┘ └────────┘
```

---

## Ключевые моменты для модификации

### Где изменить выборку матчей из БД

**Файл**: `backend/scanner/DataExtractor.php`  
**Метод**: `getActiveMatches()`

Текущий SQL:
```sql
SELECT * FROM `matches` 
WHERE `time` IS NOT NULL 
  AND `time` != "" 
  AND `match_status` IS NOT NULL
ORDER BY `id` ASC
```

**Примеры модификаций**:

1. **Добавить фильтр по лиге**:
```sql
WHERE `time` IS NOT NULL 
  AND `match_status` IS NOT NULL
  AND `liga` IN ('Premier League', 'La Liga', 'Bundesliga')
```

2. **Добавить фильтр по стране**:
```sql
WHERE `time` IS NOT NULL 
  AND `match_status` IS NOT NULL
  AND `country` = 'Англия'
```

3. **Добавить фильтр по минуте** (на уровне SQL):
```sql
WHERE `time` IS NOT NULL 
  AND `match_status` IS NOT NULL
  AND CAST(SUBSTRING_INDEX(`time`, ':', 1) AS UNSIGNED) BETWEEN 15 AND 30
```

### Где изменить фильтры минут

**Файл**: `backend/scanner/Scanner.php`  
**Метод**: `scanMatch()`

Текущие фильтры:
```php
if ($liveData['minute'] === 0) {
    return [];
}

if ($liveData['minute'] > 45 && trim($liveData['match_status']) !== 'Перерыв') {
    return [];
}
```

**Примеры модификаций**:

1. **Изменить диапазон минут**:
```php
// Анализировать только 10-35 минуты
if ($liveData['minute'] < 10 || $liveData['minute'] > 35) {
    return [];
}
```

2. **Добавить фильтр по статусу**:
```php
// Только первый тайм, без перерыва
if ($liveData['match_status'] !== '1-й тайм') {
    return [];
}
```

### Где изменить условия алгоритма

**Legacy (v1)**:  
**Файл**: `backend/scanner/Algorithms/AlgorithmOne/Filters/LegacyFilter.php`  
**Метод**: `shouldBet()`

**V2**:  
**Файл**: `backend/scanner/Algorithms/AlgorithmOne/Calculators/V2/ProbabilityCalculatorV2.php`  
**Метод**: `checkGatingConditions()`

**Примеры модификаций**:

1. **Изменить минимальную минуту** (в Config.php):
```php
public const MIN_MINUTE = 20;  // Было 15
```

2. **Изменить минимальные опасные атаки**:
```php
public const MIN_DANGEROUS_ATTACKS = 25;  // Было 20
```

3. **Изменить минимальную вероятность**:
```php
public const MIN_PROBABILITY = 0.60;  // Было 0.55 (55% → 60%)
```

### Где изменить извлекаемые данные

**Файл**: `backend/scanner/DataExtractor.php`  
**Методы**: 
- `extractLiveData()` - для legacy
- `extractLiveDataV2()` - для v2
- `extractFormData()` / `extractFormDataV2()` - для формы
- `extractH2hData()` - для H2H

**Пример добавления нового поля**:

```php
// В extractLiveDataV2() добавить:
'possession_home' => $this->getIntOrNull($match, 'live_possession_home'),
'possession_away' => $this->getIntOrNull($match, 'live_possession_away'),
```

---

## Резюме: Как работает поиск матчей

1. **SQL запрос** → Выбирает все матчи с заполненными `time` и `match_status`
2. **Фильтр минут** → Оставляет только 1-45 минуты или перерыв
3. **Извлечение данных** → Собирает live, form, H2H, league данные
4. **Gating Conditions** → Проверяет базовые условия (минута 15-30, счет 0:0, и т.д.)
5. **Расчет вероятности** → Применяет формулы legacy или v2
6. **Проверка порога** → Сравнивает с минимальной вероятностью (55%)
7. **Результат** → `bet: true/false` + причина + вероятность

**Основные точки модификации**:
- SQL запрос в `DataExtractor.getActiveMatches()`
- Фильтры минут в `Scanner.scanMatch()`
- Gating conditions в `LegacyFilter` или `ProbabilityCalculatorV2`
- Константы в `Config.php`
