# Filament Restic Backups

Пакет `siteko/filament-restic-backups` для управления бэкапами Restic из Filament (Laravel 12).

> **Статус:** beta (API и UX могут меняться до 1.0).

## Требования

- PHP 8.2+, Laravel 12, Filament 4
- Установлен `restic` (в `PATH` или через `RESTIC_BINARY`)
- Для дампа/восстановления БД:
  - MySQL/MariaDB: `mysqldump`/`mariadb-dump` и `mysql`/`mariadb`
  - Postgres: `pg_dump` (восстановление БД пока ориентировано на MySQL/MariaDB)
  - SQLite: внешние утилиты не нужны
- Очередь (в проде лучше не `sync`)
- Права на запись в:
  - `storage/app/_backup`
  - `storage/app/_restic_cache`

---

## Установка через Composer

### A) Приватный GitHub (рекомендуемо сейчас)

Добавьте в `composer.json` Laravel-проекта:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:siteko/filament-restic-backups.git" }
  ]
}
````

Затем установите пакет (лучше по тегу):

```bash
composer require siteko/filament-restic-backups:^0.1
```

> Если репозиторий приватный, на сервере/в CI должен быть доступ к GitHub по SSH (deploy key) или настроен токен.

### B) Packagist (на будущее)

```bash
composer require siteko/filament-restic-backups
```

### C) Локальная разработка (path repository)

В `composer.json` проекта:

```json
{
  "repositories": [
    { "type": "path", "url": "packages/siteko/restic-backups", "options": { "symlink": true } }
  ]
}
```

Затем:

```bash
composer require siteko/filament-restic-backups:*@dev
```

---

## Публикация и миграции

```bash
php artisan vendor:publish --tag=restic-backups-config
php artisan vendor:publish --tag=restic-backups-migrations
php artisan vendor:publish --tag=restic-backups-seeders
php artisan vendor:publish --tag=restic-backups-translations

php artisan migrate
php artisan db:seed --class=BackupSettingsSeeder
```

Примечания:

* `config` — нужен только если меняете дефолты (иначе достаточно env).
* `translations` — опционально (если хотите переопределять тексты).
* `seeders` — опционально (первая запись настроек может создаваться при первом открытии страницы).

---

## Подключение в Filament Panel

В `app/Providers/Filament/*PanelProvider.php`:

```php
use Filament\Panel;
use Siteko\FilamentResticBackups\Filament\ResticBackupsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugins([
        ResticBackupsPlugin::make(),
    ]);
}
```

Если panel ID не `admin`, укажите:

* `RESTIC_BACKUPS_PANEL=your_panel_id`

---

## Ассеты (прод)

Убедитесь, что ассеты Filament актуальны. В Filament 4 это обычно делается командой обновления/генерации ассетов (например через `php artisan filament:upgrade` в post-autoload-dump), либо вручную через `php artisan filament:assets`. ([Filament][1])

---

## Минимальная настройка

* `RESTIC_BINARY=/path/to/restic` (если не в `PATH`)
* `RESTIC_BACKUPS_LOCK_STORE=redis` (рекомендовано при нескольких воркерах/серверов)
* `config/restic-backups.php` — права доступа (`security.permissions`) и прочие параметры

---

## Проверка

Filament → группа “Backups” → страницы Overview / Settings.

