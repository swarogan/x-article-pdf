<?php

declare(strict_types=1);

namespace XArticlePdf;

interface Translator
{
    /**
     * @param list<string> $texts
     * @param (callable(int, int): void)|null $onProgress current, total
     * @return list<string>
     */
    public function translate(array $texts, string $targetLanguage, ?callable $onProgress = null): array;
}
