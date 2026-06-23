# Filament Restic Backups

Плагин `siteko/filament-restic-backups` для Filament, который управляет бэкапами и восстановлением через [Restic](https://restic.net/).

Официальный репозиторий: https://github.com/siteko-net/filament-restic-backups

> Статус: beta (до `1.0` возможны изменения API/UX).

## Что делает плагин

- Создает snapshot-бэкапы проекта и БД через restic.
- Хранит репозиторий в S3-совместимом хранилище (endpoint/bucket/ключи задаются в админке).
- Показывает в Filament обзор, историю запусков (`Runs`) и список snapshots.
- Поддерживает restore файлов и БД с safety-механиками (rollback-директории перед восстановлением).
- Применяет retention-политику (`keep_daily` / `keep_weekly` / `keep_monthly`) и `forget`/`prune`.
- Делает disaster recovery export снапшотов: локальный архив или прямой стрим в S3 (`auto` / `local` / `s3_stream`), включая FULL/DELTA относительно baseline.
- Блокирует параллельные операции через lock-механизм с heartbeat и авто-разблокировкой «протухших» локов.

## Страницы в админке (группа `Backups`)

- **Overview** — обзор, быстрые действия (создать снапшот, восстановить).
- **Snapshots** — список снапшотов с действиями восстановления и экспорта.
- **Runs** — история запусков (backup / restore / export) и их статусы.
- **Recovery Exports** — disaster recovery экспорты (FULL/DELTA), скачивание/удаление архивов.
- **Settings** — конфигурация S3, restic password, расписание, retention, paths, режим экспорта.

## Пользовательский урок (RU)

Если нужен текст для обучения пользователей (менеджеров) и быстрый обзор страницы в админке — см. `docs/user-lesson-ru.md`.

## Требования

- PHP 8.2+
- Laravel 12
- Filament 5
- `aws/aws-sdk-php` ^3 (ставится как зависимость; используется для проверки соединения и листинга бакетов)
- Установлен `restic` (в `PATH` или через `RESTIC_BINARY`)
- Для дампа/restore БД:
  - MySQL/MariaDB: `mysqldump`/`mariadb-dump` и `mysql`/`mariadb`
  - PostgreSQL: `pg_dump` (restore БД в текущей версии ориентирован на MySQL/MariaDB)
  - SQLite: внешние утилиты не требуются
- Для экспорта локальных архивов: `tar`
- Рабочая очередь (production: не `sync`)
- Права на запись в `storage/app/_backup` и `storage/app/_restic_cache`

## Установка

Если пакет доступен через Packagist:

```bash
composer require siteko/filament-restic-backups
```

Если используется установка напрямую из GitHub-репозитория:

```bash
composer config repositories.siteko-restic-backups vcs git@github.com:siteko-net/filament-restic-backups.git
composer require siteko/filament-restic-backups
```

Для приватного репозитория убедитесь, что CI/сервер имеет доступ к GitHub (SSH deploy key или token).

## Публикация ресурсов и миграции

```bash
php artisan vendor:publish --tag=restic-backups-config
php artisan vendor:publish --tag=restic-backups-migrations
php artisan vendor:publish --tag=restic-backups-seeders
php artisan vendor:publish --tag=restic-backups-translations

php artisan migrate
php artisan db:seed --class=BackupSettingsSeeder
```

Миграции пакета загружаются автоматически (`loadMigrationsFrom`), поэтому достаточно `php artisan migrate`. Публикация миграций нужна, только если вы хотите их редактировать.

Что опционально:

- `restic-backups-config` (если хотите менять дефолты)
- `restic-backups-seeders` (если хотите заранее создать запись настроек)
- `restic-backups-translations` (если хотите переопределять тексты; есть `en` и `ru`)

## Подключение в Filament Panel

Добавьте плагин в ваш `PanelProvider`:

```php
use Filament\Panel;
use Siteko\FilamentResticBackups\Filament\ResticBackupsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            ResticBackupsPlugin::make(),
        ]);
}
```

По умолчанию плагин регистрируется на панели `admin`.
Для другой панели установите `RESTIC_BACKUPS_PANEL=your_panel_id`.

## Настройка S3-хранилища

Параметры доступа к репозиторию задаются **в админке** на странице `Backups → Settings`, а не через env:

- **Endpoint** — например `https://s3.ru-1.storage.selcloud.ru`
- **Bucket** — имя бакета
- **Access key / Secret key** — S3-ключи (хранятся зашифрованными)
- **Restic password** — парольная фраза шифрования репозитория

> 🔑 Сохраните restic password отдельно и надёжно — без неё восстановление невозможно.

Репозиторий собирается как `s3:{endpoint}/{bucket}/{repository_prefix}`. Если префикс не задан вручную, он вычисляется автоматически как `restic/{app-slug}/{env}`. Регион по умолчанию `us-east-1` (`RESTIC_BACKUPS_S3_REGION`); для не-AWS провайдеров обычно не критичен.

Рекомендации по бакету для restic: класс хранения **стандартный** (не «холодный» — restic делает много мелких запросов), доступ **приватный**, версионирование и Object Lock — **выключены** (restic версионирует сам и должен мочь удалять объекты при `prune`).

## Обязательная эксплуатационная настройка

### 1) Очередь

Плагин выполняет тяжелые операции в queue jobs. Нужен запущенный worker.

```bash
php artisan queue:work --tries=1
```

### 2) Laravel Scheduler

Плагин регистрирует задачи scheduler автоматически (по `schedule.enabled`, `schedule.daily_time`, `schedule.timezone` из `backup_settings`):

- `restic-backups:run --trigger=schedule` — создание снапшота по расписанию.
- `restic-backups:cleanup-exports --hours=24` — ежедневная очистка экспортов.
- `restic-backups:cleanup-rollbacks --hours=24` — ежедневная очистка rollback-директорий.

И системный cron:

```bash
* * * * * php /path/to/project/artisan schedule:run >> /dev/null 2>&1
```

### 3) Filament database notifications

Чтобы видеть уведомления в админке:

```php
$panel->databaseNotifications();
```

И создайте таблицу:

```bash
php artisan notifications:table
php artisan migrate
```

## env / config параметры

Полный список — в `config/restic-backups.php`. Наиболее полезные:

- `RESTIC_BACKUPS_ENABLED` — включение плагина (по умолчанию `true`).
- `RESTIC_BACKUPS_PANEL` — ID Filament-панели (по умолчанию `admin`).
- `RESTIC_BINARY` — путь к бинарнику restic, если он не в `PATH`.
- `RESTIC_BACKUPS_LOCK_STORE` — cache store для локов и heartbeat (рекомендуется `redis` для multi-worker/multi-server).
- `RESTIC_BACKUPS_RETRY_LOCK` — таймаут ожидания снятия restic-лока (по умолчанию `10m`).
- `RESTIC_BACKUPS_AUTO_UNLOCK_STALE` / `RESTIC_BACKUPS_STALE_LOCK_AGE_SECONDS` — авто-разблокировка протухших локов.
- `RESTIC_BACKUPS_S3_REGION` — регион S3 (по умолчанию `us-east-1`).
- `RESTIC_BACKUPS_SNAPSHOT_EXPORT_MODE` — режим экспорта снапшотов: `auto` | `local` | `s3_stream`.
- `RESTIC_BACKUPS_EXPORTS_S3_PREFIX` — префикс для S3-экспортов.
- `RESTIC_BACKUPS_EXPORTS_MULTIPART_CHUNK_BYTES` — размер чанка multipart-загрузки (по умолчанию 8 MiB).

Параметры S3-доступа (endpoint/bucket/ключи/restic password), расписание, retention и paths хранятся в БД (`backup_settings`) и редактируются в `Backups → Settings`.

## Восстановление (restore)

Восстановление запускается **из админки** (страница `Backups → Snapshots`, действие restore на выбранном снапшоте) и выполняется в очереди job-ом. Отдельной artisan-команды для restore нет.

Параметры восстановления:

- **Scope** — что восстанавливать:
  - `files` — только файлы проекта;
  - `db` — только база данных;
  - `both` — файлы и БД.
- **Mode** (для файлов):
  - `rsync` — синхронизация в текущий каталог проекта «на месте» (с `--delete`, исключая `.env`, `storage/framework`, `storage/logs`, `bootstrap/cache`);
  - `atomic` — сборка восстановленного проекта рядом и атомарная подмена каталога (требует ту же ФС и права на запись в родительский каталог); прежний каталог сохраняется как rollback и удаляется через 24 часа.
- **Safety backup** (включён по умолчанию) — перед восстановлением создаётся страховочный снапшот с тегом `safety-before-restore`.

Что происходит во время cutover:

- приложение переводится в maintenance mode (`artisan down`) с секретным bypass-путём;
- для БД: дамп извлекается из снапшота, таблицы очищаются (кроме служебных, см. `database.preserve_tables`), затем импортируется дамп (MySQL/MariaDB);
- после успеха выполняются `optimize:clear`, `queue:restart`, `storage:link` (для файлов) и снимается maintenance mode (`artisan up`).

При ошибке job пытается **автоматически откатиться**: вернуть прежний каталог (atomic) и восстановить БД из safety-дампа, затем снять maintenance mode. Прогресс и шаги видны в `Backups → Runs`.

## Artisan-команды

- `php artisan restic-backups:run` — создать снапшот (через очередь).
  - Опции: `--tags=`, `--trigger=manual|schedule|system`, `--connection=`, `--sync`.
- `php artisan restic-backups:cleanup-exports [--hours=24] [--dry-run]` — удалить просроченные экспорты и stale work-директории.
- `php artisan restic-backups:cleanup-rollbacks [--hours=24] [--dry-run]` — удалить stale `__before_restore_` директории.
- `php artisan restic-backups:unlock [--force] [--stale] [--stale-seconds=900]` — снять operation-lock плагина.

## Локальная разработка плагина (standalone workflow)

Если вы разрабатываете плагин из этого репозитория и подключаете его в отдельный Laravel-проект:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../filament-restic-backups",
      "options": { "symlink": true }
    }
  ]
}
```

```bash
composer require siteko/filament-restic-backups:*@dev
```

## Проверка после установки

1. Откройте Filament.
2. Убедитесь, что появилась группа `Backups` со страницами Overview / Snapshots / Runs / Recovery Exports / Settings.
3. Заполните `Backups → Settings` (S3, restic password, расписание).
4. Запустите `Create snapshot` на странице `Overview`.
5. Проверьте запись в `Backups → Runs` и появление снапшота в `Backups → Snapshots`.

## Лицензия

MIT
