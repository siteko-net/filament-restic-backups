# Filament Restic Backups

Плагин `siteko/filament-restic-backups` для Filament, который управляет бэкапами и восстановлением через Restic.

Официальный репозиторий: https://github.com/siteko-net/filament-restic-backups

> Статус: beta (до `1.0` возможны изменения API/UX).

## Что делает плагин

- Создает snapshot-бэкапы проекта и БД.
- Показывает историю запусков и snapshots в Filament.
- Поддерживает restore (файлы/БД) с safety-механиками.
- Делает export архивов snapshots и disaster recovery (FULL/DELTA).
- Блокирует параллельные операции через lock-механизм.

## Пользовательский урок (RU)

Если нужен текст для обучения пользователей (менеджеров) и быстрый обзор страницы в админке — см. `docs/user-lesson-ru.md`.

## Требования

- PHP 8.2+
- Laravel 12
- Filament 4
- Установлен `restic` (в `PATH` или через `RESTIC_BINARY`)
- Для дампа/restore БД:
  - MySQL/MariaDB: `mysqldump`/`mariadb-dump` и `mysql`/`mariadb`
  - PostgreSQL: `pg_dump` (restore БД в текущей версии ориентирован на MySQL/MariaDB)
  - SQLite: внешние утилиты не требуются
- Для экспорта архивов: `tar`
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

Что обязательно:

- `restic-backups-migrations` + `php artisan migrate`

Что опционально:

- `restic-backups-config` (если хотите менять дефолты)
- `restic-backups-seeders` (если хотите заранее создать запись настроек)
- `restic-backups-translations` (если хотите переопределять тексты)

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

## Обязательная эксплуатационная настройка

### 1) Очередь

Плагин выполняет тяжелые операции в queue jobs. Нужен запущенный worker.

Пример:

```bash
php artisan queue:work --tries=1
```

### 2) Laravel Scheduler

Плагин регистрирует задачи scheduler автоматически:

- `restic-backups:run --trigger=schedule` — по значениям `schedule.enabled`, `schedule.daily_time`, `schedule.timezone` из `backup_settings`.
- `restic-backups:cleanup-exports --hours=24` — ежедневно (в `schedule.daily_time` и `schedule.timezone`).
- `restic-backups:cleanup-rollbacks --hours=24` — ежедневно (в `schedule.daily_time` и `schedule.timezone`).

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

## Минимальные env/config параметры

- `RESTIC_BINARY=/path/to/restic` (если бинарник не в `PATH`)
- `RESTIC_BACKUPS_PANEL=admin` (ID Filament-панели)
- `RESTIC_BACKUPS_LOCK_STORE=redis` (рекомендуется для multi-worker/multi-server)

Остальные настройки: `config/restic-backups.php`.

## Полезные artisan-команды

- `php artisan restic-backups:run`
- `php artisan restic-backups:run --sync`
- `php artisan restic-backups:cleanup-exports --dry-run`
- `php artisan restic-backups:cleanup-rollbacks --dry-run`
- `php artisan restic-backups:unlock --stale`

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
2. Убедитесь, что появилась группа `Backups`.
3. Заполните `Backups -> Settings`.
4. Запустите `Create snapshot` на странице `Overview`.
5. Проверьте запись в `Backups -> Runs`.

## Лицензия

MIT
