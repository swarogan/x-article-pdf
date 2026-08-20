<?php

declare(strict_types=1);

namespace XArticlePdf;

final class OllamaCatalog
{
    public function __construct(
        private readonly string $baseUrl = 'http://127.0.0.1:11434',
        private readonly int $timeoutSeconds = 4,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $host = getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434';

        return new self(rtrim($host, '/'));
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($this->baseUrl . '/api/tags', false, $context);
        if ($body === false) {
            throw new FetchException('Nie można pobrać listy modeli z Ollama.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new FetchException('Ollama zwróciła niepoprawną listę modeli.');
        }

        return self::namesFromTags($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function namesFromTags(array $payload): array
    {
        $models = $payload['models'] ?? [];
        if (!is_array($models)) {
            return [];
        }
        $names = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = $model['name'] ?? $model['model'] ?? null;
            if (is_string($name) && $name !== '' && self::isChatModel($name, $model)) {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * @param array<string, mixed> $model
     */
    public static function isChatModel(string $name, array $model = []): bool
    {
        $hay = strtolower($name . ' ' . (string) ($model['details']['family'] ?? ''));
        foreach (['embed', 'bge-', 'e5-', 'minilm', 'nomic-embed', 'rerank'] as $skip) {
            if (str_contains($hay, $skip)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9._:/=+-]{1,200}$#', $name);
    }
}
