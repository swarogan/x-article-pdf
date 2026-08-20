<?php

declare(strict_types=1);

namespace XArticlePdf;

final class FxTwitterClient implements TwitterSource
{
    public function __construct(
        private readonly string $baseUrl = 'https://api.fxtwitter.com',
        private readonly int $timeoutSeconds = 25,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        return $this->getJson('/2/status/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function thread(string $id): array
    {
        return $this->getJson('/2/thread/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\nUser-Agent: x-article-pdf/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new FetchException('Nie udało się pobrać danych z X.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new FetchException('Odpowiedź z X nie jest poprawnym JSON-em.');
        }

        $code = (int) ($decoded['code'] ?? 0);
        if ($code === 404) {
            throw new FetchException('Nie znaleziono posta. Sprawdź link (musi prowadzić do statusu, nie samego /i/article/…).');
        }
        if ($code !== 200) {
            throw new FetchException('X zwróciło błąd HTTP ' . ($code !== 0 ? (string) $code : 'nieznany') . '.');
        }

        return $decoded;
    }
}
