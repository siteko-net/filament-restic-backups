<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Services;

use Aws\S3\S3Client;
use Throwable;

class S3BucketsService
{
    /**
     * @return array{ok: bool, buckets?: array<int, string>, message?: string}
     */
    public function listBuckets(string $endpoint, string $accessKey, string $secretKey): array
    {
        try {
            $client = $this->buildClient($endpoint, $accessKey, $secretKey);
            $result = $client->listBuckets();

            $buckets = [];

            foreach ($result['Buckets'] ?? [] as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }

                $name = $bucket['Name'] ?? null;

                if (is_string($name) && $name !== '') {
                    $buckets[] = $name;
                }
            }

            sort($buckets);

            return [
                'ok' => true,
                'buckets' => $buckets,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    protected function buildClient(string $endpoint, string $accessKey, string $secretKey): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }
}
