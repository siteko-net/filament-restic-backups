<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

class SharedStorageSymlink
{
    public const ARCHIVE_PATH = '_shared/storage';

    public const RESTORE_MODE = 'manual_only';

    /**
     * Internal plugin/runtime directories that must never end up inside exported bundles.
     *
     * @var array<int, string>
     */
    public const INTERNAL_PROJECT_EXCLUDE_PATHS = [
        'storage/app/_restic_cache',
        'storage/app/_backup/exports',
        'storage/app/_backup/restore',
    ];

    /**
     * The same internal runtime directories, but relative to the shared storage root itself.
     *
     * @var array<int, string>
     */
    public const INTERNAL_STORAGE_RELATIVE_EXCLUDES = [
        'app/_restic_cache',
        'app/_backup/exports',
        'app/_backup/restore',
    ];

    /**
     * @param  array<string, mixed>  $paths
     * @return array<string, mixed>
     */
    public static function normalizePathConfig(array $paths): array
    {
        $paths['include'] = is_array($paths['include'] ?? null) ? $paths['include'] : [];
        $paths['exclude'] = is_array($paths['exclude'] ?? null) ? $paths['exclude'] : [];
        $paths['storage'] = is_array($paths['storage'] ?? null) ? $paths['storage'] : [];
        $paths['storage']['shared_symlink'] = (bool) ($paths['storage']['shared_symlink'] ?? false);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array{
     *     enabled: bool,
     *     status: string,
     *     logical_path: string,
     *     symlink_path: string,
     *     target_path: string|null,
     *     archive_path: string,
     *     restore_mode: string
     * }
     */
    public static function describe(string $projectRoot, array $paths): array
    {
        $paths = self::normalizePathConfig($paths);
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $symlinkPath = $projectRoot.DIRECTORY_SEPARATOR.'storage';

        $status = 'missing';
        $targetPath = null;

        if (is_link($symlinkPath)) {
            $resolvedTarget = realpath($symlinkPath);

            if (is_string($resolvedTarget) && $resolvedTarget !== '' && is_dir($resolvedTarget)) {
                $status = 'resolved';
                $targetPath = rtrim($resolvedTarget, DIRECTORY_SEPARATOR);
            } else {
                $status = 'unresolved_target';
            }
        } elseif (file_exists($symlinkPath)) {
            $status = 'not_symlink';
        }

        return [
            'enabled' => (bool) ($paths['storage']['shared_symlink'] ?? false),
            'status' => $status,
            'logical_path' => 'storage',
            'symlink_path' => $symlinkPath,
            'target_path' => $targetPath,
            'archive_path' => self::ARCHIVE_PATH,
            'restore_mode' => self::RESTORE_MODE,
        ];
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null
     * }  $details
     */
    public static function targetPathForBackup(array $details): ?string
    {
        $enabled = (bool) ($details['enabled'] ?? false);
        $status = (string) ($details['status'] ?? '');
        $targetPath = self::normalizeAbsolutePath($details['target_path'] ?? null);

        if (! $enabled || $status !== 'resolved' || $targetPath === null) {
            return null;
        }

        return $targetPath;
    }

    /**
     * @param  array<int, string>  $backupPaths
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null
     * }  $details
     * @return array<int, string>
     */
    public static function appendBackupPath(array $backupPaths, array $details): array
    {
        $paths = [];

        foreach ($backupPaths as $path) {
            $normalized = trim((string) $path);

            if ($normalized === '') {
                continue;
            }

            $paths[$normalized] = true;
        }

        $targetPath = self::targetPathForBackup($details);

        if ($targetPath !== null) {
            $paths[$targetPath] = true;
        }

        return array_values(array_keys($paths));
    }

    /**
     * @param  array<int, string>  $excludePaths
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null
     * }  $details
     * @return array<int, string>
     */
    public static function appendMappedExcludePaths(array $excludePaths, array $details): array
    {
        $normalized = [];

        foreach ($excludePaths as $excludePath) {
            $excludePath = trim((string) $excludePath);

            if ($excludePath === '') {
                continue;
            }

            $normalized[$excludePath] = true;
        }

        $targetPath = self::targetPathForBackup($details);

        if ($targetPath === null) {
            return array_values(array_keys($normalized));
        }

        foreach (array_keys($normalized) as $excludePath) {
            $relative = self::normalizeRelativePath($excludePath);

            if ($relative === null || ! ($relative === 'storage' || str_starts_with($relative, 'storage/'))) {
                continue;
            }

            $mappedSuffix = substr($relative, strlen('storage'));
            $mappedPath = $targetPath.str_replace('/', DIRECTORY_SEPARATOR, $mappedSuffix);
            $normalized[$mappedPath] = true;
        }

        return array_values(array_keys($normalized));
    }

    /**
     * @param  array<int, string>  $excludePaths
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null
     * }  $details
     * @return array<int, string>
     */
    public static function appendInternalExportExcludePaths(array $excludePaths, array $details): array
    {
        return self::appendMappedExcludePaths(
            array_merge($excludePaths, self::INTERNAL_PROJECT_EXCLUDE_PATHS),
            $details,
        );
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     logical_path?: string,
     *     target_path?: string|null,
     *     archive_path?: string,
     *     restore_mode?: string
     * }  $details
     * @return array<string, mixed>|null
     */
    public static function manifestEntry(array $details, bool $presentInArchive = true): ?array
    {
        $targetPath = self::targetPathForBackup($details);

        if ($targetPath === null) {
            return null;
        }

        return [
            'enabled' => true,
            'logical_path' => (string) ($details['logical_path'] ?? 'storage'),
            'source' => $targetPath,
            'archive_path' => (string) ($details['archive_path'] ?? self::ARCHIVE_PATH),
            'restore_mode' => (string) ($details['restore_mode'] ?? self::RESTORE_MODE),
            'present_in_archive' => $presentInArchive,
        ];
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null,
     *     archive_path?: string
     * }  $details
     */
    public static function restoredTargetPath(string $restoreDir, array $details): ?string
    {
        $targetPath = self::targetPathForBackup($details);

        if ($targetPath === null) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $targetPath), '/');

