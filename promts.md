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



## PROMPT (Шаг 8) — Notifications + Healthchecks + Scheduling (daily backup, weekly restic check)

Ты — senior Laravel 12 / PHP 8.4 / Filament 4 инженер. В пакете `siteko/filament-restic-backups` (в `packages/siteko/restic-backups`) уже реализованы шаги 1–7:

- Settings (`BackupSetting` singleton, encrypted casts для секретов)
- Runner (`ResticRunner`)
- Jobs: `RunBackupJob`, `RunRestoreJob` (+ возможно `RunCheckJob` если уже есть)
- Filament UI: Settings / Runs / Snapshots + Restore wizard (реальный)
- Runs логируются в таблицу `backup_runs` с `meta` (обрезка stdout/stderr, секреты не светятся)

Нужно реализовать **последний шаг**:

1. **Уведомления** (Telegram и Email) о success/fail для backup/restore/check
2. **Healthchecks ping** (start/success/fail)
3. **Расписание**: daily backup и weekly restic check
4. **Настройки** этих вещей в Filament Settings (в существующей странице Settings)

------

# 0) Общие правила (обязательные)

- Уведомления/пинги **никогда не должны ломать основной job**. Если Telegram/Email/Healthchecks недоступны — job всё равно считается success/fail по основной операции, а ошибки уведомлений записываются как warning в `meta`.
- Никаких секретов в логах/уведомлениях:
  - не логировать AWS keys, restic password
  - не логировать full repository URL, если есть риск кредов (в нашем случае обычно нет, но лучше не показывать)
- Всё внешнее выполнение — через уже имеющиеся механизмы:
  - Telegram/Healthchecks — через Laravel HTTP Client (`Http::...`) **или** Notifications, но без зависимостей, которые усложняют установку, если это не критично.
- Для Filament secret-полей — тот же безопасный UX, что у `access_key/secret_key/restic_password`:
  - не подставлять в форму текущее значение
  - пустое значение при save не затирает
  - новое значение сохраняется (encrypted cast)

------

# 1) Расширение схемы Settings (backup_settings)

## 1.1. Новые поля в БД

Добавь миграцию “add notifications/healthchecks to backup_settings”, НЕ меняй прошлые миграции задним числом.

Добавь в таблицу `backup_settings` следующие колонки:

### Notifications (минимально гибко)

- `notify_enabled` (boolean, default false)
- `notify_on_success` (boolean, default false)
- `notify_on_failure` (boolean, default true)
- `notify_channels` (json, nullable) — например `["mail","telegram"]`
- `notify_emails` (json, nullable) — массив email’ов

### Telegram

- `telegram_bot_token` (text, nullable) — **encrypted cast**
- `telegram_chat_ids` (json, nullable) — массив chat_id (строки/числа)

> Почему токен отдельным полем: чтобы шифровать легко и не играться с “частично зашифрованным json”.

### Healthchecks

- `healthchecks_enabled` (boolean, default false)
- `healthchecks_backup_ping_url` (string/text, nullable)
- `healthchecks_check_ping_url` (string/text, nullable)
- `healthchecks_restore_ping_url` (string/text, nullable)

Опционально (если хочешь поддержать start/fail отдельно):

- `healthchecks_support_start_fail` (boolean default true)
  и использовать `.../start`, `.../fail` по соглашению.
  Но чтобы было максимально надёжно: можно хранить **только базовый ping URL**, а `/start` и `/fail` формировать автоматически, при этом дать возможность отключить эту фичу.

### Scheduling

- Ничего нового в БД не обязательно, если уже есть `schedule` json.
- Но нужно расширить `schedule` схему:
  - `schedule.enabled` (уже есть)
  - `schedule.daily_time` (уже есть)
  - `schedule.timezone` (добавить, default = `config('app.timezone')`)
  - `schedule.weekly_check_day` (например `Sun`)
  - `schedule.weekly_check_time` (например `03:00`)

## 1.2. Модель `BackupSetting` casts/hiddens

- Добавить casts:
  - `notify_channels` => `array`
  - `notify_emails` => `array`
  - `telegram_bot_token` => `encrypted`
  - `telegram_chat_ids` => `array`
- Добавить в `$hidden`:
  - `telegram_bot_token` (обязательно)

------

# 2) Filament UI: Settings (добавить секции)

В существующей странице Backups Settings добавить секции:

