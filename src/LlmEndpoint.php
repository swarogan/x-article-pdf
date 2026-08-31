<?php

declare(strict_types=1);

namespace XArticlePdf;

/**
 * Adres serwera modeli razem z wykrytym protokołem: Ollama (/api/*)
 * albo zgodny z OpenAI (/v1/*), którym mówi m.in. llama.cpp (llama-server).
 */
final readonly class LlmEndpoint
{
    public const BACKEND_OLLAMA = 'ollama';
    public const BACKEND_OPENAI = 'openai';

    private const PROBE_TIMEOUT = 3;

    public function __construct(
        public string $baseUrl,
        public string $backend,
    ) {
    }

    /**
     * Pierwszy adres, który odpowiada znanym protokołem.
     */
    public static function detect(?string $manualHost = null): ?self
    {
        foreach (self::candidates($manualHost) as $baseUrl) {
            $backend = self::probe($baseUrl);
            if ($backend !== null) {
                return new self($baseUrl, $backend);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function candidates(?string $manualHost = null): array
    {
        $manual = is_string($manualHost) ? self::normalizeUrl($manualHost) : null;
        if ($manual !== null) {
            return self::withDefaultPorts($manual);
        }
        $raw = [
            getenv('LLM_HOST') ?: null,
            getenv('OLLAMA_HOST') ?: null,
            'http://127.0.0.1:11434',
            'http://127.0.0.1:8080',
        ];
        $out = [];
        foreach ($raw as $candidate) {
            $url = is_string($candidate) ? self::normalizeUrl($candidate) : null;
            if ($url !== null && !in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Sam adres bez portu: najpierw port Ollamy, potem llama.cpp, na końcu adres tak jak podany
     * (gdyby serwer stał za proxy na porcie domyślnym).
     *
     * @return list<string>
     */
    private static function withDefaultPorts(string $url): array
    {
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['port'])) {
            return [$url];
        }

        return [$url . ':11434', $url . ':8080', $url];
    }

    public static function normalizeUrl(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'http://' . $input;
        }
        $parts = parse_url(rtrim($input, '/'));
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $isName = (bool) preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host);
        $isIpv6 = (bool) preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $host);
        if (!in_array($scheme, ['http', 'https'], true) || (!$isName && !$isIpv6)) {
            return null;
        }
        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        return $url;
    }

    /**
     * Protokół wynika ze ścieżki, która odpowiedziała listą modeli. llama-server podaje
     * na /v1/models zarówno 'data', jak i ollamowe 'models', więc kolejność kluczy nic nie mówi.
     *
     * @param array<string, mixed> $payload
     */
    public static function backendForPath(string $path, array $payload): ?string
    {
        if ($path === '/api/tags') {
            return isset($payload['models']) && is_array($payload['models']) ? self::BACKEND_OLLAMA : null;
        }
        if ($path === '/v1/models') {
            return isset($payload['data']) && is_array($payload['data']) ? self::BACKEND_OPENAI : null;
        }

        return null;
    }

    private static function probe(string $baseUrl): ?string
    {
        foreach (['/api/tags', '/v1/models'] as $path) {
            $payload = self::getJson($baseUrl . $path);
            $backend = $payload === null ? null : self::backendForPath($path, $payload);
            if ($backend !== null) {
                return $backend;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getJson(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::PROBE_TIMEOUT,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
