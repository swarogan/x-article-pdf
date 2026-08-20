<?php

declare(strict_types=1);

namespace XArticlePdf;

interface TwitterSource
{
    /**
     * @return array<string, mixed>
     */
    public function status(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function thread(string $id): array;
}