## 2.1. Notifications

- Toggle: **Enable notifications**
- Checkbox/Toggles:
  - Notify on success
  - Notify on failure
- MultiSelect/CheckboxList Channels:
  - Email
  - Telegram

### Email recipients

- Repeater / TagsInput: список email адресов
- Валидация email

### Telegram

- `Bot token` — password input (не подставлять текущее значение; пустое = сохранить старое)
- `Chat IDs` — TagsInput / Repeater (строки/числа)
- Helper: “Create a bot via BotFather, use chat id from your chat/channel”

## 2.2. Healthchecks

- Toggle: Enable healthchecks ping
- Inputs:
  - Backup ping URL
  - Check ping URL
  - Restore ping URL
- Helper: “We will ping /start before, base URL on success, and /fail on failure (if enabled)”
- (Опционально) Toggle “Use /start and /fail endpoints” (если реализуешь)

## 2.3. Scheduling

- Расширить существующую секцию schedule:
  - enabled
  - daily_time
  - timezone
  - weekly_check_day (Select Mon–Sun)
  - weekly_check_time
- Helper: напомнить, что для работы расписания нужен системный cron: `php artisan schedule:run` каждую минуту, и очередь должна быть запущена.

## 2.4. Actions (очень полезно для проверки)

Добавь 2 кнопки на Settings:

1. **Send test notification**
   - отправляет тестовое сообщение в выбранные каналы (если enabled)
   - показывает Notification “sent/failed”
   - ошибки не валят страницу, показываются пользователю (без секретов)
2. **Ping healthchecks test**
   - пингует backup ping URL (success ping)
   - показывает результат (status code)

------

# 3) Уведомления: архитектура (не размазывать по job’ам)

Сделай аккуратную структуру:

## 3.1. Новый сервис `NotificationManager`

Файл: `src/Services/NotificationManager.php`

Методы:

- `notifyRunFinished(BackupRun $run): void`
  - решает, слать ли уведомление (enabled + success/fail flags + type filters)
  - отправляет в выбранные каналы
  - ошибки ловит и пишет в `$run->meta['notifications']['errors'][]`
- `sendTestNotification(): void` (или принимает настройки)

## 3.2. Формат сообщения (один стандарт)

Сделай один “summary builder”, чтобы Telegram и Email были согласованы:

В сообщении указывать:

- App name / env
- Run type: backup/restore/check
- Status: success/failed
- Started/finished + duration
- Snapshot (для restore)
- Краткая ошибка (если failed): step + error message (обрезать)
- Ссылка/подсказка “See Filament → Backups → Runs” (без реального URL, если не знаешь домен)

Не включать stdout целиком, только краткий stderr до 1000–2000 символов.

### Telegram отправка

Реализуй через Laravel Http Client к Bot API:

- POST `https://api.telegram.org/bot{token}/sendMessage`
- payload: `chat_id`, `text`, `disable_web_page_preview=true`
- отправлять во все `chat_ids`

Ошибки и rate limit не должны ломать job.

### Email отправка

Реализуй через Laravel Notifications или Mail:

- простой Mailable/Notification на адреса из `notify_emails`
- subject: `[Backups] <type> <status> <app/env>`
- body: текст + краткая сводка meta

------

# 4) Healthchecks ping: архитектура

## 4.1. Новый сервис `HealthchecksClient`

Файл: `src/Services/HealthchecksClient.php`

Методы:

- `pingStart(string $pingUrl, array $context = []): void`
- `pingSuccess(string $pingUrl, array $context = []): void`
- `pingFail(string $pingUrl, array $context = []): void`

Поведение:

- Если `healthchecks_enabled=false` или pingUrl пустой → ничего не делать.
- Если включено “/start /fail”:
  - start → `${pingUrl}/start`
  - fail → `${pingUrl}/fail`
  - success → `${pingUrl}`
- Если выключено — можно просто пинговать success и в fail тоже базовый url (или не делать fail ping). Решение за тобой, но сделай предсказуемо.

Таймаут HTTP: 5–10 секунд.
Ошибки ловить, писать в `$run->meta['healthchecks']['errors'][]`.

------

# 5) Встроить уведомления и healthchecks в job lifecycle

Важно: НЕ дублировать логику в каждом job вручную.

## 5.1. Общая “финализация” run

