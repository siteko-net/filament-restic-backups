<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

class ProjectRootResolver
{
    public static function current(): string
    {
        return self::configuredOrCurrent(null);
    }

    public static function configuredOrCurrent(mixed $configuredRoot): string
    {
        $configuredRoot = self::normalizePath($configuredRoot);

        if ($configuredRoot !== null) {
            return self::stabilizeDeployPath($configuredRoot) ?? $configuredRoot;
        }

        $fallbackRoot = self::normalizePath(config('restic-backups.paths.project_root', base_path()))
            ?? self::normalizePath(base_path())
            ?? base_path();

        return self::stabilizeDeployPath($fallbackRoot) ?? $fallbackRoot;
    }

    protected static function stabilizeDeployPath(string $path): ?string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return null;
        }

        $parent = dirname($path);

        if (basename($parent) !== 'releases') {
            return null;
        }

        $appRoot = dirname($parent);
        $currentPath = $appRoot.DIRECTORY_SEPARATOR.'current';

        if (is_dir($currentPath) || is_link($currentPath)) {
            return $currentPath;
        }

        return null;
    }

    protected static function normalizePath(mixed $path): ?string
    {
        if (! is_string($path) && ! is_numeric($path)) {
            return null;
        }

        $path = trim((string) $path);

        return $path === '' ? null : rtrim($path, DIRECTORY_SEPARATOR);
    }
}
