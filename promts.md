## PROMPT (Шаг 1) — Каркас Laravel Package + Filament Plugin “Restic Backups”

Ты — senior Laravel 12 + Filament 4 разработчик. Нужно **создать каркас переиспользуемого мини-модуля** “Restic Backups”, который можно подключать в любой Laravel-проект, и который добавляет раздел в Filament админке. На этом шаге **никакой бизнес-логики backup/restore ещё не реализуем**, только архитектурный скелет, конфиги, регистрации, место для будущих классов.

### 0) Контекст и ограничения

- Laravel: **12.x**
- Filament: **4.x**
- Livewire: **3.x**
- PHP: **8.3+** (допускай 8.2+, но ориентируйся на 8.3/8.4)
- Модуль должен быть **переносимым** между проектами: минимум “завязок” на конкретное приложение.
- Реализация должна быть “production-ready” по структуре: конфиг, миграции, publish, разделение ответственности.
- На этом шаге:
  - ✅ создаём package skeleton + plugin skeleton
  - ✅ создаём config файл
  - ✅ создаём пустые миграции-заглушки (или минимальную миграцию под settings/runs — но без логики)
  - ✅ создаём пустые Filament Pages/Resources (заглушки) и Navigation Group
  - ✅ создаём сервис-слой заглушки (ResticRunner placeholder) без реального выполнения команд
  - ❌ не делаем интеграцию с restic
  - ❌ не делаем расписания, очереди, UI-формы

### 1) Формат результата

Выдай **коммит-ориентированный результат**:

1. Полная структура файлов/папок (дерево)
2. Содержимое ключевых файлов (код)
3. Команды установки и подключения в проект (инструкция)
4. Пояснение, как добавить в Filament панель
5. Мини-checklist приёмки (как проверить, что шаг 1 готов)

### 2) Выбор способа упаковки

Сделай это как **отдельный пакет внутри monorepo** (для удобства локальной разработки), который при желании можно вынести в отдельный репозиторий без изменений.

- Путь пакета: `packages/siteko/restic-backups/`
- Composer package name: `siteko/filament-restic-backups`
- Namespace: `Siteko\FilamentResticBackups`

Пакет должен быть автозагружаем через composer path repository в корневом проекте.

### 3) Composer и автозагрузка

Внутри пакета сделай `composer.json` с:

- `"type": "library"`
- PSR-4: `"Siteko\\FilamentResticBackups\\": "src/"`
- `"extra": { "laravel": { "providers": [...], "aliases": {... (не обязательно)} } }`
- Поддержка авто-дискавери Laravel (providers)

В корневом проекте предполагается, что будет добавлен path-репозиторий.
Сгенерируй пример блока для корневого `composer.json` (инструкцией), но сам корневой файл не меняй, если это не требуется в задаче.

### 4) Service Provider

Создай сервис-провайдер пакета, например:

- `src/ResticBackupsServiceProvider.php`

В нём:

- register config merge
- publish config: `config/restic-backups.php`
- publish migrations: `database/migrations/*.php`
- register commands (пока пусто/заглушка, но подготовь место)
- register translations/views (опционально; если не используешь — оставь готовые методы и папки)

Важно: провайдер должен быть корректно подключаем через авто-discovery.

### 5) Конфиг пакета

Создай `config/restic-backups.php` с **разумными дефолтами** и комментариями.

На этом шаге в конфиге должны быть секции (пока без реализации):

- `enabled` (bool)
- `panel` (какую Filament panel подключать; дефолт: `admin`)
- `navigation`:
  - group label: `Backups`
  - icon: например `heroicon-o-archive-box` (или аналог Filament v4)
  - sort
- `paths`:
  - `project_root` (default: `base_path()`)
  - `work_dir` (default: `storage_path('app/_backup')`)
- `restic`:
  - `binary` (default: `restic`)
  - `cache_dir` (default: `storage_path('app/_restic_cache')`)
- `security`:
  - `require_confirmation_phrase` (bool)
  - `permissions` (список permission strings, пока заглушка)

Главное: конфиг должен быть понятным и пригодным для разных проектов.

### 6) Миграции (заглушки)

Сделай папку `database/migrations/`.

Создай **минимум одну миграцию** (лучше две) как основу для следующих шагов:

1. `backup_settings` — таблица для хранения настроек

- поля: `id`, `data` json, timestamps
- можно сразу предусмотреть `encrypted` касты позже, но сейчас просто json
- допустимо сделать `singleton` таблицу (одна запись)

1. `backup_runs` — таблица истории запусков

- `id`, `type` (string), `status` (string), `started_at`, `finished_at` (nullable), `meta` json, timestamps

Важно: миграции должны публиковаться командой vendor:publish.

### 7) Модели (минимальные)

Создай в `src/Models`:

- `BackupSetting` (Eloquent model)
- `BackupRun` (Eloquent model)

Пока без сложных кастов, но:

- `$casts = ['data' => 'array', 'meta' => 'array', ...]`
- Таблицы должны совпадать с миграциями.

### 8) Сервис-слой (заглушка)

Создай `src/Services/ResticRunner.php` как placeholder.

- Методы: `snapshots()`, `backup()`, `forget()`, `check()`, `restore()`
- Сейчас методы могут бросать исключение `LogicException("Not implemented")` или возвращать заглушку — но структура должна быть готова.
- Создай DTO `src/DTO/ProcessResult.php` (exitCode, duration, stdout, stderr, json)

### 9) Filament Plugin (скелет)

Сделай Filament plugin класс:

- `src/Filament/ResticBackupsPlugin.php`

Требования:

- Плагин должен:
  - добавлять Navigation Group “Backups”
  - регистрировать страницы пакета
  - уметь включаться в конкретную panel (через конфиг `panel`)
- Плагин должен быть “thin”: без логики, только регистрация.

### 10) Filament Pages (заглушки)

Создай минимум 2 страницы в `src/Filament/Pages`:

1. `BackupsDashboard` (или `BackupsOverview`)
2. `BackupsSettings`

Каждая страница:

- Должна отображаться и открываться (пусть с простым текстом “TODO”)
- Должна иметь slug, navigation label, title
- Должна быть доступна через Filament navigation в группе Backups

Если в Filament 4 лучше использовать “Page” и “Cluster” — выбери корректный подход для Filament 4, но без усложнений: важна работоспособность и базовая структура.

### 11) Политика доступа (подготовка)

На этом шаге достаточно:

- Добавить в страницы `canAccess()` или `authorize()` (как принято в Filament v4) с проверкой:
  - если в конфиге `security.permissions` пусто — доступ разрешён только супер-админу?
  - либо доступ всем authenticated admin users (простой вариант)
    Выбери 1 вариант и объясни его в инструкции (почему так).

### 12) Публикация ассетов (опционально)

Если не нужно — не делай.
Но подготовь папки:

- `resources/views` (если понадобится)
- `resources/lang`

### 13) Инструкция по подключению

В конце выдай чёткую инструкцию:

1. Как подключить пакет через path repository в корневом composer.json
2. `composer require siteko/filament-restic-backups:*` (или `composer update`)
3. `php artisan vendor:publish --tag=restic-backups-config`
4. `php artisan vendor:publish --tag=restic-backups-migrations`
5. `php artisan migrate`
6. Как подключить plugin в Filament panel provider:
   - `->plugins([ResticBackupsPlugin::make()])`
7. Как проверить: зайти в админку и увидеть группу “Backups” и две страницы.

### 14) Acceptance Criteria (критерии готовности)

Шаг 1 считается завершённым, если:

- Пакет устанавливается в чистый Laravel 12 проект
- Конфиг публикуется
- Миграции публикуются и выполняются
- В Filament появляется меню “Backups” с двумя страницами
- При открытии страниц нет ошибок
- Структура готова для шагов 2+ (settings, runner, jobs)

### 15) Важные требования к качеству

- Код должен соответствовать PSR-12
- Все классы с корректными namespace
- Никаких хардкодов путей проекта — только через config/helpers
- Никаких “молча проглатываемых” исключений
- Понятные комментарии только там, где это реально полезно

------

## Что ты должен выдать (в ответ на этот промт)

- Файловое дерево пакета
- Полный код ключевых файлов: composer.json, service provider, config, модели, plugin, pages
- Список команд установки и проверки

## PROMPT (Шаг 2) — Backup Settings Storage + Encrypted Secrets (Laravel 12, package)

Ты — senior Laravel 12 разработчик. У нас уже есть локальный Composer package `siteko/filament-restic-backups` (путь: `packages/siteko/restic-backups`). В пакете уже есть миграции-заглушки и модели:

- `database/migrations/2024_01_01_000000_create_backup_settings_table.php`
- `src/Models/BackupSetting.php`

Сейчас нужно реализовать **настоящую схему хранения настроек** для restic + S3, включая **зашифрованное хранение секретов**, и сидер с дефолтной (пустой) записью.

### 0) Важные требования и контекст

- Laravel: 12.x
- PHP: 8.2+ (но ориентируйся на 8.3/8.4)
- Пакет подключён в проект через path repository, dev-режим.
- Система должна поддерживать **singleton settings** (одна строка настроек), но без глобальных статиков, чтобы было тестируемо.
- Мы НЕ делаем Filament UI на этом шаге (только миграции/модель/сидер/сервис-методы).
- Секреты должны храниться зашифрованно с помощью **Laravel encrypted cast**.

### 1) Что именно нужно сделать

#### 1.1. Миграция `backup_settings`

Заменить текущую миграцию-заглушку на полноценную. Таблица `backup_settings` должна содержать:

- `id` (PK)

- `endpoint` (string, nullable) — S3 endpoint (например, `https://s3.amazonaws.com` или Selectel endpoint)

- `bucket` (string, nullable)

- `prefix` (string, nullable) — префикс внутри bucket

- `access_key` (text, nullable) — **зашифрованное** значение (хранится как строка, но будет ciphertext)

- `secret_key` (text, nullable) — **зашифрованное**

- `restic_repository` (string/text, nullable) — строка репозитория restic (например `s3:https://.../bucket/prefix`)

- `restic_password` (text, nullable) — **зашифрованное**

- `retention` (json, nullable) — политика хранения (`keep_daily`, `keep_weekly`, etc.)

- `schedule` (json, nullable) — расписание (пока просто хранение)

- `paths` (json, nullable) — include/exclude + project_root/доп. пути

  > Важно: include/exclude — это массивы строк.

- `project_root` (string, nullable) — базовый путь проекта (по умолчанию `base_path()` в приложении, но в БД храним строку)

- `created_at`, `updated_at`

**Примечание:** encrypted cast не требует специальных колонок, но поля с ciphertext лучше хранить в `text`.

#### 1.2. Singleton поведение

Реализовать удобный способ получить “единственную запись настроек”:

- В модели `BackupSetting` добавить статический метод, например:
  - `public static function singleton(): self`
    - возвращает существующую запись, либо создаёт новую “пустую”
- Альтернатива — сервис `BackupSettingsRepository`, но если выберешь сервис — всё равно оставь удобный метод в модели или facade позже. На этом шаге можно сделать только модельный метод.

Важно:

- Никаких жёстких `id=1` без защиты. Допускается предполагать, что это единственная запись, но корректно обрабатывать ситуацию, если записей несколько (например, брать самую свежую и/или первую по id).

#### 1.3. Encrypted casts

В `src/Models/BackupSetting.php`:

- Добавить `$casts`:
  - `access_key => 'encrypted'`
  - `secret_key => 'encrypted'`
  - `restic_password => 'encrypted'`
  - `retention => 'array'` или `json`
  - `schedule => 'array'`
  - `paths => 'array'`
- Убедиться, что на чтение/запись всё работает прозрачно.

Важно:

- Это должно реально использоваться Laravel-ом (то есть это **Eloquent Model**, не DTO).
- Не допускать утечки секретов в `toArray()`/`toJson()`:
  - Либо скрыть секретные поля через `$hidden = ['access_key', 'secret_key', 'restic_password']`
  - Либо реализовать безопасные accessor-методы для отображения “masked value” отдельно (опционально).

#### 1.4. Нормальные дефолты

Нужно обеспечить разумные значения по умолчанию при создании пустой записи:

- `retention`: например `['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 12]` (если считаешь нужным)
- `schedule`: например `['enabled' => false, 'daily_time' => '02:00']` (не обязательно, но желательно)
- `paths`: например:
  - `include` => []
  - `exclude` => []
  - либо `include` => ['storage/app', 'public'] и `exclude` => ['vendor', ...] — но если не уверен, оставь пустыми.
- `project_root`: по умолчанию `base_path()` — но это в момент создания в приложении. В сидере можно оставить null или заполнить.

Важно: не усложняй, но сделай так, чтобы запись была валидной.

#### 1.5. Сидер

Добавить сидер (в пакете) который создаёт “пустую” запись настроек.

Где хранить:

- В пакете: `database/seeders/BackupSettingsSeeder.php` (или `ResticBackupsSeeder.php`)
- Важно: сидер должен быть доступен приложению.
  Рекомендуемый подход: **опубликовать сидеры как часть пакета** (или указать пользователю команду копирования). Если не хочешь publish сидера — тогда сделай Artisan command `restic-backups:install` в пакете, который создаст запись. Но на шаге 2 лучше именно сидер.

Требования к сидеру:

- при запуске не должен создавать дубликаты:
  - если запись уже есть — ничего не делать
  - если записей несколько — ничего не ломать, можно оставить как есть

#### 1.6. Обновить config (опционально)

Если в `config/restic-backups.php` есть дефолты, и они пересекаются с `project_root/work_dir/restic.binary`, то:

- на этом шаге можно не трогать config
- но если добавляешь `project_root` в БД, **не выкидывай** config — он будет fallback.

### 2) Backward compatibility (важно)

В шаге 1 таблица могла быть `data json`. Сейчас мы переходим к нормальным колонкам. Поэтому:

- миграция должна быть корректной для свежей установки (самое важное)
- если хочешь — добавь **безопасный upgrade path**:
  - если колонка `data` существует, можно перенести значения в новые колонки (не обязательно, но хорошо)
  - но не усложняй: допустимо “fresh install only”, если в проекте ещё нет прод-данных.

### 3) Что должно быть в результате (обязательный output)

Ты должен выдать:

1. Изменения файлов (diff или полный код файлов):
   - миграция `create_backup_settings_table`
   - модель `BackupSetting`
   - новый сидер
   - (опционально) регистрация publish сидера или команда install
2. Команды для проверки в приложении:
   - `php artisan migrate:fresh`
   - `php artisan db:seed --class=...` (или общий DatabaseSeeder)
   - `php artisan tinker` пример: получить settings и убедиться что encryption работает
3. Мини-проверки:
   - что в БД секреты выглядят как ciphertext
   - что при `$settings->access_key` возвращается исходный текст

### 4) Тест-кейсы (хотя бы manual)

Опиши (или реализуй) простые проверки:

- Создать settings через сидер.
- Записать `access_key="abc"`, сохранить, посмотреть в БД — должно быть не `abc`.
- Прочитать обратно — должно быть `abc`.
- `toArray()` не должен содержать секреты (если скрываем).

### 5) Критерии приёмки

Шаг 2 готов, если:

- Таблица `backup_settings` имеет все требуемые колонки
- Модель корректно шифрует `access_key/secret_key/restic_password`
- Есть сидер, который создаёт одну запись без дублей
- Есть метод получения singleton settings
- Никаких ошибок при migrate/seed

------

## Дополнение: предпочтения по реализации (важно выполнить)

