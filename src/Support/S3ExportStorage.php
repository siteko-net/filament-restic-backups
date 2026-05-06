<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Models\BackupSetting;

class S3ExportStorage
{
    public function client(BackupSetting $settings): S3Client
    {
        $endpoint = $this->normalizeScalar($settings->endpoint);
        $accessKey = $this->normalizeScalar($settings->access_key);
        $secretKey = $this->normalizeScalar($settings->secret_key);

        if ($endpoint === null || $accessKey === null || $secretKey === null) {
            throw new \RuntimeException('S3 endpoint and credentials are required for stream export.');
        }

        return new S3Client([
            'version' => 'latest',
            'region' => (string) config('restic-backups.s3.region', 'us-east-1'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }

    public function bucket(BackupSetting $settings): string
    {
        $bucket = $this->normalizeScalar($settings->bucket);

        if ($bucket === null) {
            throw new \RuntimeException('S3 bucket is required for stream export.');
        }

        return $bucket;
    }

    public function exportKey(string $snapshotId, string $archiveName): string
    {
        $prefix = $this->normalizeScalar(config('restic-backups.exports.s3_prefix'))
            ?? $this->defaultExportPrefix();

        $snapshotPrefix = substr(trim($snapshotId), 0, 12) ?: 'snapshot';

        return trim($prefix, '/').'/snapshots/'.$snapshotPrefix.'/'.$archiveName;
    }

    public function uploadStream(S3Client $client, string $bucket, string $key, mixed $stream, ?callable $heartbeat = null): array
    {
        if (! is_resource($stream)) {
            throw new \RuntimeException('Upload stream must be a readable resource.');
        }

        $create = $client->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $key,
            'ContentType' => 'application/gzip',
        ]);

        $uploadId = (string) $create['UploadId'];
        $parts = [];
        $partNumber = 1;
        $bytes = 0;
        $chunkBytes = max(5 * 1024 * 1024, (int) config('restic-backups.exports.multipart_chunk_bytes', 8 * 1024 * 1024));
        $buffer = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, max(8192, $chunkBytes - strlen($buffer)));

                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read export stream.');
                }

                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;

                if (strlen($buffer) >= $chunkBytes) {
                    $this->uploadPart($client, $bucket, $key, $uploadId, $partNumber, $buffer, $parts, $bytes, $heartbeat);
                    $partNumber++;
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                $this->uploadPart($client, $bucket, $key, $uploadId, $partNumber, $buffer, $parts, $bytes, $heartbeat);
            }

            if ($parts === []) {
                throw new \RuntimeException('Restic produced an empty export stream.');
            }

            $complete = $client->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $parts,
                ],
            ]);

            return [
                'object_url' => isset($complete['ObjectURL']) ? (string) $complete['ObjectURL'] : null,
                'etag' => isset($complete['ETag']) ? trim((string) $complete['ETag'], '"') : null,
                'size_bytes' => $bytes,
                'parts' => count($parts),
            ];
        } catch (\Throwable $exception) {
            try {
                $client->abortMultipartUpload([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
            } catch (\Throwable) {
                // Preserve the original upload/read error.
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    protected function uploadPart(
        S3Client $client,
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        string $body,
        array &$parts,
        int &$bytes,
        ?callable $heartbeat,
    ): void {
        $bytes += strlen($body);

        $result = $client->uploadPart([
            'Bucket' => $bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $body,
        ]);

        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag' => (string) $result['ETag'],
        ];

        if (is_callable($heartbeat)) {
            $heartbeat([
                'uploaded_bytes' => $bytes,
                'uploaded_parts' => count($parts),
            ]);
        }
    }

    public function presignedDownloadUrl(S3Client $client, string $bucket, string $key, string $name, \DateTimeInterface $expiresAt): string
    {
        $request = $client->createPresignedRequest(
            $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key,
                'ResponseContentDisposition' => 'attachment; filename="'.$this->escapeHeaderFilename($name).'"',
            ]),
            $expiresAt,
        );

        return (string) $request->getUri();
    }

    public function deleteObject(S3Client $client, string $bucket, string $key): void
    {
        try {
            $client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchKey') {
                throw $exception;
            }
        }
    }

    protected function defaultExportPrefix(): string
    {
        $appSlug = Str::slug((string) config('app.name', '')) ?: Str::slug((string) basename(base_path()));

        if ($appSlug === '') {
            $appSlug = 'app';
        }

        $env = trim((string) config('app.env', 'production')) ?: 'production';

        return 'restic-exports/'.$appSlug.'/'.$env;
    }

    protected function escapeHeaderFilename(string $name): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $name);
    }

    protected function normalizeScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }
}