        if ($relative === '') {
            return null;
        }

        return rtrim($restoreDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null,
     *     archive_path?: string
     * }  $details
     * @return array<string, mixed>|null
     */
    public static function copyRestoredTreeToBundle(string $restoreDir, string $bundleRoot, array $details): ?array
    {
        $sourceDir = self::restoredTargetPath($restoreDir, $details);

        if ($sourceDir === null) {
            return null;
        }

        $manifest = self::manifestEntry($details, presentInArchive: is_dir($sourceDir));

        if (! is_dir($sourceDir)) {
            return $manifest;
        }

        $destinationDir = self::bundleDirectoryPath($bundleRoot);
        self::mirrorDirectory($sourceDir, $destinationDir, self::INTERNAL_STORAGE_RELATIVE_EXCLUDES);

        return $manifest;
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     status?: string,
     *     target_path?: string|null,
     *     archive_path?: string
     * }  $details
     */
    public static function matchesBase(?string $basePath, array $details): bool
    {
        $targetPath = self::targetPathForBackup($details);
        $basePath = self::normalizeAbsolutePath($basePath);

        return $targetPath !== null && $basePath !== null && $basePath === $targetPath;
    }

    /**
     * @param  array{
     *     archive_path?: string
     * }  $details
     */
    public static function bundleDirectoryPath(string $bundleRoot, array $details = []): string
    {
        $archivePath = (string) ($details['archive_path'] ?? self::ARCHIVE_PATH);
        $archivePath = str_replace('/', DIRECTORY_SEPARATOR, trim($archivePath, '/'));

        return rtrim($bundleRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$archivePath;
    }

    /**
     * @param  array<int, string>  $excludeRelativePaths
     */
    protected static function mirrorDirectory(string $sourceDir, string $destinationDir, array $excludeRelativePaths = []): void
    {
        self::ensureDirectory($destinationDir);

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $excludeRelativePaths = array_values(array_filter(array_map(
            static fn (string $path): ?string => self::normalizeRelativePath($path),
            $excludeRelativePaths,
        )));

        $directoryIterator = new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (\SplFileInfo $item) use ($sourceDir, $excludeRelativePaths): bool {
                if ($excludeRelativePaths === []) {
                    return true;
                }

                $pathname = $item->getPathname();
                $prefixLength = strlen($sourceDir);
                $relative = substr($pathname, $prefixLength);
                $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
                $relative = self::normalizeRelativePath($relative);

                return ! self::shouldExcludeRelativePath($relative, $excludeRelativePaths);
            },
        );

        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = $iterator->getSubPathName();
            $targetPath = $destinationDir.DIRECTORY_SEPARATOR.$relative;

            if ($item->isLink()) {
                $linkTarget = readlink($item->getPathname());

                if ($linkTarget === false) {
                    throw new \RuntimeException('Unable to read symlink target from shared storage export.');
                }

                self::ensureDirectory(dirname($targetPath));
                @symlink($linkTarget, $targetPath);

                continue;
            }

            if ($item->isDir()) {
                self::ensureDirectory($targetPath);

                continue;
            }

            self::ensureDirectory(dirname($targetPath));

            if (! @copy($item->getPathname(), $targetPath)) {
                throw new \RuntimeException('Unable to copy shared storage file into export bundle.');
            }
        }
    }

    /**
     * @param  array<int, string>  $excludeRelativePaths
     */
    protected static function shouldExcludeRelativePath(?string $relativePath, array $excludeRelativePaths): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        foreach ($excludeRelativePaths as $excludePath) {
            if ($relativePath === $excludePath || str_starts_with($relativePath, $excludePath.'/')) {
                return true;
            }
        }

        return false;
    }

    protected static function ensureDirectory(string $path): void
    {
        $path = trim($path);

        if ($path === '') {
            return;
        }

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new \RuntimeException("Unable to create directory [{$path}].");
        }
    }

    protected static function normalizeRelativePath(mixed $path): ?string
    {
        if (! is_string($path) && ! is_numeric($path)) {
            return null;
        }

        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? null : $path;
    }

    protected static function normalizeAbsolutePath(mixed $path): ?string
    {
        if (! is_string($path) && ! is_numeric($path)) {
            return null;
        }

        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
