<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

class DisasterRecoveryExport
{
    public const README_NAME = 'README.txt';
    public const MANIFEST_NAME = 'manifest.json';
    public const TOOLS_DIR = 'TOOLS';
    public const RESTORE_SH = 'restore.sh';
    public const RESTORE_PS1 = 'restore.ps1';

    /**
     * @param  array<string, mixed>  $context
     */
    public static function buildReadmeFull(array $context): string
    {
        $snapshotId = (string) ($context['snapshot_id'] ?? 'unknown');
        $generatedAt = (string) ($context['generated_at'] ?? '');

        $lines = [
            'Disaster Recovery Export (FULL)',
            'Snapshot: ' . $snapshotId,
        ];

        if ($generatedAt !== '') {
            $lines[] = 'Generated at: ' . $generatedAt;
        }

        $lines[] = '';
        $lines[] = 'This archive is a plain, unencrypted copy of the project.';
        $lines[] = 'Completeness depends on the snapshot include/exclude settings.';
        $lines[] = '';
        $lines[] = 'Restore options:';
        $lines[] = '1) Full only';
        $lines[] = '   - Extract the FULL archive into the target directory.';
        $lines[] = '2) Full + Delta';
        $lines[] = '   - Extract FULL and DELTA archives.';
        $lines[] = '   - Run TOOLS/restore.sh <full_dir> <delta_dir>';
        $lines[] = '   - Or TOOLS/restore.ps1 -FullDir <full_dir> -DeltaDir <delta_dir>';
        $lines[] = '';
        $lines[] = 'Database dump (if present): storage/app/_backup/db.sql.gz';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function buildReadmeDelta(array $context): string
    {
        $baselineId = (string) ($context['baseline_snapshot_id'] ?? 'unknown');
        $targetId = (string) ($context['to_snapshot_id'] ?? 'unknown');
        $generatedAt = (string) ($context['generated_at'] ?? '');

        $lines = [
            'Disaster Recovery Export (DELTA)',
            'Baseline snapshot: ' . $baselineId,
            'Target snapshot: ' . $targetId,
        ];

        if ($generatedAt !== '') {
            $lines[] = 'Generated at: ' . $generatedAt;
        }

        $lines[] = '';
        $lines[] = 'This archive contains changes since the baseline snapshot.';
        $lines[] = 'Apply it on top of the FULL archive using the restore scripts.';
        $lines[] = 'Deleted paths are listed in ' . self::MANIFEST_NAME . '.';
        $lines[] = '';
        $lines[] = 'Usage:';
        $lines[] = '- TOOLS/restore.sh <full_dir> <delta_dir>';
        $lines[] = '- TOOLS/restore.ps1 -FullDir <full_dir> -DeltaDir <delta_dir>';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function buildRestoreShellScript(): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: restore.sh <full_dir> [delta_dir]"
}

if [[ $# -lt 1 ]]; then
  usage
  exit 1
fi

FULL_DIR="$1"
DELTA_DIR="${2:-}"

if [[ ! -d "$FULL_DIR" ]]; then
  echo "Full directory not found: $FULL_DIR" >&2
  exit 1
fi

if [[ -z "$DELTA_DIR" ]]; then
  echo "No delta directory provided. Nothing to apply."
  exit 0
fi

if [[ ! -d "$DELTA_DIR" ]]; then
  echo "Delta directory not found: $DELTA_DIR" >&2
  exit 1
fi

FILES_DIR="$DELTA_DIR/files"
MANIFEST="$DELTA_DIR/manifest.json"

if [[ ! -d "$FILES_DIR" ]]; then
  echo "Delta files directory not found: $FILES_DIR" >&2
  exit 1
fi

normalize_path() {
  local path="$1"
  path="${path//\\//}"
  path="${path#./}"
  if [[ "$path" == "" || "$path" == /* ]]; then
    return 1
  fi
  IFS="/" read -r -a parts <<< "$path"
  for part in "${parts[@]}"; do
    if [[ "$part" == "" || "$part" == "." || "$part" == ".." ]]; then
      return 1
    fi
  done
  echo "$path"
  return 0
}

copy_entry() {
  local src="$1"
  local rel="$2"
  local dest="$FULL_DIR/$rel"
  local dest_dir
  dest_dir="$(dirname "$dest")"
  mkdir -p "$dest_dir"
  cp -a "$src" "$dest"
}

cd "$FILES_DIR"

while IFS= read -r -d "" entry; do
  rel="${entry#./}"
  if ! safe_rel=$(normalize_path "$rel"); then
    echo "Skip unsafe path: $rel" >&2
    continue
  fi
  if [[ -d "$entry" ]]; then
    mkdir -p "$FULL_DIR/$safe_rel"
  fi
done < <(find . -type d -print0)

while IFS= read -r -d "" entry; do
  rel="${entry#./}"
  if ! safe_rel=$(normalize_path "$rel"); then
    echo "Skip unsafe path: $rel" >&2
    continue
  fi
  copy_entry "$entry" "$safe_rel"
done < <(find . -type f -o -type l -print0)

if [[ -f "$MANIFEST" ]]; then
  if command -v python3 >/dev/null 2>&1; then
    deleted_paths=$(python3 - "$MANIFEST" <<'PY'
import json
import sys

manifest = sys.argv[1] if len(sys.argv) > 1 else ""
if not manifest:
    sys.exit(0)

with open(manifest, "r") as handle:
    data = json.load(handle)

for path in data.get("deleted", []):
    if isinstance(path, str):
        print(path)
PY
)
  elif command -v python >/dev/null 2>&1; then
    deleted_paths=$(python - "$MANIFEST" <<'PY'
import json
import sys

manifest = sys.argv[1] if len(sys.argv) > 1 else ""
if not manifest:
    sys.exit(0)

with open(manifest, "r") as handle:
    data = json.load(handle)

for path in data.get("deleted", []):
    if isinstance(path, str):
        print(path)
PY
)
  elif command -v php >/dev/null 2>&1; then
    deleted_paths=$(php - "$MANIFEST" <<'PHP'
<?php
$manifest = $argv[1] ?? '';
if ($manifest === '') {
    exit(0);
}

$data = json_decode(file_get_contents($manifest), true);
if (!is_array($data)) {
    exit(0);
}

foreach ($data['deleted'] ?? [] as $path) {
    if (is_string($path)) {
        echo $path, PHP_EOL;
    }
}
?>
PHP
)
  else
    echo "No json parser found (python/php). Skipping deletes." >&2
    deleted_paths=""
  fi

  while IFS= read -r path; do
    [[ -z "$path" ]] && continue
    if ! safe_rel=$(normalize_path "$path"); then
      echo "Skip unsafe delete path: $path" >&2
      continue
    fi
    target="$FULL_DIR/$safe_rel"
    if [[ -e "$target" ]]; then
      rm -rf "$target"
    fi
  done <<< "$deleted_paths"
else
  echo "Manifest not found: $MANIFEST" >&2
fi

echo "Delta applied."
BASH;
    }

    public static function buildRestorePowerShellScript(): string
    {
        return implode("\r\n", [
            'param(',
            '  [Parameter(Mandatory = $true)]',
            '  [string]$FullDir,',
            '  [string]$DeltaDir = ""',
            ')',
            '',
            '$FullDir = (Resolve-Path -LiteralPath $FullDir).Path',
            'if (-not (Test-Path -LiteralPath $FullDir)) {',
            '  Write-Error "Full directory not found: $FullDir"',
            '  exit 1',
            '}',
            '',
            'if ([string]::IsNullOrWhiteSpace($DeltaDir)) {',
            '  Write-Output "No delta directory provided. Nothing to apply."',
            '  exit 0',
            '}',
            '',
            '$DeltaDir = (Resolve-Path -LiteralPath $DeltaDir).Path',
            'if (-not (Test-Path -LiteralPath $DeltaDir)) {',
            '  Write-Error "Delta directory not found: $DeltaDir"',
            '  exit 1',
            '}',
            '',
            '$FilesDir = Join-Path $DeltaDir "files"',
            '$Manifest = Join-Path $DeltaDir "manifest.json"',
            '',
            'if (-not (Test-Path -LiteralPath $FilesDir)) {',
            '  Write-Error "Delta files directory not found: $FilesDir"',
            '  exit 1',
            '}',
            '',
            'function Test-SafeRelativePath {',
            '  param([string]$Path)',
            '  $Path = $Path -replace "\\\\", "/"',
            '  if ($Path.StartsWith("./")) { $Path = $Path.Substring(2) }',
            '  $Path = $Path.TrimStart("/")',
            '  if ([string]::IsNullOrWhiteSpace($Path)) { return $false }',
            '  if ($Path.StartsWith("/")) { return $false }',
            '  foreach ($part in $Path.Split("/")) {',
            '    if ($part -eq "" -or $part -eq "." -or $part -eq "..") { return $false }',
            '  }',
            '  return $true',
            '}',
            '',
            '$files = Get-ChildItem -LiteralPath $FilesDir -Force -Recurse',
            'foreach ($item in $files) {',
            '  $rel = $item.FullName.Substring($FilesDir.Length).TrimStart("\\", "/")',
            '  $rel = $rel -replace "\\\\", "/"',
            '  if (-not (Test-SafeRelativePath $rel)) {',
            '    Write-Warning "Skip unsafe path: $rel"',
            '    continue',
            '  }',
            '  $dest = Join-Path $FullDir $rel',
            '  if ($item.PSIsContainer) {',
            '    New-Item -ItemType Directory -Force -Path $dest | Out-Null',
            '  } else {',
            '    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null',
            '    Copy-Item -LiteralPath $item.FullName -Destination $dest -Force',
            '  }',
            '}',
            '',
            'if (Test-Path -LiteralPath $Manifest) {',
            '  $data = Get-Content -LiteralPath $Manifest -Raw | ConvertFrom-Json',
            '  foreach ($path in $data.deleted) {',
            '    if (-not [string]::IsNullOrWhiteSpace($path)) {',
            '      $rel = ($path -replace "\\\\", "/")',
            '      if (-not (Test-SafeRelativePath $rel)) {',
            '        Write-Warning "Skip unsafe delete path: $rel"',
            '        continue',
            '      }',
            '      $target = Join-Path $FullDir $rel',
            '      if (Test-Path -LiteralPath $target) {',
            '        Remove-Item -LiteralPath $target -Recurse -Force',
            '      }',
            '    }',
            '  }',
            '} else {',
            '  Write-Warning "Manifest not found: $Manifest"',
            '}',
            '',
            'Write-Output "Delta applied."',
            '',
        ]);
    }

    public static function writeTools(string $baseDir): void
    {
        $toolsDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::TOOLS_DIR;

        if (! is_dir($toolsDir)) {
            @mkdir($toolsDir, 0755, true);
        }

        $shPath = $toolsDir . DIRECTORY_SEPARATOR . self::RESTORE_SH;
        $ps1Path = $toolsDir . DIRECTORY_SEPARATOR . self::RESTORE_PS1;

        @file_put_contents($shPath, self::buildRestoreShellScript());
        @file_put_contents($ps1Path, self::buildRestorePowerShellScript());

        @chmod($shPath, 0755);
        @chmod($ps1Path, 0644);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function writeReadmeFull(string $baseDir, array $context): void
    {
        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::README_NAME;
        @file_put_contents($path, self::buildReadmeFull($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function writeReadmeDelta(string $baseDir, array $context): void
    {
        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::README_NAME;
        @file_put_contents($path, self::buildReadmeDelta($context));
    }
}