- Используй **строгие типы** где уместно (phpdoc/return types).
- Не логируй и не выводи секреты.
- Держи реализацию “пакетной”: все классы в namespace `Siteko\FilamentResticBackups\...

## PROMPT (Шаг 3) — `ResticRunner` (Symfony Process) + безопасный запуск + Result DTO

Ты — senior Laravel 12 / PHP 8.4 инженер. В проекте уже есть локальный пакет:

```
packages/siteko/restic-backups
```

В пакете уже существуют (как минимум) файлы:

- `src/Services/ResticRunner.php` (плейсхолдер)
- `src/DTO/ProcessResult.php` (плейсхолдер)
- `src/Models/BackupSetting.php` (singleton, encrypted casts готовы)

Нужно реализовать **единый сервис**, который запускает `restic` через **Symfony Process**, передаёт секреты только через **env**, возвращает **структурированный результат**, и нигде не “светит” секреты.

------

# 0) Цель шага

Сделать “движок выполнения restic-команд” для дальнейших шагов (jobs, Filament UI).
На этом шаге **не делай очереди, расписания, UI** — только сервис и DTO, плюс минимальные исключения/утилиты.

------

# 1) Требуемые методы `ResticRunner`

Сервис должен уметь:

1. `snapshots(array $filters = []): ProcessResult`

- Должен вызывать `restic snapshots --json`
- Возвращать `parsedJson` как массив снапшотов (или `null` при ошибке парсинга)

1. `backup(array|string $paths, array $tags = [], array $options = []): ProcessResult`

- Запускает `restic backup ...`
- Пути должны передаваться как аргументы process (без shell)
- Теги — через `--tag` (для каждого тега)
- На этом шаге допускается не поддерживать exclude/include, но предусмотрите место в `$options`.

1. `forget(array $retention, array $options = []): ProcessResult`

- Запускает `restic forget ...`
- `retention` — ассоц массив типа:
  - `keep_last`, `keep_daily`, `keep_weekly`, `keep_monthly`, `keep_yearly`
- Опционально: `--prune` (по умолчанию true, но можно отключить через `$options['prune']=false`)
- Возврат как `ProcessResult`

1. `check(array $options = []): ProcessResult`

- Запускает `restic check`
- Минимально: без опций, но оставь расширяемость (например `read_data_subset`)

1. `restore(string $snapshotId, string $targetDir, array $options = []): ProcessResult`

- Запускает `restic restore <snapshotId> --target <targetDir>`
- `targetDir` создавай заранее (если нет), проверь права/доступность.
- Предусмотри опции `include`, `exclude`, `path` (не обязательно реализовывать полностью, но интерфейс заложить)

Дополнительно (желательно, для диагностики):

- `version(): ProcessResult` (restic version) — полезно для healthcheck и логов

------

# 2) Как формировать команду и окружение (SECURITY MUST)

## 2.1. Никакого shell

Команды запускай **только** через `new Process([$binary, 'snapshots', '--json', ...])`.
**Запрещено**:

- `Process::fromShellCommandline()`
- `exec()`, backticks
- конкатенация строки команды в shell виде

Это нужно для безопасности и корректного escaping.

## 2.2. Секреты только через env

Секреты (access_key, secret_key, restic_password) должны передаваться в Process только через environment variables:

- `RESTIC_REPOSITORY` (или как минимум `-r ...`, но предпочтительно env)
- `RESTIC_PASSWORD` (обязательно env) ([Restic Documentation](https://restic.readthedocs.io/en/v0.12.1/030_preparing_a_new_repo.html?utm_source=chatgpt.com))
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (env)
- опционально `AWS_SESSION_TOKEN` (если когда-то будет) ([Restic Documentation](https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html?utm_source=chatgpt.com))
- region можно позже поддержать через env `AWS_DEFAULT_REGION` или restic option `-o s3.region=...` (не обязательно в этом шаге) ([Restic Documentation](https://restic.readthedocs.io/en/v0.12.0/030_preparing_a_new_repo.html?utm_source=chatgpt.com))

### Endpoint / bucket / prefix

В настройках у нас есть `endpoint/bucket/prefix` и `restic_repository`.
На этом шаге:

- Если `restic_repository` заполнен — используем его как первичный источник.
- Иначе можно собрать repository строку из `endpoint/bucket/prefix` в формате restic S3 backend (для S3-совместимых серверов restic принимает URL в repository строке). ([Restic Documentation](https://restic.readthedocs.io/en/v0.12.0/030_preparing_a_new_repo.html?utm_source=chatgpt.com))

## 2.3. Не светить секреты в логах

В `ProcessResult` и любых исключениях **нельзя** хранить/выводить:

- значения секретных env
- команду со вставленными секретами
- repository строку, если она теоретически может содержать креды (например rest server `https://user:pass@host/...`)

Требования:

- В `ProcessResult` можно хранить “safeCommand” — **редактированный/редактируемый** вид команды (без env и без секретов).
- Если где-то логируешь repository, то через redaction:
  - маскируй `://user:pass@` → `://***:***@`
  - маскируй любые токены длиннее N
- Лучший вариант: по умолчанию вообще не логировать repository, только “backend = s3” и “bucket/prefix” (без ключей).

------

# 3) Источник настроек

`ResticRunner` должен получать настройки из:

- `Siteko\FilamentResticBackups\Models\BackupSetting::singleton()`
  и/или
- `config('restic-backups...')` как fallback (binary, cache_dir, etc.)

Требования:

- Валидация: если нет repo/password/ключей — сервис должен:
  - либо возвращать `ProcessResult` с exitCode != 0 и понятным stderr (если ты реализуешь “soft fail”)
  - либо бросать `ResticConfigurationException` (предпочтительно), но без секретов в сообщении.

------

# 4) `ProcessResult` DTO — что вернуть

`src/DTO/ProcessResult.php` должен содержать (минимум):

- `exitCode: int`
- `durationMs: int` (или float seconds)
- `stdout: string`
- `stderr: string`
- `parsedJson: array|null` (результат JSON-парсинга, если применимо)
- `command: array` или `safeCommand: string` (без секретов)
- `startedAt/finishedAt` (опционально)

## JSON parsing

