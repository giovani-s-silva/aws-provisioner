<?php

declare(strict_types=1);

namespace AwsProvisioner\Storage;

use Aws\S3\S3Client;

/**
 * Creates an S3 bucket and applies a public-read bucket policy, or reuses it if it already exists.
 */
final class BucketProvisioner
{
    public function __construct(
        private readonly S3Client $s3,
    ) {
    }

    public function exists(string $bucketName): bool
    {
    }

    public function create(string $bucketName): void
    {
    }

    public function applyPublicReadPolicy(string $bucketName): void
    {
    }
}