Сделай общий метод/трейт/сервис (например `RunFinalizer`), либо аккуратно добавь в каждый job одинаковый блок:

- В самом начале:
  - healthchecks start ping (если есть pingUrl для типа job)
- В конце:
  - healthchecks success или fail
  - notify success/fail (по настройкам)

Где брать pingUrl:

- backup → `healthchecks_backup_ping_url`
- check → `healthchecks_check_ping_url`
- restore → `healthchecks_restore_ping_url`

## 5.2. Какие jobs затрагиваем

- `RunBackupJob` — после выставления final status
- `RunRestoreJob` — после final status
- Добавить новый job `RunResticCheckJob` (см. ниже) — тоже интегрировать

------

# 6) Weekly restic check: новый job

Создай `src/Jobs/RunResticCheckJob.php`

Поведение:

1. lock (отдельный или общий “global” ключ, чтобы не пересекался с backup/restore)
2. создать `BackupRun`:
   - type = `check`
   - status = `running`
3. вызвать `ResticRunner->check()` (и при желании `snapshots()`/`stats` не нужно)
4. записать результат в meta
5. статус success/failed
6. healthchecks ping (start/success/fail)
7. уведомления (по правилам)
8. unlock в finally

------

# 7) Scheduling: daily backup + weekly check

## 7.1. Где регистрировать расписание

Сделай так, чтобы пакет мог сам подключаться к Laravel scheduler, **но управляемо**:

В `ResticBackupsServiceProvider::boot()` добавь регистрацию расписания через:

- `app()->booted(function () { $schedule = app(Schedule::class); ... })`

Или любой корректный способ для Laravel 12.

## 7.2. Что именно планировать

Если `BackupSetting::singleton()->schedule['enabled'] = true`:

### Daily backup

- каждый день в `daily_time` (из settings)
- timezone из settings (fallback `config('app.timezone')`)
- задача: `dispatch(new RunBackupJob(tags: ['trigger:schedule'], trigger:'schedule'))`
- важно: запускать через очередь, а не синхронно.

### Weekly restic check

- раз в неделю в выбранный день/время
- задача: `dispatch(new RunResticCheckJob(trigger:'schedule'))`

## 7.3. Важный edge case

Scheduler вызывается в CLI. Доступ к БД settings должен быть.
Если settings ещё не созданы — job не планировать (или планировать, но будет fail). Лучше: не планировать и вывести warning в логи.

------

# 8) Runs list: показать новые типы и уведомления

Убедись, что на странице Runs:

- `type=check` отображается
- в Details видно:
  - healthchecks ping results/errors
  - notifications sending results/errors (без секретов)

------

# 9) Manual test plan (обязательный output)

Реализатор должен описать проверки:

1. Включить notifications:
   - Telegram token/chat_id или email recipients
   - отправить “Test notification” из Settings
2. Включить healthchecks:
   - указать ping URL для backup
   - нажать “Ping healthchecks test”
3. Запустить “Run backup now”:
   - убедиться: при start был pingStart, на success pingSuccess
   - пришло уведомление (если включено)
4. Запустить “Run restic check now” (если есть кнопка/команда, или вручную dispatch):
   - появился run type=check
5. Scheduling:
   - включить schedule.enabled, выставить daily_time/weekly_check_time
   - указать, что нужен cron `schedule:run`
   - проверить, что задачи реально диспатчатся (можно временно поставить время на ближайшие 1–2 минуты)

------

# 10) Acceptance criteria

Шаг 8 завершён, если:

- В Settings есть настройки Notifications + Healthchecks + Scheduling
- Telegram/email отправляются по настройкам на success/fail (и есть тестовая отправка)
- Healthchecks пингуется на start/success/fail (и есть тестовый ping)
- Реализован `RunResticCheckJob` и он логируется в `backup_runs`
- Daily backup и weekly check планируются через scheduler по settings
- Ошибки уведомлений/healthchecks не ломают основной job, но логируются в `backup_runs.meta`

------

# 11) Доп. пожелания (не обязательно, но если легко)

- Добавить кнопку “Run restic check now” в UI (например на Settings или Snapshots)
- Добавить на Settings маленький “Health widget”: last successful backup time, last check time

------

Если хочешь, я могу следующим сообщением дать “мини-чеклист прод-ввода”: cron для scheduler, systemd queue worker, права на storage, где держать Telegram токен, и как безопасно проверять restore на staging.