- Для `snapshots()` обязательно парсить JSON (restic поддерживает `--json` для snapshots). ([Restic Documentation](https://restic.readthedocs.io/en/stable/075_scripting.html?utm_source=chatgpt.com))
- Для остальных команд:
  - либо не парсить (parsedJson=null)
  - либо пытаться парсить, если `$options['json']=true`
- JSON может быть:
  - массивом (`[...]`)
  - или “JSON lines” (строка из нескольких JSON-объектов построчно) — если выберешь поддержку, делай аккуратно: пробуй `json_decode` целиком, если не вышло — разбей по строкам и декодируй построчно.

Если парсинг не удался:

- `parsedJson = null`
- `stderr`/`meta` могут содержать “json_parse_error”, но без секретов

------

# 5) Timeout / performance / output size

- В `ResticRunner` должны быть настройки timeout:
  - по умолчанию таймаут **высокий** (например 3600 или отключён) — restic может работать долго
  - возможность переопределить через `$options['timeout']`
- Важно: stdout/stderr могут быть большими.
  Реализуй защиту:
  - либо лимитирование длины сохраняемого вывода (например 1–5 МБ)
  - либо опцию `$options['capture_output']=true/false`

------

# 6) Рабочая директория и cache

- Process должен запускаться с `cwd`:
  - предпочтительно `project_root` из settings
  - fallback: `base_path()`
- Если в config есть `restic.cache_dir`, передай restic global option `--cache-dir` (это не секрет).
- Убедись, что директории для cache/work существуют (создать при необходимости).

------

# 7) Ошибки и исключения

Добавь минимальный набор исключений в пакете, например:

- `src/Exceptions/ResticException.php` (базовое)
- `src/Exceptions/ResticConfigurationException.php` (нет настроек)
- `src/Exceptions/ResticProcessException.php` (если хочешь бросать при exitCode != 0)

Политика:

- По умолчанию методы возвращают `ProcessResult` **всегда**, даже если exitCode != 0 (так проще для UI/Jobs).
- Но добавь опцию `$options['throw'] = true`, при которой exitCode != 0 → кидаем исключение `ResticProcessException` (со safe info).

------

# 8) Что изменить/добавить в кодовой базе (deliverables)

Реализатор должен предоставить:

1. Полный код:
   - `src/Services/ResticRunner.php`
   - `src/DTO/ProcessResult.php`
   - новые exceptions (если добавляешь)
2. Обновления `composer.json` пакета, если нужны зависимости (Symfony Process уже приходит с Laravel через symfony/*, но если нет — аккуратно добавить).
3. Примеры ручной проверки (команды):

### Manual checks

- В Tinker:
  - `BackupSetting::singleton()` заполнить repo/password/keys
  - вызвать `$runner->version()`, `$runner->snapshots()`
- Должно быть видно:
  - exitCode
  - stderr при ошибке
  - secrets не присутствуют в `toArray()`/логах и safeCommand

------

# 9) Acceptance criteria

Шаг 3 считается выполненным, если:

- `ResticRunner` запускает команды через Symfony Process массивом аргументов (без shell)
- Секреты передаются только через env и нигде не выводятся
- `snapshots()` корректно возвращает `parsedJson` (при наличии репозитория)
- Все методы возвращают `ProcessResult` со стабильной структурой
- Есть понятная обработка ошибок и конфигурации
- Код расширяем для шагов 4–7 (Jobs, restore wizard и т.п.)

------

# 10) Важные замечания

- Не добавляй UI/Filament на этом шаге.
- Не храни секреты в “meta” или “stdout/stderr” искусственно.
- Не делай “умных” авто-init репозитория — это позже.

## PROMPT (Шаг 4) — `RunBackupJob`: дамп БД + restic backup + retention + запись в backup_runs

Ты — senior Laravel 12 / PHP 8.4 инженер. У нас есть пакет `siteko/filament-restic-backups` (локально в `packages/siteko/restic-backups`). Шаги 1–3 уже реализованы:

- `Siteko\FilamentResticBackups\Models\BackupSetting` — singleton, encrypted casts работают (`access_key`, `secret_key`, `restic_password`)
- `Siteko\FilamentResticBackups\Models\BackupRun` — таблица `backup_runs` существует (есть `type`, `status`, `meta`, `started_at`, `finished_at`)
- `Siteko\FilamentResticBackups\Services\ResticRunner` — выполняет `backup()`, `snapshots()`, `forget()`, `check()`, `restore()`, возвращает `ProcessResult`

Твоя задача — реализовать **очередной Job**, который:

1. ставит lock,
2. делает дамп БД в `storage/app/_backup/db.sql.gz` (стримингом),
3. запускает `restic backup` (проект + дамп) с тегами,
4. запускает `forget/prune` по retention,
5. пишет запись в `backup_runs`,
6. гарантированно снимает lock даже при ошибках.

Никакого UI и restore тут не делаем — только backup job (+ опционально команда для запуска).

------

# 0) Ограничения/важные требования

- Laravel 12
- Запуск должен быть безопасным для больших БД: **нельзя** грузить дамп целиком в память.
- Нельзя светить секреты в логах/`meta`/исключениях.
- Job должен быть “готов к продакшену”: корректные таймауты, retry-политика, атомарные статусы `backup_runs`, lock в `finally`.
- Дамп БД должен быть пригоден для восстановления: для MySQL/MariaDB использовать параметры дампа, минимизирующие блокировки и повышающие консистентность.

------

# 1) Файлы/классы, которые нужно добавить/изменить

## 1.1. Job

Создай `src/Jobs/RunBackupJob.php`:

- implements `ShouldQueue`
- используй `Queueable`, `InteractsWithQueue`, `SerializesModels` (стандартно)
- задай параметры выполнения:
  - `public $timeout = 7200` (или больше)
  - `public $tries = 1` (важно: backup не должен дублироваться при ретраях без контроля)
  - `public $backoff = [60]` (если решишь retries включить — обоснуй)

Job должен принимать опциональные параметры:

- `array $tags = []` — теги для снапшота
- `?string $trigger = null` — `manual|schedule|system` (для meta)
- `?string $connectionName = null` — какую DB connection дампить (default = `config('database.default')`)
- `bool $runRetention = true` — запускать ли `forget/prune` после backup

## 1.2. (Опционально, но желательно) Console Command для запуска

Чтобы “по клику и по расписанию” было проще подключать, добавь минимальную команду:

`src/Console/RunBackupCommand.php` (или `Commands/RunBackupCommand.php`)

- `php artisan restic-backups:run`
- опции: `--tags=...` (CSV), `--trigger=manual|schedule`, `--connection=...`, `--sync` (выполнить синхронно для отладки)
- по умолчанию: dispatch в очередь.

Зарегистрируй команду в `ResticBackupsServiceProvider` (в `boot()` или `register()`).

> Если не хочешь добавлять command на этом шаге — объясни, как запускать job из scheduler (но лучше сделать command).

------

# 2) Лок (Lock) — строго обязательный

Job должен ставить lock перед любыми действиями.

Требования:

- Используй `Cache::lock(...)` (Laravel atomic locks).
- Ключ лока: например `restic-backups:run-backup`
- TTL: минимум 2 часа (`7200`) или больше.
- Если lock уже занят:
  - Job должен завершиться корректно (status в `backup_runs` можно не создавать, либо создать запись со статусом `skipped` — выбери один вариант и придерживайся его).
- Lock **обязательно** освобождать в `finally`.

------

# 3) Запись в `backup_runs` — жизненный цикл

С самого начала job нужно создать запись `BackupRun`:

- `type = 'backup'`
- `status = 'running'`
- `started_at = now()`
- `meta` минимально:
  - `trigger` (manual/schedule)
  - `tags` (без секретов)
  - `project_root` (можно)
  - `dump_path` (будущий)
  - `host` / `app_env` (по желанию)

По завершению:

- при успехе:
  - `status = 'success'`
  - `finished_at = now()`
- при ошибке:
  - `status = 'failed'`
  - `finished_at = now()`
  - `meta.error_class`, `meta.error_message` (со **строгой очисткой** от секретов)
  - `meta.step` где упало: `dump|restic_backup|retention`

Также сохраняй в `meta` результаты `ProcessResult` (в урезанном виде):

- `backup.exitCode`, `backup.durationMs`, `backup.stderr` (обрезать до лимита), `backup.stdout` (обрезать)
- `forget.*` аналогично

Лимитируй размер stdout/stderr (например 200 KB), чтобы не раздувать БД.

------

# 4) Дамп БД: `storage/app/_backup/db.sql.gz`

## 4.1. Требование по пути

Дамп всегда должен лежать строго здесь:

- `storage_path('app/_backup/db.sql.gz')`

Job обязан:

- создать директорию `storage/app/_backup` если её нет
- перезаписать файл дампа при каждом запуске (это ок благодаря lock)

## 4.2. Драйверы БД

Минимально обязательно поддержать **MySQL/MariaDB** (у нас это основной кейс).

Желательно добавить поддержку:

- PostgreSQL (`pg_dump` → gzip)
- SQLite (дамп = копия файла БД; gzip опционально)

Если не добавляешь поддержку других драйверов — сделай явную ошибку “driver not supported”.

## 4.3. Как делать дамп (ВАЖНО: без OOM)

Запрещено:

- `file_put_contents($path, gzencode($process->getOutput()))` — это держит всё в памяти
- хранить дамп в строке целиком

Разрешено (рекомендуется):

- Запускать `mysqldump` через Symfony Process и **стримить stdout чанками** в `gzopen()` файл через callback.
- Пример подхода:
  - открыть `$gz = gzopen($dumpPath, 'wb9')`
  - `Process::run($callback)` и в callback писать только stdout (TYPE_OUT) в gz.
  - stderr — собирать отдельно (с лимитом).

## 4.4. Параметры mysqldump

Сформируй параметры так, чтобы дамп был консистентным и быстрым:

- `--single-transaction` (для InnoDB)
- `--quick`
- `--routines --triggers --events` (желательно)
- `--set-gtid-purged=OFF` (часто полезно)
- опционально `--column-statistics=0` (для совместимости)
- не используй интерактивный пароль в аргументах (не светить пароль):
  - предпочтительно через env `MYSQL_PWD` для процесса
  - либо через `--password=...` **нельзя** (засветится)
- хост/порт/юзер/база — из `config('database.connections.<name>')`

Если `mysqldump` бинарник не найден — понятная ошибка.

## 4.5. Как определять параметры подключения

- По умолчанию использовать connection из Job параметра или `config('database.default')`.
- Поддержать:
  - TCP (`host`, `port`)
  - сокет (если задан `unix_socket`)
- Учитывай, что у Laravel может быть `DB_SOCKET`.

------

# 5) Restic backup: проект + дамп + теги

## 5.1. Какие paths бэкапить

Требование “проект + дамп” можно выполнить так:

- делаем дамп в `storage/app/_backup/db.sql.gz`
- затем вызываем `ResticRunner->backup()` на `project_root`

То есть дамп “внутри проекта” уже попадёт в backup.

Дополнительно:

- если в settings `project_root` пусто, используй `base_path()` как fallback.

## 5.2. Теги

Собери теги:

- входные `$tags` из job (если есть)

- - дефолтные:

  - `app:<app.name>`
  - `env:<app.env>`
  - `host:<hostname>`
  - `trigger:<manual|schedule>`
  - `type:full` (или `type:backup`)

Передавай теги в `ResticRunner->backup($paths, $tags)`.

## 5.3. Обработка результата

- Если `backup.exitCode != 0`:
  - статус run = failed
  - retention не выполняй (или выполняй, но только если явно указано; по умолчанию **не выполняй**)
  - lock освобождай в finally

------

# 6) Retention: forget + prune

После успешного backup:

- Возьми `$settings->retention` (массив)
- Если retention пустой — пропусти этот шаг (но запиши в meta `retention.skipped=true`)
- Иначе вызови `ResticRunner->forget($settings->retention, ['prune' => true])`

Если retention упал:

- BackupRun можно отметить как `failed` (строго) или `success_with_warnings` (если у тебя есть такой статус).
- Выбери один подход и документируй.
- Я рекомендую: `failed` если retention обязателен, или `success` + meta warning если retention вторичен.
  На этом шаге сделай проще: **`failed`**, чтобы не накапливать мусор.

------

# 7) Очистка/финализация

После всего:

- В meta запиши:
  - `dump.size_bytes` (файловый размер)
  - `dump.duration_ms`
  - `backup.duration_ms`
  - `retention.duration_ms`
- Освободи lock в `finally`.
- Закрой файловые дескрипторы (gz) даже при ошибке (try/finally вокруг дампа).

------

# 8) Безопасность логов (обязательная)

Нельзя сохранять в meta/лог:

- `access_key`, `secret_key`, `restic_password`
- repository, если он содержит креды
- строку команды с паролями

Если сохраняешь “команду” — только safeCommand из `ProcessResult` (уже должен быть безопасным из шага 3).

------

# 9) Инструкции для ручного теста (обязательный output)

В конце реализации выдай пошаговую проверку:

1. Заполнить `BackupSetting::singleton()` данными (repo/keys/password).
2. Убедиться, что `restic` установлен и repo инициализирован (если нет — временно пропустить реальный backup, но job должен корректно упасть с понятной ошибкой).
3. Запустить:
   - `php artisan restic-backups:run --sync` (если команду сделал)
   - или `RunBackupJob::dispatchSync()` в tinker
4. Проверить:
   - появился/обновился `storage/app/_backup/db.sql.gz`, размер > 0
   - в `backup_runs` появилась запись со статусом success/failed
   - в `meta` нет секретов
   - lock не “завис” (повторный запуск возможен)

------

# 10) Acceptance Criteria

Шаг 4 считается выполненным, если:

- Job выполняется через очередь и не зависит от web-таймаутов
- Lock предотвращает параллельные бэкапы и всегда снимается
- Дамп БД создаётся стримингом, без загрузки в память, файл gzip валидный
- Restic backup запускается и результат сохраняется в `backup_runs.meta` (без секретов)
- Retention `forget --prune` выполняется после успешного backup
- Любая ошибка корректно переводит `backup_runs` в failed и записывает шаг/сообщение

------

## PROMPT (Шаг 5) — Filament 4 UI: Backups Settings + Run Backup Action + Runs list (logs)

Ты — senior Laravel 12 / Filament 4 разработчик. В пакете `siteko/filament-restic-backups` (в `packages/siteko/restic-backups`) уже реализованы шаги 1–4:

- `BackupSetting::singleton()` — хранит настройки (endpoint/bucket/prefix/access_key/secret_key/restic_repository/restic_password/retention/schedule/paths/project_root), секреты шифруются encrypted cast, скрыты из `toArray()`.
- `BackupRun` модель/таблица `backup_runs` — хранит историю запусков (`type`, `status`, `started_at`, `finished_at`, `meta` json).
- `ResticRunner` — выполнен (шаг 3), но в UI на шаге 5 напрямую не используется.
- `RunBackupJob` — выполнен (шаг 4), можно диспатчить.
- Filament plugin подключён и уже есть navigation group “Backups”.

Нужно сделать **минимально полезный UI** в Filament 4:

1. Страница **Settings** с формой редактирования `BackupSetting` (singleton).
2. Страница **Runs** со списком `backup_runs`, фильтрами и просмотром деталей/логов.
3. На Settings добавить действие **“Run backup now”**: диспатчит `RunBackupJob` и показывает нотификацию “queued”.

------

# 0) Ограничения и принципы

- Никаких restore UI на этом шаге.
- Не отображать секреты в открытом виде:
  - `access_key`, `secret_key`, `restic_password` в форме должны быть masked (password input) и не должны “вытекать” при рендере.
- Не допускать случайной перезаписи секретов пустыми строками.
- UI должен быть совместим с Filament 4 (Pages/Forms/Tables), без устаревших паттернов Filament 2/3.
- Доступ к разделу — только пользователям панели, которые уже имеют доступ (как было заложено в BaseBackupsPage/Plugin). Если есть механизм permission в конфиге — используй его.

------

# 1) Навигация и структура раздела Backups

В навигации в группе “Backups” должны быть:

- **Settings**
- **Runs**

Порядок:

1. Settings (sort меньше)
2. Runs

Иконки — любые стандартные из Filament/Heroicons, но не критично.

------

# 2) Settings Page (singleton edit form)

## 2.1. Класс/расположение

Создай/обнови страницу (в пакете) например:

- `src/Filament/Pages/BackupsSettings.php`

Если уже есть заглушка `BackupsSettings`, переделай её.

## 2.2. Источник данных

Форма должна работать с `BackupSetting::singleton()`:

- при открытии страницы — загружать текущие значения в форму
- при сохранении — обновлять существующую запись (не создавать новую)

Важно:

- если записей больше одной (аномалия) — использовать `singleton()` и сохранять туда.

## 2.3. UX формы: секции/поля

Сделай форму с понятными секциями:

### A) Storage (S3)

Поля:

- `endpoint` (TextInput, placeholder `https://s3.example.com`, nullable)
- `bucket` (TextInput, nullable)
- `prefix` (TextInput, nullable, helper “optional folder/prefix inside bucket”)

Секреты:

- `access_key` (TextInput, type=password)
- `secret_key` (TextInput, type=password)

Поведение секретов:

- При загрузке формы НЕ показывать реальное значение (или показывать как “********”).
- Если пользователь оставил поле пустым — НЕ перезаписывать значение в БД.
- Если пользователь ввёл новое — сохранить новое (encrypted cast сам отработает).

> Реализация этого поведения обязательна. Filament обычно заполняет state из модели — это нельзя делать для секретов.

### B) Restic repository

- `restic_repository` (TextInput или Textarea, nullable)
- `restic_password` (password input, с тем же поведением как и секреты выше)

### C) Retention

Секция “Retention policy”:

- `retention.keep_daily` (numeric, min 0)
- `retention.keep_weekly` (numeric)
- `retention.keep_monthly` (numeric)
- (опционально) `retention.keep_last`, `retention.keep_yearly`
- helper text: “If empty/0 — retention step may be skipped”.

Хранение: это json поле `retention` в модели.

### D) Schedule

Секция “Schedule”:

- `schedule.enabled` (toggle)
- `schedule.daily_time` (time input, format HH:MM)
  (или Select с шагом 15 минут)
- (опционально) `schedule.timezone` (string, default app timezone)

Пока это только хранение, реально scheduler будет позже, но UI должен быть готов.

### E) Paths

Секция “Paths”:

- `project_root` (TextInput) — по умолчанию `base_path()` (подставить при первом сохранении, либо показать текущий)
- `paths.include` (Repeater или TagsInput) — массив путей/паттернов
- `paths.exclude` (Repeater/TagsInput)

Важно:

- если `paths.include` пуст — считается “backup whole project_root” (это пояснить helper text).

## 2.4. Валидация

Минимальная валидация:

- `endpoint` должен быть URL (но allow empty)
- `bucket` — строка без пробелов (мягко)
- `project_root` — строка, но желательно проверить что директория существует (опционально)
- retention поля — integer >= 0

Секреты — nullable.

## 2.5. Сохранение

На странице должна быть кнопка Save (стандарт).
При успешном сохранении:

- Notification “Saved”
- Секретные поля после сохранения должны очищаться в форме (не показывать введённое значение снова).

------

# 3) Action “Run backup now”

На Settings странице добавь action/button:

- Label: “Run backup now”
- Требует подтверждение (modal confirm): “This will start a backup job in the queue…”
- При клике:
  - `dispatch()` или `dispatchSync()` по опции? На этом шаге строго: **dispatch в очередь**.
  - Передать tags и trigger:
    - trigger = `manual`
    - tags = минимум `['trigger:manual']` (или пусто; job сам добавит дефолты)
  - показать Notification:
    - title “Backup queued”
    - body “Backup job has been queued and will run in background.”
- (желательно) блокировать кнопку если сейчас есть активный lock, но если это сложно — допускается просто диспатчить и job сам “skip” сделает.
  Если реализуешь disabled-state:
  - проверка через cache lock `restic-backups:run-backup` (read-only)

------

# 4) Runs Page (таблица `backup_runs`)

## 4.1. Класс/расположение

Создай/обнови страницу:

- `src/Filament/Pages/BackupsRuns.php`
  (или `BackupsDashboard` переименуй, но лучше сделать отдельную “Runs” вместо “Dashboard”)

Навигация label: “Runs” или “History”.

## 4.2. Таблица

Отображать записи `BackupRun` отсортированные по `started_at desc` (или `id desc`).

Колонки:

- `started_at` (datetime)
- `finished_at` (datetime, nullable)
- `type` (badge)
- `status` (badge with color mapping)
- `duration` (computed: finished-started в секундах)
- `trigger` (из meta.trigger, если есть)
- `tags` (meta.tags, compact)
- `exitCode` (из meta.backup.exitCode если есть)

Фильтры:

- status (success/failed/running/skipped)
- type (backup/check/retention/restore etc; пока хотя бы backup)
- date range (started_at)

Действия строки:

1. **View** (открывает modal или отдельную страницу деталей)
2. (опционально) “Copy error” если failed

## 4.3. Просмотр логов/деталей

В View должны быть:

- основные поля (type, status, started_at, finished_at)
- meta в структурированном виде:
  - dump duration/size
  - restic backup exitCode/duration
  - retention exitCode/duration
- stdout/stderr показывать аккуратно:
  - в `Textarea`/`Code` стиле
  - большие строки — collapse/expand
  - показывать максимум N символов (если job уже обрезал — просто показывай как есть)
- Если run failed:
  - показать `meta.step` где упало
  - `meta.error_message`

Важно:

- никаких секретов (в meta их не должно быть, но UI всё равно не должен пытаться “показать settings” здесь)

------

# 5) Технические требования Filament 4

- Используй корректные классы Filament 4 (Pages + Forms + Tables).
- Не использовать устаревшие namespace из Filament 2/3.
- Вынеси повторяющиеся настройки (navigation group, canAccess) в `BaseBackupsPage` если он уже есть.
- Страницы должны регистрироваться через `ResticBackupsPlugin`.

------

# 6) Команды/проверки после реализации (обязательный output)

Реализатор должен дать инструкцию как проверить:

1. Открыть Filament админку → Backups → Settings
2. Ввести тестовые значения (включая секреты), сохранить
3. Убедиться в БД:
   - секреты зашифрованы
4. Нажать “Run backup now”
   - увидеть Notification “queued”
5. Перейти в Backups → Runs
   - увидеть запись `running`/`success`/`failed`
6. Открыть View run и увидеть stderr/stdout/step без секретов

------

# 7) Acceptance criteria

Шаг 5 выполнен, если:

- Settings page реально редактирует singleton `backup_settings`
- Секретные поля:
  - не подставляются в форму при загрузке
  - не затираются пустотой при сохранении
  - сохраняются при вводе нового значения
- Кнопка “Run backup now” диспатчит `RunBackupJob` и показывает queued notification
- Runs page показывает список запусков и позволяет открыть детали/логи
- Нет ошибок Filament/Laravel, страницы доступны из навигации

------

Если нужно, в конце добавь список TODO для следующего шага (Snapshots list + Restore wizard), но код restore не делать.

------

Если хочешь — следующим сообщением подготовлю такой же “жёсткий” промт на **Шаг 6 (Snapshots list из restic + wizard stub restore)**.

Ниже — **подробный промт для AI-реализатора** на **Шаг 6**: страница Snapshots (получение `restic snapshots --json` через `ResticRunner->snapshots()`), таблица + action “Restore…” (пока только wizard UI, без выполнения восстановления).

------

## PROMPT (Шаг 6) — Filament 4: Snapshots list из restic + Restore wizard stub

Ты — senior Laravel 12 / Filament 4 разработчик. У нас есть пакет `siteko/filament-restic-backups` (в `packages/siteko/restic-backups`). Уже реализованы шаги 1–5:

- `BackupSetting` (singleton) хранит repo/password/S3-ключи (encrypted casts).
- `ResticRunner` (шаг 3) умеет `snapshots()` и возвращает `ProcessResult` с `parsedJson`.
- `RunBackupJob` (шаг 4) работает.
- Filament раздел “Backups” (шаг 5) содержит Settings и Runs.

Нужно реализовать **страницу Snapshots**, которая показывает снапшоты из restic, и добавить action “Restore…” как **wizard-заглушку** (подготовка UI), **без реального восстановления**.

------

# 0) Ограничения и цели

- Никакого фактического restore (не трогать файлы/БД).
- Страница должна быть полезной сама по себе: список снапшотов, фильтры, обновление.
- Ошибки конфигурации/доступа к repo должны отображаться аккуратно (без секретов).
- Команда: `restic snapshots --json` вызывается только через `ResticRunner->snapshots()`.

------

# 1) Добавить страницу Snapshots в Filament раздел Backups

## 1.1. Класс/расположение

Создай страницу в пакете:

- `src/Filament/Pages/BackupsSnapshots.php` (предпочтительно)
  или `src/Filament/Pages/Snapshots.php` (но лучше сохранять нейминг “Backups…” как остальные)

И зарегистрируй в `ResticBackupsPlugin` так, чтобы она появилась в меню группы “Backups” рядом с Settings и Runs.

Рекомендуемый порядок в навигации:

1. Settings
2. Snapshots
3. Runs

------

# 2) Получение данных: `ResticRunner->snapshots()`

## 2.1. Где вызывать

На странице Snapshots:

- при первичной загрузке страницы загружать список снапшотов
- при клике “Refresh” — перезагружать

Важно: не делай постоянных запросов/реактивного поллинга по умолчанию (restic может быть тяжёлый).

## 2.2. Обработка результата

`ResticRunner->snapshots()` возвращает `ProcessResult`:

- если `exitCode === 0` и `parsedJson` массив — используем
- если `exitCode != 0` или `parsedJson == null`:
  - покажи Notification/Alert на странице с сообщением
  - отобрази пустую таблицу
  - добавь кнопку “Open Settings” (перейти на Settings), чтобы пользователь исправил repo/password

Обязательно: не показывать секреты.
Можно показывать только:

- exitCode
- короткий stderr (обрезанный)
- подсказка “Check repository settings / restic init”

------

# 3) Таблица Snapshots (источник данных)

## 3.1. Формат данных restic snapshots --json

Поддержи как минимум структуру снапшота, где есть:

- `id` (full id)
- `short_id` (если есть)
- `time` (datetime string)
- `hostname`
- `tags` (array)
- `paths` (array)

Примечание: restic JSON может иметь немного разные поля по версиям — делай код tolerant:

- если `short_id` отсутствует — показывай первые 8 символов `id`
- если `tags` null — показывай пусто
- если `paths` пусто — показывай “—”

## 3.2. Колонки

В таблице Filament вывести колонки:

- **ID**: short_id (8–10 символов) + action “copy full id”
- **Time**: time (локальная TZ админки; формат `Y-m-d H:i:s`)
- **Host**: hostname
- **Tags**: теги как badge/chips (compact)
- **Paths**: кратко: либо первая строка + “+N”, либо tooltip/popover со списком

Сортировка:

- default: time desc

Пагинация:

- да (например 25/50)

Фильтры:

- by tag (Select из уникальных тегов, если легко)
- by host (Select из уникальных hostnames)
- date range (если удобно)
  Минимум: tag + host.

------

# 4) Row actions

## 4.1. Action “Restore…”

Добавь действие строки **Restore…**.

Важно: это только wizard UI, без выполнения.

Wizard должен содержать:

### Step 1: Confirm snapshot

- показать выбранный snapshot: short_id, time, host, tags
- предупреждение: “Restore is a destructive operation. This wizard currently DOES NOT perform restore (stub).”
- кнопка Continue

### Step 2: Choose scope

Radio buttons:

- `files`
- `db`
- `both`

Дополнительно (опционально):

- checkbox “I understand this will overwrite newer data” — но можно оставить на шаг 7.

Кнопка Finish:

- по нажатию просто показывает Notification:
  - title “Restore wizard (stub)”
  - body “Selected: files/db/both. Actual restore will be implemented in step 7.”
- Никаких jobs не диспатчить.

Сохрани выбор в переменные wizard state (чтобы было легко подключить реальный restore job на шаге 7).

## 4.2. Action “View details” (желательно)

Добавь ещё одно действие:

- “Details” — открывает modal с JSON-деталями снапшота (pretty printed)
  Это полезно для отладки.

------

# 5) Page actions

Добавь actions в верхней части страницы:

- **Refresh**: перезагружает данные из restic
- (опционально) **Check repo**: вызывает `ResticRunner->check()` и показывает результат (можно, но не обязательно в шаге 6)
- (опционально) link to Settings

------

# 6) Производительность и UX

- Кэширование: допускается кэшировать список снапшотов на 10–30 секунд в рамках запроса или через Cache::remember на короткое время, чтобы не дергать restic слишком часто при навигации.
  Но не делай долгий кэш, чтобы не путать пользователя.
- Большие поля (paths/tags) показывать компактно.

------

# 7) Безопасность

- Никаких секретов в UI.
- stderr ограничить (например 500–2000 символов).
- Не показывать repository URL, если он может содержать креды (в нашем случае не должен, но лучше перестраховаться).

------

# 8) Что должен выдать реализатор (output)

Реализатор должен предоставить:

1. Код новой страницы + регистрация в plugin
2. Реализация таблицы с данными из `ResticRunner->snapshots()`
3. Restore wizard stub (без выполнения)
4. Инструкцию ручной проверки:

### Manual checks

- В Settings заполнить repo/password/S3 keys
- Нажать Refresh на Snapshots
- Увидеть список снапшотов (с short id)
- Нажать Restore… → выбрать scope → Finish → увидеть уведомление (и убедиться, что ничего не восстановилось)

------

# 9) Acceptance criteria

Шаг 6 выполнен, если:

- В Filament появился пункт Snapshots в группе Backups
- Таблица показывает реальные снапшоты из `restic snapshots --json`
- Ошибки repo показываются понятным сообщением без секретов
- Action “Restore…” открывает wizard с выбором files/db/both и завершает без реального действия
- Код расширяем для шага 7 (реальное восстановление)

------

## PROMPT (Шаг 7) — Restore Job + безопасный wizard (Filament 4)

Ты — senior Laravel 12 / PHP 8.4 / Filament 4 инженер. В пакете `siteko/filament-restic-backups` (путь: `packages/siteko/restic-backups`) уже реализованы шаги 1–6:

- `BackupSetting::singleton()` — настройки repo/S3/retention/schedule/paths (секреты encrypted)
- `BackupRun` — таблица `backup_runs` (type/status/started_at/finished_at/meta json)
- `ResticRunner` — `restore(snapshotId, targetDir)` и остальные команды, возвращает `ProcessResult`
- `RunBackupJob` — делает DB dump → restic backup → retention → пишет `backup_runs`
- Filament раздел Backups: Settings, Runs, Snapshots
- На Snapshots есть action “Restore…” (wizard stub) — нужно превратить в реальный безопасный wizard + запуск restore job.

Нужно реализовать:

1. **`RunRestoreJob`** — реально выполняет восстановление
2. **Wizard в Filament** — собирает параметры, требует подтверждение вводом фразы, диспатчит job, показывает “queued”
3. **Логирование** — запись в `backup_runs` (type=restore) + сохранение stdout/stderr шагов (с лимитами), без секретов.

------

# 0) Главные принципы безопасности (обязательны)

- Restore — разрушительная операция. По умолчанию должен быть включён **maintenance mode**.
- Нельзя показывать/логировать секреты (S3 keys, restic password).
- Job должен работать “как в проде”: без web-таймаутов, через очередь.
- Должна быть защита от параллельных restore/backup: **global lock**.
- Перед началом restore сделать **safety-backup** (по умолчанию включено) — чтобы можно было откатить, если restore пошёл не туда.
- Всё внешнее выполнять через **Symfony Process** (без shell-командной строки), как в шаге 3.

------

# 1) Параметры restore (что выбирает пользователь)

## 1.1. Snapshot

- snapshot id (full id или short id, но job должен использовать full id если возможно)

## 1.2. Scope (объём восстановления)

Radio:

- `files` — только файлы проекта
- `db` — только база данных
- `both` — файлы + БД

## 1.3. Restore mode (только для files/both)

Radio:

- `rsync` — восстановить во временную папку → `rsync -a --delete` в `project_root`
- `atomic` — восстановить во временную папку → подготовить “новую директорию” → атомарно заменить `project_root`

## 1.4. Safety backup (по умолчанию ON)

Toggle:

- `Create safety backup before restore` (default true)

## 1.5. Подтверждение

Текстовый input, который должен совпасть **строго** с заданной фразой.
Формат фразы (пример, выбери и зафиксируй):
`RESTORE <APP_NAME> <SHORT_ID> <SCOPE>`

Например:
`RESTORE kratonshop db9d66b1 both`

В wizard прямо покажи эту фразу (copy-friendly), пользователь должен ввести её вручную.

------

# 2) Wizard в Filament (реальный запуск)

## 2.1. Где реализовать

На странице Snapshots (где список) action “Restore…” должен:

- открыть wizard со Step 1/2/3
- на Finish:
  - провалидировать confirmation phrase
  - диспатчить `RunRestoreJob` (queue)
  - показать Notification “Restore queued”
  - записать, какие параметры выбраны (можно в meta будущего run, или передать job)

Важно: wizard не должен выполнять restore синхронно в веб-запросе.

## 2.2. Steps

**Step 1: Snapshot info + предупреждение**

- short_id, time, host, tags, paths
- warning блок “Destructive operation”

**Step 2: Choose scope + mode**

- scope: files/db/both
- mode: rsync/atomic (disabled если scope=db)
- safety backup toggle

**Step 3: Confirmation**

- показать фразу, которую надо ввести
- input, must match exactly

------

# 3) `RunRestoreJob` — общий алгоритм

Создай `src/Jobs/RunRestoreJob.php` (ShouldQueue), параметры конструктора:

- `string $snapshotId`
- `string $scope` (`files|db|both`)
- `?string $mode` (`rsync|atomic`, nullable если scope=db)
- `bool $safetyBackup = true`
- `?string $trigger = 'manual'`
- `?string $dbConnection = null` (default `config('database.default')`)

Job должен:

1. создать `BackupRun` запись:
   - type = `restore`
   - status = `running`
   - started_at = now
   - meta: snapshotId/shortId, scope, mode, safetyBackup, trigger
2. поставить lock
3. preflight checks
4. maintenance mode
5. safety backup (если включено)
6. restic restore snapshot → temp
7. применить восстановление files/db согласно scope/mode
8. post-steps: clear caches, queue restart (опционально), up
9. update `BackupRun` status success/failed, finished_at, meta (step logs)
10. cleanup temp dirs
11. unlock в `finally`

------

# 4) Locks (обязательное)

- Используй `Cache::lock()` с TTL (например 2–6 часов).
- Ключ: `restic-backups:restore` (и при необходимости общий ключ `restic-backups:maintenance`)
- Если lock занят:
  - не стартовать restore
  - записать run как `skipped` (или вообще не создавать run — но лучше создать и зафиксировать, что пытались)
  - вернуть Notification из UI: “Another backup/restore is running”

Важно: lock освобождать всегда в `finally`.

------

# 5) Preflight checks (до destructive действий)

Перед `artisan down`:

- проверить, что snapshot существует (через `ResticRunner->snapshots()` и поиск id/short_id) — иначе fail сразу
- проверить доступность `project_root` из settings (exists, writable if files restore)
- проверить наличие `restic` binary (через `ResticRunner->version()` или `Process`)
- если scope включает DB — проверить, что драйвер поддержан и есть доступ к DB (простая проверка соединения)

Ошибки писать в `BackupRun.meta.error_*` без секретов.

------

# 6) Maintenance mode (обязательное)

Если scope включает `files` или `both`, перед изменениями:

- выполнить `php artisan down --force` через Process
- после file operations (особенно rsync/atomic swap) **убедиться**, что app всё ещё down:
  - либо снова выполнить `php artisan down --force`
  - либо аккуратно не затереть down-файл (но проще повторно вызвать down)

В конце:

- `php artisan up` через Process

Важно: все artisan команды запускать с `cwd = project_root` и без shell.

------

# 7) Safety-backup (по умолчанию ON)

Перед restore (после `down`, но до изменения файлов/БД) выполнить safety backup текущего состояния.

⚠️ Важно: нельзя словить deadlock на lock’ах backup job.
Сделай один из вариантов (выбери и реализуй):

**Вариант A (рекомендуется): вынести логику backup в сервис и переиспользовать**

- создать `BackupService` (в пакете) с методом `runBackup(trigger, tags, retentionEnabled)`
- `RunBackupJob` и `RunRestoreJob` используют один сервис
- lock берётся на уровне сервисного orchestration так, чтобы не было вложенного lock

**Вариант B: делать safety backup внутри restore job напрямую**

- повторить ключевые шаги из backup job:
  - создать DB dump
  - `ResticRunner->backup(project_root, tags)` с тегами типа `safety-before-restore`, `restore:<shortId>`, `run:<runId>`
  - retention на safety backup можно отключить
- логировать результат в meta текущего restore-run (или отдельным BackupRun type=backup trigger=safety)

Обязательное: safety backup должен либо:

- быть отдельной записью `backup_runs` (лучше), либо
- быть в meta restore-run с полями `safety_backup.*`

------

# 8) Restic restore snapshot → temp

## 8.1. Временная директория

Создавай temp каталог:

- `/tmp/restic-restore-<runId>-<timestamp>` (или внутри `storage/app/_backup/tmp/`)
- очищать после завершения

## 8.2. Вызов restic restore

`ResticRunner->restore($snapshotId, $targetDir)` (через Process)

После restore нужно найти путь к восстановленному проекту внутри targetDir.
Так как restic сохраняет абсолютные пути, ожидаем:

- restoredProjectPath = `$targetDir . '/' . ltrim($projectRoot, '/')`

Например, если `project_root=/var/www/kratonshop`:

- restoredProjectPath = `/tmp/restic-restore-123/var/www/kratonshop`

Проверь, что эта директория существует; если нет — fail (и покажи подсказку “snapshot paths do not include project_root”).

------

# 9) Восстановление файлов (scope files/both)

## 9.1. Mode: rsync --delete

Алгоритм:

1. убедиться, что `restoredProjectPath` существует
2. выполнить `rsync` из `restoredProjectPath/` → `project_root/` с удалением лишнего:
   - `rsync -a --delete ...`
   - запускать через Process массивом аргументов (без shell)

Обязательные нюансы:

- по умолчанию **не трогать текущий `.env`** (иначе можно убить окружение):
  - добавь `--exclude=.env`
  - и отрази это в wizard/описании (или настройкой в будущем)
- опционально: не трогать `storage/framework/down` (или после rsync повторно вызвать `artisan down`)
- после rsync:
  - `php artisan optimize:clear` (или `cache:clear`, `config:clear`, `route:clear`, `view:clear`)

## 9.2. Mode: atomic swap

Цель: минимизировать “полу-обновлённое состояние”.

Реализация должна быть generic (без предположений про release-symlink), но безопасной:

1. подготовить “новый каталог” рядом с project_root на том же filesystem:
   - например `project_root.__restored_<runId>`
2. перенести/скопировать восстановленные файлы туда:
   - можно `rsync -a` (без delete) из `restoredProjectPath/` → `newDir/`
3. сохранить `.env` из текущего `project_root`:
   - скопировать `.env` в `newDir/.env` (если текущий существует)
4. атомарная замена:
   - `mv project_root project_root.__before_restore_<timestamp>`
   - `mv newDir project_root`
5. опционально: оставить oldDir как fallback (и записать путь в meta), но не удалять сразу

После swap:

- `php artisan optimize:clear`
- `php artisan queue:restart` (см. ниже)

Важно:

- все `mv` выполнять через Process, гарантировать, что операции происходят на одном FS (newDir должен быть sibling)
- если swap не удался на середине — в meta записать, что именно случилось, и попытаться откатиться (best effort):
  - если project_root отсутствует, но есть oldDir — вернуть его назад

------

# 10) Восстановление БД (scope db/both)

## 10.1. Где взять дамп

Дамп в snapshot должен лежать по пути:

- `<restoredProjectPath>/storage/app/_backup/db.sql.gz`

Проверь существование файла; если нет — fail с понятной ошибкой.

## 10.2. Драйверы

Минимум: MySQL/MariaDB.
Опционально: Postgres/SQLite (если не делаешь — бросай “driver not supported”).

## 10.3. Как импортировать (без утечки пароля)

Используй CLI клиента (mysql/psql) через Process, пароль передавать через env:

- MySQL: `MYSQL_PWD` в env процесса (не в аргументах)
- Хост/порт/юзер/БД — из `config('database.connections.<conn>')`

## 10.4. Как “затереть новое”

Требование: восстановить БД **точно на момент snapshot**, значит текущую БД нужно очистить.

Выбери и реализуй 1 надёжный вариант:

**Вариант A (предпочтительно): `php artisan db:wipe --force`**

- выполнить `php artisan db:wipe --force` (cwd=project_root, до импорта)
- затем импорт дампа
  Плюс: просто. Минус: зависит от Laravel boot (но обычно ок).

**Вариант B: пересоздать базу (если есть права)**

- `DROP DATABASE` + `CREATE DATABASE` + import
  Минус: не всегда есть права.

**Вариант C: сгенерировать DROP TABLE для всех таблиц**

- получить список таблиц из `information_schema`
- выполнить DROP TABLE … (FK checks off)
- затем import

На этом шаге допускается вариант A как основной, с fallback на C, если wipe не доступен.

## 10.5. Импорт gz

Импорт должен быть streaming:

- `gunzip -c db.sql.gz | mysql ...` — **но без shell**.
  Значит делай 2 Process:

1. gunzip process, stdout pipe
2. mysql process, stdin pipe
   или используй PHP gzopen + запись в stdin mysql процесса (stream).

(Важное требование: не загружать весь дамп в память.)

------

# 11) Queue stop/restart (опционально, но полезно)

НЕЛЬЗЯ “останавливать очередь”, если restore job сам выполняется в очереди (убьёшь себя).
Поэтому сделай безопасный минимум:

- после успешного file restore (особенно atomic swap) выполнить:
  - `php artisan queue:restart` (через Process)
    Это заставит остальных воркеров перезапуститься и подхватить новое состояние кода.

Если хочешь добавить опцию “operator must stop systemd queue manually” — только текстом, но кодом не пытайся останавливать systemd.

------

# 12) Логирование в `backup_runs` (обязательное)

В `BackupRun.meta` нужно сохранить шаги и результаты (с лимитами):

- `steps.maintenance.down` (exitCode, duration, stderr truncated)
- `steps.safety_backup` (если был)
- `steps.restic_restore` (exitCode, duration, stderr)
- `steps.files_sync` (rsync/mv results)
- `steps.db_wipe`
- `steps.db_import`
- `steps.optimize_clear`
- `steps.queue_restart`
- `steps.maintenance.up`

Обрезай stdout/stderr до лимита (например 200KB) чтобы не раздувать БД.

Статусы:

- `running`
- `success`
- `failed`
- (опционально) `skipped`

В error case:

- `meta.error.step`
- `meta.error.class`
- `meta.error.message` (без секретов)

------

# 13) Cleanup

В `finally`:

- удалить temp restore dir (best effort)
- если создан newDir для atomic swap и операция провалилась — удалить/оставить и записать путь
- обязательно release lock

------

# 14) Обновления UI после шага 7

- На Runs странице (шаг 5) убедиться, что `type=restore` и `status` корректно отображаются и можно открыть детали.
- На Snapshots restore wizard после dispatch показывать ссылку “Open Runs” (опционально).

------

# 15) Manual test plan (обязательный output)

Реализатор должен описать, как протестировать безопасно:

1. В staging окружении:
   - сделать 2 бэкапа (до изменений и после)
   - изменить файл/данные в БД
2. В Filament → Snapshots:
   - выбрать “старый” snapshot
   - scope=both
   - mode=rsync (или atomic)
   - подтвердить фразой
3. Проверить:
   - сайт ушёл в maintenance
   - после выполнения job данные и файлы вернулись к состоянию snapshot
   - новые файлы исчезли (rsync --delete) или заменился каталог (atomic)
   - `backup_runs` содержит restore-run с логами шагов
   - `.env` не перезаписался

------

# 16) Acceptance criteria

Шаг 7 выполнен, если:

- Restore запускается только через wizard с подтверждением фразой
- Job ставит lock и не допускает параллельных restore/backup
- Перед restore включается maintenance
- Делается safety backup (по умолчанию) и результат логируется
- Restic restore делается во временную директорию, затем применяется rsync --delete или atomic swap
- DB восстанавливается из db.sql.gz потоково и “затирает новое”
- После restore очищаются кеши, выполняется `queue:restart` (минимум), приложение возвращается `up`
- Все шаги логируются в `backup_runs`, без секретов
- lock снимается всегда

------

Ниже — **отдельный подробный промт “Шаг 7 — улучшенная версия”** (добавления/правки к твоему текущему шагу 7), чтобы:

админка **не лежала весь restore**

файлы **не затирались**, пока новая версия не готова

алгоритм был **staged + atomic swap**

перед стартом был **жёсткий preflight по диску** (если места нет — restore недоступен)



# PROMPT (Шаг 7.2) — Restore v2: staged + atomic swap + минимальный downtime + preflight по месту

Ты — senior Laravel 12 / PHP 8.4 / Filament 4 инженер. В пакете `siteko/filament-restic-backups` уже есть рабочий restore (RunRestoreJob + wizard). Нужно **усилить шаг 7**: поменять порядок операций, добавить staging и проверки по диску, чтобы UX и надёжность были прод-уровня.

### Главная идея

Restore делаем **двухфазно**:

1. **STAGE (долго, без maintenance):**
   - restic restore выбранного snapshot **сразу в staging-директорию на том же диске**, где `project_root`
   - валидации структуры, наличие дампа БД
   - подготовка каталога для swap
   - расчёт места / проверка, что хватит
2. **CUTOVER (коротко, с maintenance):**
   - `artisan down` (с secret bypass для админа)
   - атомарная замена каталогов (rename)
   - DB restore
   - clear caches + queue:restart
   - `artisan up`

В результате:

- админка доступна почти всё время (STAGE),
- файлы не уничтожаются до готовности новой версии,
- в любой момент можно откатиться на старую директорию.

------

# 1) Требования по UX: админка не лежит весь restore

## 1.1. Maintenance включать только на CUTOVER

Запрещено: включать `artisan down` в самом начале job.

Нужно:

- пока идёт restic restore (STAGE) — сайт/админка работают
- down включается только на короткий участок (swap + DB import + post-steps)

## 1.2. Secret bypass для админа

На CUTOVER всё равно будет 503 для всех. Чтобы админ мог зайти (если нужно), включить down с секретом:

- `php artisan down --secret=<generated>` (в job)
- этот секрет:
  - генерируется на старте job (короткий безопасный токен)
  - сохраняется в `backup_runs.meta.restore.secret` (или отдельным полем), **только для пользователей с доступом к Runs**
  - в UI показывается как “bypass URL path: /” (без домена)

Важно: даже при bypass админ не должен активно “кликать админку” во время cutover. Это больше как аварийный доступ и просмотр статуса.

------

# 2) Надёжность: staged + atomic swap, никаких “delete в бою”

## 2.1. Запрещено rsync --delete прямо в project_root

Если выбран режим atomic swap — никаких прямых затираний/удалений в текущем `project_root` до момента cutover.

## 2.2. Staging-директория должна быть на том же filesystem

Atomic swap возможен только если staging и `project_root` на одном FS (rename атомарный).

Требование:

- `staging_dir` должен быть sibling рядом с проектом, например:
  - `project_root.__restored_<runId>`
  - `project_root.__swap_<runId>` (если нужно)
- Запрещено: staging в `/tmp`, если `/tmp` на другом FS.

Добавь проверку “same filesystem”:

- сравнить `stat()` / `st_dev` для `project_root` и `dirname(staging_dir)`
- если не совпадает — блокировать atomic swap (либо предлагать rsync-режим)

## 2.3. Старая версия проекта сохраняется как rollback

На cutover:

- текущее `project_root` переименовывается в `project_root.__before_restore_<timestamp>`
- новый staging становится `project_root`
- старую директорию **не удалять сразу** (оставить для отката)

Добавить настройку (опционально):

- “keep previous version for N hours/days” (можно захардкодить 24–72 часа на этом шаге)
- cleanup можно сделать позже отдельным джобом, но сейчас минимум: **не удалять**.

------

# 3) Жёсткий preflight по дисковому месту

## 3.1. Где проверять

Проверка должна быть в двух местах:

1. **В wizard** (до запуска) — показать пользователю “хватит/не хватит”, если не хватит — disable Finish.
2. **В job** (перед stage restore) — истиная проверка, потому что место могло измениться.

## 3.2. Как считать требуемое место

Для scope `files` или `both`:

1. Попытаться получить размер восстановления для конкретного snapshot:

- `restic stats <snapshotId> --mode restore-size --json`
  - лучше добавить в `ResticRunner` новый метод `statsRestoreSize($snapshotId): int` или универсальный `stats()`
  - если stats недоступен — fallback на `du` текущего `project_root`.

1. Получить свободное место на FS, где лежит `project_root`:

- через PHP `statvfs()` / Symfony Filesystem, либо `df -B1 <project_root>` через Process (без shell конкатенаций).

1. Правило:

- `required_free = expected_restore_size * 1.15 + 2GiB`
- если `free < required_free` → restore запрещён:
  - в wizard: disable + сообщение
  - в job: fail до любых действий, статус run=failed, step=`preflight_space`

Для scope `db`:

- достаточно фиксированного минимума, например `2–5GiB`, либо оценка по размеру дампа (если доступно через restic stats по файлу) — на этом шаге можно просто `min_free_db = 2GiB`.

## 3.3. Что показывать в UI

В wizard добавить блок “Disk space preflight”:

- Free: X GiB
- Estimated restore-size: Y GiB (источник: restic stats / fallback du)
- Required (with buffer): Z GiB
- Result: ✅ / ❌

------

# 4) Новый алгоритм RunRestoreJob (v2) — пошагово

Нужно переработать `RunRestoreJob` под двухфазную схему и rollback.

## 4.1. Общая структура job

1. создать `BackupRun` (type=restore, status=running, meta: snapshotId, scope, mode, flags)
2. lock (общий restore-lock, чтобы не пересекаться с backup/restore)
3. **PRECHECKS (без down):**
   - snapshot exists
   - project_root exists
   - mode atomic доступен (same FS)
   - space preflight (см. выше)
4. **STAGE (без down):**
   - restic restore snapshot сразу в `staging_dir_parent = dirname(project_root)`:
     - targetDir = `project_root.__restored_<runId>`
     - Важно: Restic восстанавливает абсолютные пути, поэтому:
       - либо используйте targetDir как “корневой target”, а затем вычисляйте `restoredProjectPath = targetDir/<ltrim(project_root,'/')>`
       - либо (лучше) восстанавливайте в отдельный target, но итоговый staging каталог формируйте rsync’ом.
         Рекомендация: оставить стандартный restic restore и потом **переложить** в `project_root.__swap_<runId>` один раз rsync’ом (без delete). Но это увеличивает место (до ~3×).
         Оптимум по месту: восстановить так, чтобы staging получился без лишней копии (смотри раздел 2.2).
   - Validate staging:
     - существует `stagingProjectRoot` (путь восстановленного проекта)
     - есть `artisan`, `vendor/autoload.php` (или хотя бы `composer.json` + `artisan`)
     - если scope включает DB: найден `storage/app/_backup/db.sql.gz`
   - Подготовить atomic swap dir (если stagingProjectRoot не совпадает с нужной staging-директорией)
   - Записать в meta пути staging/rollback
5. **CUTOVER (коротко, с down):**
   - сгенерировать `down_secret`, вызвать `artisan down --secret=... --force`
   - (опционально) safety backup текущего состояния (если включено) — но строго:
     - либо отдельный lightweight safety snapshot (желательно)
     - либо хотя бы safety DB dump
     - важно: safety backup должен стартовать до файловых операций и логироваться
   - Files atomic swap:
     - `mv project_root project_root.__before_restore_<ts>`
     - `mv stagingPreparedDir project_root`
     - сохранить `.env`:
       - если `.env` в staging отличается/отсутствует — скопировать `.env` из rollbackDir в новый project_root
   - DB restore (если scope db/both):
     - wipe DB (db:wipe --force или аналог)
     - streaming import из `db.sql.gz` (не в память)
   - post:
     - `php artisan optimize:clear`
     - `php artisan queue:restart`
   - `php artisan up`
6. finish:
   - status success/failed
   - сохранить в meta итоги steps, время, пути rollback/staging
7. finally:
   - unlock
   - temp cleanup (но rollbackDir не удалять)

## 4.2. Rollback (best effort)

Если ошибка случилась на CUTOVER после down:

- попробовать откат:
  - если `project_root` уже заменён, а `rollbackDir` есть:
    - `mv project_root project_root.__failed_restore_<ts>` (если существует)
    - `mv rollbackDir project_root`
- если DB уже wiped и import failed:
  - если safety DB dump есть — попытаться импортировать его обратно (опционально, best effort)
- после rollback попытки:
  - `artisan up` (чтобы не оставить 503)
- сохранить в meta `rollback.attempted=true`, `rollback.success=true/false`

Важно: rollback — не гарантированный, но должен быть “best effort” и логироваться.

------

# 5) Изменения wizard (Filament) под новый restore v2

Wizard должен:

1. Step Snapshot info (как было)
2. Step Options:
   - scope (files/db/both)
   - mode (atomic swap default; rsync как fallback)
   - safety backup toggle
3. Step Preflight:
   - отображение disk space check (free/required)
   - если atomic mode — отображение “same filesystem: OK/FAIL”
4. Step Confirmation phrase (как было)
5. Finish:
   - dispatch `RunRestoreJob` + notify queued
   - показать “During cutover site goes to maintenance briefly. You can use bypass secret from Runs if needed.”

Также:

- если preflight FAIL → Finish disabled.

------

# 6) Логирование шагов (в runs) — расширить

В `backup_runs.meta.steps` добавить отдельные фазы:

- `preflight_space`
- `stage_restic_restore`
- `stage_validate`
- `cutover_down`
- `cutover_swap`
- `cutover_db_wipe`
- `cutover_db_import`
- `cutover_optimize_clear`
- `cutover_queue_restart`
- `cutover_up`
- `rollback_*` (если был)

Для каждого шага:

- exitCode, durationMs
- stdout/stderr truncated
- без секретов

------

# 7) Acceptance criteria (что считается “готово”)

Шаг 7.2 выполнен, если:

- Restore job **не включает maintenance** до stage завершения
- Restic restore идёт в staging, текущий проект не трогается до cutover
- Cutover использует atomic swap (rename на том же FS)
- Старая версия сохраняется как rollbackDir
- Есть preflight по диску:
  - в wizard показывает расчёт и блокирует restore, если места нет
  - в job повторяет проверку и падает до начала restore, если места не хватает
- При ошибке на cutover выполняется best-effort rollback и приложение поднимается обратно
- Все шаги логируются в Runs

------

# 8) Manual test plan (минимальный)

1. На staging:
   - сделать backup (чтобы был snapshot)
   - изменить пару файлов + записи в БД
2. Запустить restore v2:
   - убедиться: пока идёт stage — админка доступна
   - down появляется только на короткое время
   - после restore данные/файлы вернулись
3. Спровоцировать ошибку на cutover (например, сломать права на staging dir):
   - убедиться, что rollback попытался вернуться и сайт поднялся

------

Если хочешь, я могу дополнить промт маленьким “UX-улучшением”: показывать на странице Runs “Phase: Stage / Cutover / Rollback” и примерный прогресс по шагам (без вебсокетов, просто по мета-полям, которые job пишет).





Ниже — **отдельный подробный промт “Step 7 polish”** (как отдельный мини-шаг после текущей реализации Step 7). Он включает:

- гарантию, что maintenance действует и на **новом дереве** (убрать “already up”)
- авто-очистку rollback-директорий через 24 часа (delayed job + scheduler страховка)
- особое внимание к **`.env` и runtime-артефактам** (чтобы restore не подменял живое окружение)
- правила отображения `rollback_path` и safety DB dump в Runs

------

## PROMPT — Step 7 Polish: maintenance correctness + rollback cleanup + .env/runtime safety

Ты — senior Laravel 12 / PHP 8.4 / Filament 4 инженер. В пакете `siteko/filament-restic-backups` уже реализован Restore v2 (staged + atomic swap + preflight). Нужно “навести лоск” и закрыть edge cases. Код должен оставаться безопасным: не раскрывать секреты, логировать шаги в `backup_runs.meta`, не ломать основной restore при сбоях вторичных действий.

### Цели

1. **Убрать “Application is already up”** и гарантировать maintenance на новом дереве после swap
2. **Сохранить “живой” `.env` и runtime-артефакты**, чтобы restore не мог их подменить
3. **Авто-очистка** `__before_restore_*` через 24 часа + страховка через scheduler
4. Улучшить отображение **rollback_path** и **пути к safety DB dump** в Runs (как подсказки для админа)

------

# A) Maintenance mode: корректный down/up при atomic swap

## A1. Проблема

Сейчас `artisan down` выполняется до swap, но после swap новый `project_root` может оказаться “up”, так как down-файл находится внутри `storage/framework/down` и был в старом дереве. Поэтому `artisan up` пишет “already up”.

## A2. Требование

**Maintenance должен гарантированно действовать на новом дереве** в течение всей фазы cutover.

## A3. Реализация (обязательная)

В `RunRestoreJob` изменить cutover sequence:

1. `artisan down --secret=<secret>` (как сейчас) **перед** swap — ок
2. Сделать atomic swap каталогов
3. **Сразу после swap повторно выполнить**:
   - `artisan down --secret=<тот же секрет>` **в новом project_root**
   - это устраняет “already up” и гарантирует, что maintenance включён на новом дереве перед post-steps/DB
4. После всех действий выполнить `artisan up`

Логирование:

- добавь новый шаг в meta: `cutover_down_after_swap`
- `cutover_up` должен теперь реально выключать maintenance (без “already up” в обычной ситуации)

Примечание:

- если down после swap не удался, restore всё равно продолжается, но в meta фиксируется warning. (Однако лучше считать это fail, если strict.)

------

# B) `.env` и runtime-артефакты: защита “живого окружения”

## B1. Проблема

Restic snapshot содержит файлы проекта. Восстановленное дерево может включать:

- `.env`
- `storage/` (включая сессии, кэши, down-file, logs)
- `bootstrap/cache/*`
- прочие runtime данные

При atomic swap есть риск:

- заменить `.env` на версию из snapshot (сломать окружение)
- принести “старый” runtime мусор (cache/session/view) и получить странные баги
- потерять актуальные runtime настройки (например ключи, интеграции, локальные overrides)

## B2. Требование

После restore:

1. `.env` **всегда берётся из текущего живого окружения**, а не из snapshot
2. Runtime артефакты не должны ломать приложение: cache/compiled/view/session/down должны быть в корректном состоянии
3. Поведение должно быть предсказуемым как для `files`, так и для `both`.

## B3. Политика по умолчанию (обязательная)

Сделай фиксированную политику (позже можно вынести в настройки):

### `.env`

- Никогда не использовать `.env` из snapshot.
- Всегда копировать `.env` из **старого live каталога** (rollbackDir, который был project_root перед swap) в новый project_root:
  - если `.env` существует в rollbackDir → `copy(rollbackDir/.env -> newRoot/.env)` (overwrite)
  - если `.env` отсутствует в rollbackDir → оставить как есть (но записать warning в meta)
- `.env` копировать **после swap** и **до artisan commands**, чтобы оптимизационные команды использовали правильную конфигурацию.

Логирование:

- `env_preserve`: source path, target path, ok=true/false

### runtime cleanup

После swap (и повторного down), выполнить cleanup:

- удалить/очистить:
  - `bootstrap/cache/*.php` (опционально) или просто rely on optimize:clear
  - `storage/framework/views/*`
  - `storage/framework/cache/*`
  - `storage/framework/sessions/*` (осторожно: это выкинет сессии пользователей; но во время restore это ожидаемо)
  - `storage/framework/testing/*` (если есть)
- затем выполнить:
  - `php artisan optimize:clear`
  - `php artisan queue:restart`

Важно:

- Если scope = `files` и ты свапнул storage полностью, это означает, что ты принёс старые сессии/кеши из snapshot. Их лучше сбросить, чтобы не было странностей.
- Логи (`storage/logs`) можно оставить, не удалять.

Логирование:

- `runtime_cleanup`: что чистили, exitCode, errors (best effort, не должен ломать restore)

### Симлинки

После swap выполнить best effort:

- `php artisan storage:link` (может вернуть ошибку “link exists” — это ok)
  Логировать как `storage_link`.

------

# C) Auto-cleanup rollback dir через 24 часа

## C1. Требование

После успешного restore:

- rollbackDir (`__before_restore_*`) хранится как аварийная точка отката
- авто-очистка через **24 часа**
- очистка должна быть безопасной (нельзя удалить не ту папку)
- если очередь не работала — должна быть “страховка” через scheduler.

## C2. Delayed job (обязательное)

Создай `CleanupRollbackDirJob`:

- принимает:
  - `string $path`
  - `int $restoreRunId`
  - `Carbon $notBefore` (или timestamp)
- job делает:
  1. валидации безопасности:
     - `$path` начинается с `project_root . '.__before_restore_'` ИЛИ находится в том же parent, и имя содержит `__before_restore_`
     - `$path` не равно `project_root`
     - `$path` существует и это directory
  2. проверка времени:
     - текущее время >= notBefore
  3. удаление рекурсивно (Symfony Filesystem)
  4. логирование результата:
     - либо создать `BackupRun` type=`cleanup`
     - либо (предпочтительно) обновить meta restore-run: `cleanup.scheduled=true`, `cleanup.done=true/false`, `cleanup.at=...`, `cleanup.error=...`

Диспатч:

- в конце успешного restore-run:
  `dispatch(new CleanupRollbackDirJob($rollbackPath, $runId, now()->addDay()))->delay(now()->addDay())`

## C3. Scheduler страховка (обязательная)

Даже если delayed job не выполнится (очередь стояла), scheduler должен раз в сутки чистить старые rollback dirs.

Реализуй команду/джоб:

- `CleanupStaleRollbackDirsJob` или artisan command
- логика:
  - искать в parent директории проекта папки `project_root.__before_restore_*`
  - определить “возраст”:
    - предпочтительно по метке времени в имени (если есть),
    - иначе по `filemtime`
  - удалить старше 24h
  - не трогать каталоги моложе

Интеграция scheduler:

- ежедневный запуск в ночное время (например 04:00) с timezone из settings.

Логирование:

- можно в Laravel log + опционально отдельные записи в `backup_runs` type=`cleanup`.

------

# D) Улучшение Runs UI: rollback path и safety db dump

## D1. Требование

В Run details для restore:

- отображать:
  - `Rollback path` (полный путь)
  - `Safety DB dump path` (если есть)
  - `Bypass path` (уже есть)
- эти строки должны быть “копируемыми” (CopyAction или hint)

## D2. Правило определения safety dump path

Если safety backup делал db dump в `storage/app/_backup/db.sql.gz` перед swap, то после swap этот файл должен оказаться в rollbackDir:

- `safety_dump_path = rollbackDir + '/storage/app/_backup/db.sql.gz'`
  Показывать только если файл существует (или если в meta есть size_bytes).

------

# E) Acceptance criteria (готово, если)

1. После restore `artisan up` больше не пишет “already up” (в нормальном сценарии)
2. `.env` всегда сохраняется из live окружения (rollbackDir → newRoot), и это отражено в meta
3. Runtime мусор очищен (или optimize:clear достаточно) и это отражено в шагах
4. RollbackDir автоматически удаляется через 24 часа:
   - есть scheduled delayed job
   - есть ежедневная страховка scheduler
5. В Runs UI видны rollback path + safety dump path + bypass path

------

# F) Manual test plan

1. Сделать restore files atomic на dev/stage
2. Проверить:
   - down включился до swap и ещё раз после swap
   - up действительно выключает maintenance
   - `.env` совпадает с оригинальным live `.env` (можно временно добавить маркер)
3. Убедиться, что rollbackDir существует сразу после restore
4. Принудительно запустить cleanup job (без ожидания суток) с подменой delay в тесте:
   - убедиться, что удаляет только корректные каталоги

------

Если хочешь, после этого можно спокойно делать Step 8 (нотификации/healthchecks) — там как раз хорошо отправлять уведомление с `rollback_path` и “expires in 24h” для аварийного отката.

------

Ниже — **PROMPT 9** в стиле вашего `promts.md`: “DB-only restore” **без staging всего проекта**, через `restic dump` (или `restore --include` как fallback), с совместимостью с текущим путём дампа `storage/app/_backup/db.sql.gz` и текущими тегами/снапшотами.

## PROMPT 8 — Filament “Backups → Overview” (Dashboard) + краткое здоровье системы

### Цель

Реализовать страницу **Backups → Overview** (Filament page `BackupsDashboard`) вместо текущего `TODO`, чтобы админ сразу видел:

1. состояние репозитория restic (доступен/не инициализирован/ошибка),
2. последние снапшоты (время/ID/теги/кол-во),
3. последние запуски `backup`/`restore` (успех/ошибка/время/длительность),
4. базовую диагностику (свободное место, активный lock, очередь),
5. быстрые действия (Run backup, открыть Runs, открыть Snapshots, открыть Settings).

### Важно (ограничения)

- **Никаких destructive-операций** (init repo / unlock / delete snapshots) — это в будущих промптах (у вас это планируется позже).
- Минимизировать тяжёлые вызовы restic: **кэшировать** результаты на короткое время (например 30–60 сек), чтобы Overview не “подвешивал” панель.
- Не выводить секреты ни в UI, ни в логах.

------

## Что есть сейчас (ориентиры в коде)

- `BackupsDashboard` — заглушка `TODO`.
- Есть `RunBackupCommand restic-backups:run`, который диспатчит `RunBackupJob` (async или sync). Это можно дергать из UI-кнопки “Run backup now”.
- В restore есть “skipped” при невозможности взять lock (`reason: lock_unavailable`) — полезно подсвечивать на Overview как “почему не стартует”.
- В Snapshots UI уже есть preflight-логика оценки места (restic stats restore-size / fallback du). Это можно переиспользовать концептуально, но на Overview сделать облегчённо (только free bytes).

------

## План реализации (шаги)

### Шаг 1. Сервис-агрегатор данных для Overview

Создать класс, например:

- `src/Support/BackupsOverview.php` (или `src/Services/BackupsOverviewService.php`)

Метод:

```php
public function get(): array
```

Возвращает структуру (пример):

```php
[
  'settings' => [
    'configured' => bool,          // есть ли запись BackupSetting::singleton()
    'schedule_enabled' => bool|null,
    'project_root' => string|null,
  ],
  'repo' => [
    'status' => 'ok'|'uninitialized'|'error',
    'message' => string,
    'snapshots_count' => int|null,
    'last_snapshot' => [
      'id' => string|null,
      'short_id' => string|null,
      'time' => string|null,
      'tags' => array,
    ],
  ],
  'runs' => [
    'last_any' => BackupRun|null,
    'last_backup' => BackupRun|null,
    'last_restore' => BackupRun|null,
    'last_failed' => BackupRun|null,
  ],
  'system' => [
    'disk_free_bytes' => int|null,
    'lock' => [
      'likely_locked' => bool,
      'note' => string|null,
    ],
  ],
]
```

Реализация:

- `BackupSetting::singleton()` завернуть в try/catch, если настройки ещё не созданы — `configured=false`.
- `repo.status`:
  - попытаться вызвать “лёгкую” команду restic через `ResticRunner` (лучше всего snapshots `--json`, если уже используется в Snapshots page).
  - если ошибка похожа на “unable to open config file / not a repository” → `uninitialized`
  - иначе → `error` + короткое сообщение
- `snapshots_count` и `last_snapshot`:
  - распарсить JSON массива снапшотов, взять последний по времени (или первый, если restic уже сортирует).
- `runs`:
  - `BackupRun::latest('started_at')`
  - отдельно `where type=backup/restore`
- `system.disk_free_bytes`:
  - `disk_free_space($projectRoot ?? base_path())`
- `system.lock` (best effort):
  - попытаться взять lock тем же ключом, что используют jobs. Если ключ вынести в константу — идеально.
  - логика: `Cache::lock($key, 1)->get()` → если удалось взять и тут же `release()` → `likely_locked=false`, иначе `true`.

Кэширование:

- `Cache::remember('restic-backups:overview', 30, fn()=>...)`
- Либо раздельно: snapshots кэш 30–60 сек, runs без кэша.

------

### Шаг 2. Реализовать UI в `BackupsDashboard`

Заменить `Text::make('TODO')` на нормальную сетку/секции. Сейчас это `content(Schema $schema)` — туда и вставляем компоненты.

Пример структуры страницы:

- **Section: Repository**
  - Status badge: OK / Not initialized / Error
  - Snapshots count
  - Last snapshot: time, short_id, tags
  - Action buttons:
    - “Open Snapshots” → link на `BackupsSnapshots`
    - “Open Settings” → link на `BackupsSettings`
- **Section: Last runs**
  - 3 карточки: Last backup / Last restore / Last failed
  - В каждой: status, started_at, finished_at/duration, trigger (если есть в meta)
  - Кнопка “Open Runs” → link на `BackupsRuns`
- **Section: System**
  - Free disk space (человекочитаемо)
  - Lock indicator:
    - если `likely_locked=true`: подсказка “Возможно, предыдущий restore/backup ещё выполняется или воркер умер, и lock не освободился”.
    - если последний `skipped` с `reason=lock_unavailable` — показать это явно (прочитать meta).
- **Header actions**
  - “Run backup now”:
    - диспатчить `RunBackupJob::dispatch([...], 'manual', null, true)` (по аналогии с командой).
    - UI: показать Notification “Backup dispatched”
  - (опционально) “Run backup sync” — только для dev, можно скрыть.

UX-детали:

- Ошибки repo показывать коротко + совет “проверьте Settings / выполните init вручную”.
- Не спамить огромными stderr: всё обрезать.

------

### Шаг 3. Переиспользуемые утилиты форматирования

Добавить в сервис/хелпер:

- `formatBytes(int $bytes): string`
- `formatDuration(?Carbon $started, ?Carbon $finished): string`
- `formatRunStatusBadge($status): ...`

------

### Шаг 4. Минимальные проверки/тесты (по возможности)

Если тесты уже есть — добавить:

- Unit: Overview service возвращает `configured=false`, если нет settings.
- Unit: если ResticRunner кидает исключение “not a repository” → status `uninitialized`.
- Feature/UI тесты можно не делать, если это тяжело — но хотя бы сервис.

------

## Acceptance criteria (что считается “готово”)

1. Страница **Backups → Overview** больше не содержит `TODO` и показывает 3 блока: Repo / Runs / System.
2. При недоступном или неинициализированном repo — админ видит понятный статус и подсказку, без падения страницы.
3. Кнопка “Run backup now” диспатчит backup в очередь и показывает уведомление (без ошибок).
4. Restic команды не выполняются на каждый рефреш без ограничений — есть кэш 30–60 сек.
5. Никакие секреты не попадают в UI.

------

## Notes для реализатора

- `BackupsDashboard` уже в навигации как `slug=backups`, label `Overview` — сохраняем.
- Не трогать PROMPT 9 (DB-only restore оптимизация) — это следующий этап.
- Lock-UX фикс (unlock button, stale-lock детектор, и т.п.) — **не делаем сейчас**, но Overview должен хотя бы **показывать симптом** (последний skipped + likely_locked).

------

Если хочешь — следующим сообщением я сделаю “скелет PR” (какие новые файлы/классы добавить и как аккуратно связать их с Filament Schema), но текущий PROMPT 8 уже самодостаточный для AI-реализатора.

------

## PROMPT 9 — DB-only restore без восстановления всего дерева (restic dump)

### Цель

Сейчас при restore из snapshot даже в режиме “только БД” пакет, скорее всего, делает `restic restore` всего snapshot в staging-директорию, а потом берёт оттуда файл дампа и импортирует. Это медленно и требует много места.

Нужно оптимизировать: **если пользователь выбирает restore scope = `db`**, пакет должен:

- **не восстанавливать файлы проекта** в staging,
- **скачать только файл дампа БД** из snapshot,
- выполнить безопасное восстановление БД (safety dump → wipe → import → post-ops),
- не трогать `.env` и файлы проекта.

### Основа restic (для реализации)

- `restic dump [flags] snapshotID file` умеет извлечь файл из snapshot и записать его в `--target`. ([man.archlinux.org](https://man.archlinux.org/man/restic-dump.1.en))
- `restic restore <snapshot> --target ... --include <path>` умеет восстановить только подмножество файлов (например, один файл). ([restic.readthedocs.io](https://restic.readthedocs.io/en/latest/050_restore.html?utm_source=chatgpt.com))

------

## Контекст в проекте

- Дамп БД создаётся в проекте как: `storage/app/_backup/db.sql.gz` (внутри project root).
- Snapshot restic содержит project root с этим файлом (как часть backup).
- Restore v2 сейчас staged + atomic swap + preflight. Для `db`-restore swap файлов не нужен.

------

## Требования/ограничения

1. Новая оптимизация применяется **только** для `scope = db` (DB-only).
2. Для `scope = files` и `scope = both` поведение остаётся прежним (staging → swap и т.п.).
3. Не раскрывать секреты (repo password, S3 keys, DB пароль) в логах/meta.
4. Если “DB-only через dump” невозможен (не нашли файл дампа в snapshot) — сделать **прозрачный fallback** на текущий способ (staging restore), либо отказ с понятным сообщением (лучше fallback).

------

## Изменения в коде — пошагово

### Шаг 1. Добавить поддержку `restic dump` в `ResticRunner`

Добавить метод (или универсальный `run(['dump', ...])` если уже так делается), например:

- `public function dump(string $snapshotId, string $filePath, string $targetPath, array $opts = []): ProcessResult`

Команда:

- `restic dump <snapshotId> <filePath> --target <targetPath>` ([man.archlinux.org](https://man.archlinux.org/man/restic-dump.1.en))
  (Плюс стандартные env из настроек: `RESTIC_REPOSITORY`, `RESTIC_PASSWORD`, S3 creds.)

Логирование:

- В meta писать “dump snapshotId + filePath + target basename”, без секретов.

### Шаг 2. Определить “путь дампа внутри snapshot” (важно для совместимости)

Нужно вычислять `dumpPathInSnapshot` для указанного snapshot.

**Основной кандидат (как сейчас ожидается):**

- `{$projectRoot}/storage/app/_backup/db.sql.gz`
  Где `$projectRoot` — то же, что используется для backup.

**Проблема:** в restic пути часто абсолютные (зависят от того, как делался backup). Поэтому нужен fallback.

Сделать функцию:

- `resolveDumpPathInSnapshot(string $snapshotId): ResolvedPathResult`

Алгоритм:

1. Попробовать “абсолютный” путь из настроек:
   - `$candidate = rtrim($settings->project_root, '/').'/storage/app/_backup/db.sql.gz'`
2. Проверить существование файла в snapshot *лёгкой командой*, прежде чем dump:
   - Вариант A (предпочтительно): `restic ls <snapshotId> --long <candidate>` (или `--json`) и убедиться что узел найден.
   - Вариант B (если ls сложно парсить): сразу сделать `dump` в temp и если restic вернул “not found” — переходить к fallback.
3. Fallback-поиск (best effort):
   - Если основной путь не найден: попытаться восстановить через `restic restore --include` (см. Шаг 4) или поискать файл иначе.
   - (Опционально) Можно добавить “heuristic”: искать по окончанию пути `storage/app/_backup/db.sql.gz` (но не делайте `ls /` рекурсивно по всему snapshot — это может быть огромно).

**Acceptance:** даже если exact path не найден, система **не должна ломаться**: либо fallback на staging, либо понятное сообщение “dump file not found in snapshot”.

### Шаг 3. Новый DB-only restore flow в `RunRestoreJob`

В `RunRestoreJob` (или отдельном методе) сделать разветвление:

- если `scope === 'db'`:
  - **не выполнять stage restore файлов**
  - **не выполнять atomic swap директорий**
  - выполнить следующий pipeline:

#### Pipeline DB-only (предлагаемый)

1. Acquire global lock (как сейчас).
2. Preflight:
   - Проверить доступность repo (как минимум выполнить `restic snapshots`/`ls`/`dump`).
   - Проверить свободное место:
     - Для DB-only достаточно `disk_free_space()` на temp-директории (см. ниже), сравнить хотя бы с “ожидаемым размером дампа” (если известно) + запас.
3. Создать **safety DB dump** текущей базы (как уже делаете перед cutover).
4. Maintenance mode:
   - Рекомендуется `artisan down --secret=...` (с bypass-secret как у вас), чтобы не держать сайт на “старых данных” во время импорта.
   - (Если очень не хочется — можно сделать optional, но лучше как у full restore.)
5. Скачать dump из snapshot:
   - Выбрать `tmpDumpPath`, например: `storage_path('app/_backup/restore/db.sql.gz')` **в живом окружении** (не в staging).
   - `ResticRunner->dump($snapshotId, $dumpPathInSnapshot, $tmpDumpPath)`.
6. Wipe database:
   - Использовать вашу “безопасную wipe” (которая не трогает таблицы пакета) — вы это уже отдельно фиксите/зафиксируете.
7. Import dump:
   - Импортировать из `$tmpDumpPath` (gzip → mysql).
8. Post-ops:
   - `optimize:clear`
   - `queue:restart` (на проде нормально; в dev можно флагом отключать)
   - `artisan up`
9. Cleanup:
   - удалить `$tmpDumpPath` (best effort)
10. Meta:

- `meta.restore.scope = 'db'`
- `meta.restore.db_restore_method = 'dump'|'restore_include'|'staging_fallback'`
- `meta.restore.db_dump_target_path = ...` (локальный temp путь, можно обрезать)
- шаги: `dump_db_from_snapshot`, `db_wipe`, `db_import`, `post_ops`

### Шаг 4. Fallback: `restic restore --include` (если dump не подходит)

Если `restic dump` по каким-то причинам неудобен/ограничен, либо если нужно восстановить дамп в директорию с сохранением пути, можно использовать:

- `restic restore <snapshotId> --target <tempDir> --include <dumpPath>` ([restic.readthedocs.io](https://restic.readthedocs.io/en/latest/050_restore.html?utm_source=chatgpt.com))

Но учтите: restic restore сохранит структуру путей внутри target (может получиться `<tempDir>/<abs path>/storage/app/_backup/db.sql.gz>`). Тогда нужна функция `resolveDumpFileOnDiskFromRestoreTarget()`.

**Рекомендуемая стратегия:**

- Primary: `dump`
- Secondary: `restore --include`
- Tertiary: старый staging-flow (если вообще не нашли dump path)

### Шаг 5. Filament UI (BackupsSnapshots restore wizard)

Если в wizard уже есть “scope”, убедиться, что:

- `db` scope реально маршрутизируется в новую ветку (DB-only flow).
- В описании/подсказке рядом с выбором:
  - “DB-only: извлекается только дамп БД из snapshot, файлы проекта не меняются”.

Если scope пока “внутренний” — добавить его в UI.

------

## Логирование / мета / безопасность

- В meta обязательно писать:
  - `restore_scope`
  - `db_restore_method`
  - `snapshot_id`
  - exit_code/duration stdout/stderr (truncated)
- Никогда не писать:
  - S3 access key/secret
  - RESTIC_PASSWORD
  - DB password
- Если dump не найден — писать это как понятную причину (`dump_not_found_in_snapshot`) + какой fallback применён.

------

## Acceptance criteria

1. При restore scope=`db`:
   - **не создаётся staging dir всего проекта**
   - **не выполняется atomic swap**
   - скачивается/восстанавливается **только** файл `db.sql.gz` и делается import
2. При этом:
   - .env и файловая структура проекта не меняются
   - restore run корректно логируется в `backup_runs` (status success/failed)
3. При невозможности найти дамп в snapshot:
   - либо корректный fallback (restore --include или старый staging),
   - либо понятная ошибка с инструкцией (без “сломанных” состояний).

------

## Manual test plan

1. **DB-only restore success**
   - Сделать backup (чтобы snapshot гарантированно содержал `storage/app/_backup/db.sql.gz`)
   - Изменить данные в БД
   - Запустить restore scope=db
   - Убедиться: данные вернулись, файлы не тронуты, staging swap не было, run=success.
2. **Dump path mismatch**
   - Смоделировать ситуацию, когда project_root изменился (или дамп лежит иначе)
   - Запустить restore scope=db
   - Убедиться: либо сработал fallback, либо понятное сообщение.
3. **Error during import**
   - Сломать импорт (временно неверные DB креды)
   - Убедиться: run=failed, maintenance best-effort снимается, safety dump остаётся доступным (как сейчас у вас задумано).

------



------

## PROMPT 10 — Simplified Settings UX: auto-prefix `restic/<app-slug>/<env>`, bucket selector, repo init, readonly project root

### Цель

Упростить страницу **Backups → Settings**: убрать лишние/опасные поля (Prefix, Repository), минимизировать шанс ошибиться и добавить “правильный” onboarding:

1. Endpoint вводится пользователем (с `https://` или без — нормализуем).
2. Пользователь вводит Access key + Secret key.
3. Bucket выбирается из списка бакетов (кнопка “Refresh”), с fallback на ручной ввод, если ListBuckets недоступен.
4. Repository path формируется автоматически:
   - `restic/<app-slug>/<env>` (read-only)
   - итоговый repository URI хранится в настройках (внутренне), но не вводится руками.
5. Добавить кнопку **Init repository** в Settings, которая выполнит `restic init` для рассчитанного repo.
6. Paths:
   - Project root всегда `base_path()` (read-only)
   - Include/Exclude сделать понятными, добавить дефолтный пресет exclude и явные подсказки.

------

## Контекст текущей реализации

- Настройки хранятся в `backup_settings` (singleton).
- Сейчас в UI есть поля: Endpoint, Bucket, Prefix, Access key, Secret key, Repository, Repository password, Retention, Schedule, Paths.
- Ошибка “Unable to open config file… key does not exist” возникает, если repo не инициализирован — сейчас пользователь делает `restic init` вручную.

------

## Ограничения/принципы

- Не выводить секреты (ключи, пароль restic, db пароль) в UI/логах.
- Init — потенциально опасная операция → требуется подтверждение.
- Не ломать уже настроенные инсталляции: если данные уже сохранены, UI должен корректно отображать и продолжать работать.

------

# Часть A — Data model / вычисления

### A1) Добавить/зафиксировать вычисляемый prefix (не редактируемый)

Создать функцию (например в `BackupSetting` или в отдельном helper/service):

```
computeRepositoryPrefix(): string
```

Алгоритм:

1. `appSlug`:
   - `Str::slug(config('app.name'))`
   - если пусто → `Str::slug(basename(base_path()))`
   - если пусто → `project-<first8(sha1(base_path()))>`
2. `env = config('app.env')` (fallback `production`)
3. prefix = `"restic/{$appSlug}/{$env}"`

**Стабильность:**

- Если в `backup_settings` уже сохранён `repository_prefix` (или аналог) — использовать его, не пересчитывать.
- Автогенерация применяется только при первом сохранении/если поле пустое.

> Если сейчас в модели такого поля нет — добавь `repository_prefix` (string, nullable) + миграцию.
> Альтернатива: хранить только repository URI, но prefix отдельно удобнее для UI.

### A2) Убрать вводимые пользователем поля Prefix и Repository

- Prefix больше не хранится как пользовательский input.
- Repository строку (`s3:https://.../bucket/...`) пользователь не редактирует.
- Repo URI строится из: normalized endpoint + bucket + computed prefix.

### A3) Нормализовать Endpoint

Создать функцию:
`normalizeEndpoint(string $value): string`

Правила:

- trim
- если нет схемы → добавить `https://`
- убрать trailing `/`

------

# Часть B — Bucket select + refresh (S3 listBuckets)

### B1) Реализовать “List buckets” через S3 API (а не через restic)

Добавить сервис, например `S3ClientFactory` + `S3BucketsService`, который используя:

- endpoint
- access key
- secret key
  получает список бакетов.

Требование: работать с S3-compatible (Selectel).

### B2) UI поведение

- Поле Bucket по умолчанию — **Select** (пустой, без опций).
- Рядом кнопка **Refresh**:
  - вызывает backend action: `loadBuckets(endpoint, accessKey, secretKey)`
  - возвращает список бакетов → заполняет options.
- Если получаем ошибку “AccessDenied” / “ListBuckets not allowed”:
  - показываем предупреждение
  - переключаем поле Bucket в режим **TextInput** (fallback) или показываем рядом второй input “Enter bucket manually”.

> Важно: не делать авто-запрос при каждом вводе символов ключа.

------

# Часть C — Repository status + Init button

### C1) Добавить в Settings блок “Repository status”

При отображении страницы:

- если нет endpoint/keys/bucket/password → статус “Not configured”
- иначе попытка проверки репозитория:
  - выполнить лёгкую команду restic, например `restic snapshots --json` (через `ResticRunner`)
  - если ошибка “config file … key does not exist / not a repository” → статус `Not initialized`
  - если success → `OK` (можно показать snapshots count)
  - иначе → `Error` + короткое сообщение

Результат кэшировать на 15–30 сек, чтобы не долбить restic на каждый render.

### C2) Кнопка Init repository

Добавить кнопку (action) в Settings:

- активна только если статус `Not initialized` и все поля заполнены
- при нажатии:
  - спрашиваем подтверждение (например, пользователь вводит `INIT` или `init` в модалке)
  - выполняем `ResticRunner->init()`:
    - `restic -r <repo> init`
  - после успеха:
    - уведомление success
    - обновить repo status (refresh)

Если repo уже initialized и init вернул “already initialized” — трактуем как success.

------

# Часть D — Paths UX

### D1) Project root

- Убрать input.
- Всегда показывать read-only `base_path()`.

### D2) Include paths

- Оставить editable (повторяющийся список строк).
- Подсказка:
  - “Если пусто — бэкапится весь проект (Project root), кроме Exclude paths.”
  - “Если заполнено — бэкапятся только перечисленные пути (относительно Project root).”
- Валидация:
  - запрещать абсолютные пути (начинающиеся с `/`), либо показывать warning и автоматически приводить к относительному.

### D3) Exclude paths

- Editable list + кнопка “Restore defaults”.
- Дефолтный пресет (минимальный безопасный):
  - `vendor`
  - `node_modules`
  - `.git`
  - `storage/framework`
  - `storage/logs`
  - `bootstrap/cache`
  - `public/build`
  - `public/hot`
  - `storage/app/_backup` (обсудить: обычно дамп БД должен включаться, так что **НЕ исключать**; исключать нельзя, иначе backup потеряет db.sql.gz)

Отдельно явная подсказка:

- “Не исключайте `storage/app/_backup`, иначе в snapshot не будет дампа БД.”

------

# Часть E — Миграции и обратная совместимость

### E1) Если добавляем `repository_prefix`

- миграция: добавить nullable `repository_prefix` в `backup_settings`.
- при загрузке старых настроек:
  - если у пользователя уже был prefix/repository:
    - сохранить его в `repository_prefix` один раз (migration или runtime upgrade)
    - не менять существующий путь, чтобы не “потерять” старый репозиторий.

### E2) Repository URI

- Можно продолжать хранить `repository` как string в settings (как сейчас), но:
  - формировать автоматически и делать read-only в UI.
- Либо хранить endpoint+bucket+repository_prefix отдельно, а repository вычислять на лету для ResticRunner.

------

# Acceptance criteria

1. На Settings больше нет полей:
   - Prefix (input)
   - Repository (input)
2. Bucket выбирается из Select после “Refresh”, но если listBuckets недоступен — есть ручной ввод.
3. На странице виден read-only “Repository path”:
   - `restic/<app-slug>/<env>`
     и (опционально) read-only repo uri.
4. Есть статус repo и кнопка Init:
   - после init пропадает ошибка “unable to open config file…”
   - Snapshots page начинает работать.
5. Project root read-only и равен `base_path()`.
6. Include/Exclude с понятными подсказками и дефолтами.

------

# Manual test plan

1. **Fresh install**:
   - ввести endpoint+keys → refresh buckets → выбрать bucket → задать password → init → snapshots открываются.
2. **No ListBuckets permission**:
   - refresh buckets → видим warning → вводим bucket вручную → init работает.
3. **Existing install**:
   - обновить пакет → existing repo продолжает открываться, не “перескакивает” на новый auto-prefix.
4. **Paths defaults**:
   - убедиться, что `storage/app/_backup/db.sql.gz` попадает в backup.

------

## PROMPT 11 — Delete snapshot(s): точечное удаление снапшотов из Filament (restic forget + prune) + логирование в backup_runs

### Цель

Добавить в страницу **Backups → Snapshots** возможность **точечно удалить любой snapshot** (один) из репозитория restic через UI.

Удаление должно быть безопасным:

- с подтверждением,
- с global lock (чтобы не пересекаться с backup/restore),
- с логированием в `backup_runs`,
- без утечки секретов,
- с корректным обновлением таблицы снапшотов после операции.

------

# 1) UX/Поведение

### 1.1 Row action “Delete”

В таблице Snapshots добавить **Row Action**:

- label: `Delete`
- icon: trash
- color: danger

### 1.2 Подтверждение (обязательно)

Показать modal:

- Заголовок: `Delete snapshot`
- Текст: “Это удалит snapshot из репозитория. Освобождение места потребует prune и может занять время.”
- Поле подтверждения: пользователь должен ввести **первые 8 символов** snapshot id (или полностью).
  - expected: `$snapshotIdShort = substr($id, 0, 8)`
  - input: `confirmation`
  - validation: must equal `$snapshotIdShort`
- Кнопки: `Cancel` / `Delete`

### 1.3 Блокировка UI

Во время выполнения:

- показывать loading state у кнопки
- после завершения — Notification success/error
- после success — обновить таблицу snapshots (refresh).

------

# 2) Команда restic и стратегия удаления

### 2.1 Основная команда

Использовать:

- `restic forget <SNAPSHOT_ID> --prune`

Обоснование:

- `forget` удаляет ссылку на снапшот,
- `--prune` освобождает место (иначе пользователь думает “не удалилось”).

### 2.2 Время выполнения

`--prune` может идти долго. Нужно:

- выполнять в фоне через Job (рекомендуется),
- либо синхронно только если проект прямо хочет “быстро и просто” (но лучше job).

------

# 3) Архитектура: Job + логирование в backup_runs

### 3.1 Создать Job

Создать `ForgetSnapshotJob` (или `DeleteSnapshotJob`) в пакете:

- входные параметры:
  - `string $snapshotId`
  - `?int $userId` (опционально, если есть auth)
  - `string $trigger = 'filament'`

### 3.2 Запись в backup_runs

При запуске Job создать `BackupRun`:

- `type = 'forget_snapshot'` (или `'maintenance'`)
- `status = 'running'`
- `meta`:
  - `snapshot_id`
  - `trigger`
  - `initiator_user_id` (если есть)
  - `steps` (см. ниже)

После выполнения:

- `status = success|failed|skipped`
- `finished_at`, `duration_ms`
- `meta.steps.forget_prune`: stdout/stderr truncated, exitCode, duration.

### 3.3 Global lock

Использовать тот же lock-key, что backup/restore (одна операция за раз):

- если lock не получен → run.status = `skipped`, `meta.reason=lock_unavailable`
- Notification в UI: “Another backup/restore operation is running.”

------

# 4) ResticRunner: добавить метод forgetSnapshot

### 4.1 Метод

В `ResticRunner` добавить:

- `public function forgetSnapshot(string $snapshotId, bool $prune = true): ProcessResult`

Собирает команду:

- `restic forget <snapshotId> --prune` (если $prune)

Логирование:

- команда в meta без секретов.

------

# 5) Filament: интеграция в BackupsSnapshots

### 5.1 Row action

В таблице snapshots добавить action:

- вызывает dispatch `ForgetSnapshotJob::dispatch($id, auth()->id() ?? null)`
- показывает Notification “Delete scheduled” (если async)
- или “Deleted” (если sync)

### 5.2 Обновление таблицы

После завершения job таблица обновится при следующем refresh.
Если хочешь мгновенно — можно:

- показать кнопку Refresh (уже есть),
- либо auto-refresh через polling (не обязательно).

------

# 6) Edge cases / Ошибки

1. Repo not initialized / unreachable:
   - action disabled
   - показать tooltip “Repository is not available”
2. Snapshot not found:
   - restic вернёт ошибку → status failed + stderr в meta
3. `forget` success, `prune` failed:
   - считать overall как failed или “partial success”:
     - предпочтение: `failed` + meta `{ forget_ok: true, prune_ok: false }`
4. Нельзя удалять snapshot, который прямо сейчас используется restore/backup:
   - lock это уже решает
5. Секреты:
   - убедиться, что stdout/stderr не содержат ключей/паролей (в идеале они не должны, но всё равно truncation как в других шагах).

------

# 7) Acceptance criteria

1. На странице Snapshots есть row action Delete с подтверждением по short-id.
2. После удаления snapshot исчезает из списка (после refresh).
3. Операция пишет запись в `backup_runs` с типом `forget_snapshot` и шагами/результатом.
4. Операция защищена global lock (при занятости — skipped + понятное уведомление).
5. Никаких секретов не появляется в meta/logs.

------

# 8) Manual test plan

1. Создать 2–3 снапшота (backup несколько раз).
2. Удалить средний snapshot через UI:
   - подтвердить short-id
   - дождаться выполнения job
   - refresh → snapshot пропал
   - в Runs появился run type forget_snapshot со статусом success
3. Запустить backup и параллельно попытаться Delete:
   - должен быть skipped/lock_unavailable
4. Попробовать удалить несуществующий snapshot (подменить ID):
   - run failed, понятная ошибка в meta.

------

## PROMPT 12 — Lock UX overhaul: wait/queue, lock owner info, heartbeat, stale unlock + artisan command

### Проблема

Сейчас backup/restore используют global lock `restic-backups:operation`. Если воркер умирает/перезапускается (например, после `queue:restart`), lock остаётся жить до TTL → любые новые попытки дают `backup_runs.status=skipped` с `meta.reason=lock_unavailable`. Это портит UX и мешает работе (особенно в тестовом окружении).

### Цель

Сделать “человеческое” поведение:

1. **Не создавать `skipped` run** при занятости lock:
   - вместо этого **ждать** (block) небольшое время,
   - если не дождались — **ставить в очередь** (requeue с delay/backoff), а не мусорить `skipped`.
2. Хранить и показывать **информацию о владельце lock** (кто и что сейчас делает).
3. Делать **heartbeat** во время долгих операций (restic restore/backup/prune), чтобы можно было отличить “живой процесс” от “залипшего”.
4. Реализовать **stale detection** и команду `restic-backups:unlock` для принудительной разблокировки (с подтверждением).
5. Улучшить UX в Filament: когда операция занята — показывать понятное сообщение и сведения о текущей операции (run id/type/started).

------

# 1) Архитектура: единый LockService

Создать класс, например:

- `src/Support/OperationLock.php` (или `src/Services/OperationLockService.php`)

### 1.1 Константы/ключи

- lock key: `restic-backups:operation`
- info key: `restic-backups:operation:info`

### 1.2 Методы

**(A)** `public function acquire(string $type, int $ttlSeconds, int $blockSeconds = 30, array $context = []): ?LockHandle`

- Пытается взять lock с ожиданием:
  - `$lock = Cache::lock($key, $ttlSeconds)`
  - `$lock->block($blockSeconds)` (или аналог)
- Если удалось:
  - создаёт/обновляет `operation:info` JSON:
    - `type` = `backup|restore|forget_snapshot|check|...`
    - `run_id` (если уже известен)
    - `started_at` (ISO8601)
    - `hostname` (`gethostname()`/`php_uname('n')`)
    - `pid` (`getmypid()`)
    - `ttl_seconds`
    - `expires_at` (now + ttl)
    - `last_heartbeat_at` (now)
    - `context` (например snapshot_id, trigger)
  - возвращает handle, который умеет `heartbeat()` и `release()`

**(B)** `public function heartbeat(array $patch = []): void`

- Обновляет `last_heartbeat_at` + опционально дописывает поля (например текущий шаг).

**(C)** `public function release(): void`

- освобождает lock (если есть)
- `Cache::forget(infoKey)` (best effort)

**(D)** `public function getInfo(): ?array`

- читает `operation:info`

**(E)** `public function isStale(int $seconds = 900): bool`

- stale если `last_heartbeat_at` старее 15 минут (или configurable)

> Важно: сервис должен работать на **том же cache store**, что и queue worker.

------

# 2) Изменить Jobs: ожидание + requeue вместо skipped

## 2.1 Общая стратегия

- **Не создавать `BackupRun` до успешного acquire lock**, либо создавать со статусом `queued` (но не `skipped`).
- Если lock занят:
  - подождать `blockSeconds` (например 30–60 сек),
  - если не получилось — сделать `$this->release($delay)` (ре-очередь) с backoff и **без** записи `backup_runs`.

### 2.2 Рекомендованные параметры

- Backup:
  - ttl: 2 часа (как сейчас)
  - blockSeconds: 30
  - requeue delay: 60, 120, 300 (backoff)
- Restore:
  - ttl: 6 часов (как сейчас)
  - blockSeconds: 60
  - requeue delay: 120, 300, 600 (backoff)

### 2.3 Реализация в `RunBackupJob` и `RunRestoreJob`

В `handle()`:

1. попробовать acquire lock через OperationLock:
   - если не удалось:
     - если job queued → `$this->release($delaySeconds); return;`
     - если job sync/manual CLI → вернуть понятный результат/исключение, чтобы UI показал “занято”
2. после acquire:
   - создать `BackupRun` со статусом `running`
   - записать `run_id` в `operation:info`
3. во время шагов:
   - перед каждым шагом обновлять `operation:info.context.step = '...'` + `heartbeat()`
4. в `finally`:
   - `operationLock->release()`

> Acceptance: больше не должно появляться `backup_runs.status=skipped` с `lock_unavailable` при нормальной конкуренции. Только реальные ошибки.

------

# 3) Heartbeat внутри ResticRunner (критично для длительных процессов)

Проблема: stage restore / prune могут идти 10–20 минут. Если heartbeat обновлять только “до/после шага”, stale-детектор будет думать что процесс умер.

### Решение

Обновить `ResticRunner` так, чтобы во время выполнения long-running restic команд:

- он мог принимать callback `heartbeat` и вызывать его раз в N секунд (например каждые 15–30 сек) пока процесс идёт.

Пример API:

- `public function run(array $args, ?callable $heartbeat = null, int $heartbeatEverySeconds = 20): ProcessResult`

Внутри:

- запуск Symfony Process асинхронно (`start()`)
- цикл while running:
  - если прошло heartbeatEverySeconds → вызвать `$heartbeat(['step' => ..., 'command' => ...])`
  - спать коротко (0.2–0.5s)
- затем собрать stdout/stderr/duration/exitCode как сейчас.

> Так heartbeat будет работать на `restic backup`, `restore`, `forget --prune`, `check`.

------

# 4) Инфо о владельце lock в Filament UI

## 4.1 Где показывать

Минимум:

- на странице `BackupsSnapshots` (там где restore wizard и refresh snapshots)
- и/или на `BackupsDashboard` (Overview) — если он уже есть/будет.

Показывать баннер/alert:

- “Операция выполняется: {type}, старт {started_at}, host {hostname}”
- если есть `run_id` → ссылка “Open run details” на `BackupsRuns` (фильтр по id или прямой view).

## 4.2 Поведение кнопок

- При клике “Run backup now” или “Restore”:
  - если lock занят:
    - показывать Notification: “Сейчас выполняется другая операция. Задача поставлена в очередь.” (если мы requeue)
    - либо “Подождите, операция выполняется: …” (если синхронный запуск без очереди)

------

# 5) Команда `restic-backups:unlock`

Создать artisan command:

- `restic-backups:unlock`

Поведение:

1. читает `operation:info`
2. если пусто:
   - вывод “No active lock info found.” + попытка `forceRelease()` всё равно (best effort)
3. если есть:
   - вывести кратко: type/run_id/started/last_heartbeat/hostname/pid/expires_at
   - спросить подтверждение:
     - ввести `UNLOCK` или `yes`
   - выполнить:
     - `Cache::lock(lockKey)->forceRelease()`
     - `Cache::forget(infoKey)`
4. опции:
   - `--force` (без подтверждения)
   - `--stale` (разблокировать только если stale)
   - `--stale-seconds=900` (кастом)

> Это must-have для админов и для dev.

------

# 6) Stale lock strategy (безопасно)

По умолчанию:

- **не авто-forceRelease** в рабочих сценариях.
- stale detection используется для:
  - отображения предупреждения “Lock looks stale (heartbeat > 15 min)”
  - облегчения решения админа (unlock command / будущая кнопка Force unlock).

(Авто-forceRelease можно добавить позже под флагом `config('restic-backups.locks.auto_release_stale')`.)

------

# 7) Acceptance criteria

1. При параллельных попытках backup/restore:
   - **не создаются** `backup_runs` со `status=skipped lock_unavailable`
   - вместо этого job ожидает lock и/или уходит в requeue.
2. В `Cache` появляется `restic-backups:operation:info` во время операции, и очищается после.
3. Во время длительных restic операций обновляется `last_heartbeat_at` (каждые 20–30 сек).
4. `php artisan restic-backups:unlock` снимает lock и чистит info, с подтверждением.
5. Filament показывает понятное сообщение о текущей операции (type/started/host/run_id).

------

# 8) Manual test plan

1. Запустить restore (долгий) → убедиться, что:
   - lock info заполняется
   - heartbeat обновляется
2. Во время restore нажать “Run backup now”:
   - не появляется skipped run
   - видим уведомление “в очереди/ожидании”
3. Убить воркер посреди операции:
   - lock остаётся, но heartbeat перестаёт обновляться
   - UI показывает “stale”
   - `restic-backups:unlock --stale --force` снимает lock
4. После unlock новая операция стартует.

------

Если хочешь — могу сразу предложить “минимальный релизный срез” (MVP): **(1) info + unlock command + heartbeat в ResticRunner + убрать skipped**. А “очередь” (requeue/backoff) можно сделать в этом же PR, но её проще тестировать на реальной очереди.



## PROMPT — Fix Restore DB: не терять `backup_runs`, не оставлять пустую БД, не тащить “пакетные” таблицы в дампы

Ты — senior Laravel 12 / PHP 8.4 / Filament 4 инженер. В пакете `siteko/filament-restic-backups` уже реализован restore v2 (staged + atomic swap + preflight + Step7 polish). На текущем этапе обнаружен **критический баг**: при restore БД джоб может **стереть таблицы пакета (`backup_runs`, `backup_settings`)** через `db:wipe`/`dropAllTables()`, после чего падает на `run->update()`. Итог — **БД остаётся пустой**, а **meta последнего restore-run недоступна** (таблица `backup_runs` уже уничтожена).

Нужно исправить восстановление базы данных так, чтобы:

1. пакетные таблицы **никогда не удалялись** во время restore БД,
2. дампы **не содержали** пакетные таблицы (иначе они могут “перезаписаться” из снапшота),
3. при фейле импорта после wipe выполнялся **best-effort DB rollback из safety dump**, чтобы не оставлять проект на пустой БД.

------

# 0) Контекст (как сейчас)

Файлы:

- `packages/siteko/restic-backups/src/Jobs/RunRestoreJob.php`
  - `wipeDatabase()` вызывает `artisan db:wipe --force --database=...`, fallback: `dropAllTables()`
  - сразу после wipe выполняется `$run->update(['meta' => $meta])`
- `packages/siteko/restic-backups/src/Jobs/RunBackupJob.php`
  - генерирует дамп `storage/app/_backup/db.sql.gz`
- `RunRestoreJob::runSafetyBackup()` тоже делает safety DB dump перед cutover.

Проблема: `db:wipe` и `dropAllTables()` удаляют **все** таблицы, включая `backup_runs`/`backup_settings`. Затем любой `BackupRun::update()` падает (таблица не существует), restore прерывается, импорт дампа не выполняется → **пустая БД**.

------

# 1) Цели и требования

## 1.1. Главное требование

Во время restore БД **нельзя** удалять:

- `backup_runs`
- `backup_settings`

Идеально: сделать это расширяемым (конфигом), но минимум — эти две таблицы.

## 1.2. Дамп БД не должен включать таблицы пакета

Чтобы restore не мог “принести” старые `backup_runs/backup_settings` из снапшота, нужно исключить их из дампа:

- для MySQL/MariaDB: `--ignore-table=<db>.backup_runs` и `--ignore-table=<db>.backup_settings`

Это нужно применить минимум в:

- `RunBackupJob::dumpMysql()`
- `RunRestoreJob::dumpMysql()` (safety dump)

## 1.3. Best-effort DB rollback

Если DB wipe уже сделан, а импорт дампа упал, job должен:

- попытаться импортировать **safety dump** обратно
- зафиксировать это в `backup_runs.meta.steps.rollback_db_restore` (и флаги attempted/success)
- после этого продолжить общий rollback (если применимо) и поднять приложение (`up`) best-effort

Важно: rollback БД — best-effort, но он должен **выполняться автоматически**, чтобы не оставлять пустую БД.

------

# 2) Изменения в коде (пошагово)

## 2.1. Добавить единый список “внутренних” таблиц пакета

В `RunRestoreJob` (и желательно переиспользовать в `RunBackupJob`) добавить метод:

- `protected function internalTables(): array`
  - возвращает `['backup_runs', 'backup_settings']`

Опционально (желательно): разрешить расширение через конфиг пакета, например:

- `config('restic-backups.database.preserve_tables', ['backup_runs','backup_settings'])`

Но если конфиг трогать не хочешь — ок, захардкодь минимум.

## 2.2. Полностью убрать `artisan db:wipe` из restore пути (или запретить его по умолчанию)

Переписать `wipeDatabase()` в `RunRestoreJob`:

- Вместо `db:wipe` выполнять **удаление всех таблиц, кроме internalTables()**
- То есть новая логика:
  1. определить driver (`mysql/mariadb/pgsql/sqlite`)
  2. вызвать `dropAllTablesExcept($connectionName, $excludeTables)`
  3. вернуть meta с:
     - `exit_code`
     - `excluded_tables`
     - `dropped_tables_count`
     - `driver`
     - `duration_ms`

> Почему так: `db:wipe` не умеет exclude, и именно он приводит к исчезновению `backup_runs` и падению логирования.

### 2.2.1. Реализовать `dropAllTablesExcept()`

Добавить в `RunRestoreJob` новый метод:

- `protected function dropAllTablesExcept(string $connectionName, array $excludeTables): array`

Для MySQL/MariaDB:

- `SET FOREIGN_KEY_CHECKS=0`
- получить список таблиц:
  - `SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'`
- удалить таблицы, которые **не** в exclude
- (желательно) также удалить VIEW (если есть), но аккуратно:
  - `SHOW FULL TABLES WHERE Table_type = 'VIEW'`
  - `DROP VIEW IF EXISTS ...`
- `SET FOREIGN_KEY_CHECKS=1`

Для SQLite (best effort):

- `SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%'`
- drop all кроме exclude

Для Postgres (best effort):

- получить таблицы из `pg_tables` (schema = 'public') + views из `pg_views`
- `DROP TABLE ... CASCADE` / `DROP VIEW ... CASCADE` кроме exclude

Если драйвер не поддержан:

- вернуть exit_code=1 и понятный stderr (`Unsupported driver for safe wipe`)

## 2.3. Обновить fallback `dropAllTables()`

Сейчас есть `dropAllTables()` — он опасен. Либо:

- удалить его использование вообще,
- либо переписать его как thin-wrapper над `dropAllTablesExcept($connectionName, [])`.

Важно: в restore flow **не должно остаться** кода, который может дропнуть `backup_runs`.

## 2.4. Исключить пакетные таблицы из MySQL-dump (backup и safety)

В `RunBackupJob::dumpMysql()`:

- в `$baseCommand` или в `buildMysqlDumpCommand()` добавить флаги ignore-table **до** указания database:
  - `--ignore-table={$database}.backup_runs`
  - `--ignore-table={$database}.backup_settings`

В `RunRestoreJob::dumpMysql()` сделать то же самое (safety dump).

Требования к качеству:

- ignore-table добавлять только если `$database` определён
- не светить секреты (пароль как и раньше через `MYSQL_PWD`)

## 2.5. Best-effort DB rollback если импорт упал после wipe

В `RunRestoreJob::handle()`:

Добавить флаги:

- `$dbWiped = false;`
- `$safetyDumpPath = ...` (у тебя уже вычисляется после swap через `resolveSafetyDumpPath($rollbackDir)` — это хорошо, но нужно гарантировать, что путь доступен и при db-only restore тоже, если safety backup делался)

Логика:

1. После успешного `wipeDatabase()`:
   - поставить `$dbWiped = true`
2. Если на `cutover_db_import` выброшено исключение или exit_code != 0:
   - в `catch` (перед финальным `run->update(status=failed)`) выполнить:
     - если `$dbWiped === true` и есть `$safetyDumpPath` и файл существует → вызвать `importMysqlDump($connectionName, $safetyDumpPath, $projectRoot)` (или универсальный `importDatabaseDump()` для драйвера)
     - записать step meta: `meta['steps']['rollback_db_restore'] = ...`
     - добавить `meta['rollback']['db_attempted']`, `meta['rollback']['db_success']`
   - если safety dump нет — просто зафиксировать в meta, что rollback невозможен

Важно:

- rollback DB не должен “маскировать” исходную ошибку restore — status остаётся failed, но БД не пустая.

------

# 3) Логирование и безопасность

- Все новые шаги должны логироваться в `backup_runs.meta.steps.*` (как у тебя принято: exit_code, duration_ms, stdout/stderr truncated).
- Никаких секретов в meta: ни паролей, ни full env.
- Если DB rollback выполнялся — обязательно записать путь safety dump (если уже пишется `meta['restore']['safety_dump_path']`, оставить/расширить).

------

# 4) Acceptance criteria

Считаем задачу выполненной, если:

1. Запуск restore с scope `db` или `both` **никогда не удаляет**:
   - `backup_runs`
   - `backup_settings`
2. Даже если restore упал после wipe (например, сломали дамп или mysql client):
   - БД **не остаётся пустой** (если был safety dump — он восстанавливается best-effort)
   - В `backup_runs` остаётся запись о restore-run со статусом failed и meta шагами
3. `RunBackupJob` и safety dump в `RunRestoreJob` создают дампы **без** `backup_runs/backup_settings`.

------

# 5) Manual test plan (минимум)

## Тест A: Проверка, что `backup_runs` не стирается

1. Убедиться, что в БД есть записи `backup_runs`
2. Запустить restore (scope=both) на тестовом снапшоте
3. Во время `cutover_db_wipe` проверить: таблица `backup_runs` существует
4. По окончании: в Runs виден полный meta.

## Тест B: Симулировать фейл импорта

1. Перед restore временно “сломать” mysql client (например, подменить путь к binary или дать неверные права на дамп)
2. Запустить restore scope=db/both
3. Убедиться:
   - restore падает
   - `backup_runs` не пропадает
   - `rollback_db_restore` выполнен (если safety dump есть)
   - данные в БД не пустые

## Тест C: Проверка дампа

1. Сделать backup (обычный)
2. Распаковать `storage/app/_backup/db.sql.gz` и убедиться, что там нет `CREATE TABLE backup_runs` / `INSERT INTO backup_runs`.

------

# 6) Что выдать в результате

- Патч/изменения в:
  - `RunRestoreJob.php` (safe wipe + db rollback + meta)
  - `RunBackupJob.php` (ignore-table в mysqldump)
- Если добавляешь конфиг:
  - обновление `config/restic-backups.php` (секция `database.preserve_tables`, `database.exclude_from_dumps`)
- Короткое описание миграций/обратной совместимости (миграции не нужны)
- Commit message в стиле проекта (например: `fix(restore): preserve package tables during DB wipe and rollback on import failure`)

------

Если нужно — можешь дополнительно (не обязательно) сделать микро-улучшение: в meta писать `db_wipe_strategy = safe_drop_except` и `excluded_tables` (чтобы админ видел, почему “не всё удалили”).