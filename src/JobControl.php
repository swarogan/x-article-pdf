<?php

declare(strict_types=1);

namespace XArticlePdf;

/**
 * Sygnał "stop" dla trwającego zadania. Przycisk w przeglądarce trafia do osobnego
 * requestu, więc jedynym wspólnym miejscem obu procesów PHP jest dysk.
 */
final class JobControl
{
    public function __construct(private readonly string $directory)
    {
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16,32}$/', $id);
    }

    public function cancel(string $jobId): void
    {
        if (!self::isValidId($jobId)) {
            return;
        }
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        @file_put_contents($this->path($jobId), (string) time());
    }

    public function isCancelled(string $jobId): bool
    {
        return self::isValidId($jobId) && is_file($this->path($jobId));
    }

    public function clear(string $jobId): void
    {
        if (self::isValidId($jobId)) {
            @unlink($this->path($jobId));
        }
    }

    private function path(string $jobId): string
    {
        return $this->directory . '/' . $jobId . '.stop';
    }
}
