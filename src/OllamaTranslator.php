<?php

declare(strict_types=1);

namespace XArticlePdf;

final class OllamaTranslator implements Translator
{
    private const MAX_SOURCE_CHARS = 1800;
    /** Ładowanie dużego modelu potrafi trwać kilka minut — stąd wiele krótkich prób. */
    private const WARMUP_ATTEMPTS = 40;
    private const WARMUP_ATTEMPT_TIMEOUT = 15;
    private const WARMUP_PAUSE = 2;

    public function __construct(
        private readonly string $baseUrl = 'http://127.0.0.1:11434',
        private readonly string $model = 'gemma4:e2b',
        private readonly int $timeoutSeconds = 180,
        private string $targetLanguage = 'Polish',
        private readonly string $backend = LlmEndpoint::BACKEND_OLLAMA,
        private readonly int $heartbeatSeconds = 5,
    ) {
    }

    /** @var (callable(int): void)|null */
    private $onActivity = null;

    /**
     * Sygnał życia w trakcie generowania fragmentu: dostaje liczbę znaków, które już przyszły.
     *
     * @param (callable(int): void)|null $onActivity
     */
    public function onActivity(?callable $onActivity): void
    {
        $this->onActivity = $onActivity;
    }

    public static function forEndpoint(LlmEndpoint $endpoint, ?string $model = null): self
    {
        $chosen = is_string($model) && $model !== ''
            ? $model
            : (getenv('OLLAMA_TRANSLATE_MODEL') ?: 'gemma4:e2b');

        return new self($endpoint->baseUrl, $chosen, 180, 'Polish', $endpoint->backend);
    }

    public static function fromEnvironment(?string $model = null): self
    {
        $host = getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434';
        $chosen = is_string($model) && $model !== ''
            ? $model
            : (getenv('OLLAMA_TRANSLATE_MODEL') ?: 'gemma4:e2b');

        return new self(rtrim($host, '/'), $chosen);
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * Czeka, aż serwer załaduje model. Każda próba jest krótka i zgłaszana przez $onTry —
     * długie żądanie bez żadnego sygnału zrywa strumień postępu w przeglądarce.
     *
     * @param (callable(int, int): void)|null $onTry
     */
    public function warmup(
        ?callable $onTry = null,
        int $attempts = self::WARMUP_ATTEMPTS,
        int $attemptTimeout = self::WARMUP_ATTEMPT_TIMEOUT,
        int $pauseSeconds = self::WARMUP_PAUSE,
    ): void {
        $last = null;
        for ($try = 1; $try <= $attempts; $try++) {
            if ($onTry !== null) {
                $onTry($try, $attempts);
            }
            try {
                $this->generateOnce('/no_think\nping', 8, $attemptTimeout);
                return;
            } catch (FetchException $e) {
                $last = $e;
                if (!$this->isTransient($e->getMessage()) && $try >= 2) {
                    throw $e;
                }
                if ($try < $attempts && $pauseSeconds > 0) {
                    sleep($pauseSeconds);
                }
            }
        }

        throw $last ?? new FetchException('Serwer modeli nie załadował modelu.');
    }

    public function translate(array $texts, string $targetLanguage, ?callable $onProgress = null): array
    {
        $this->targetLanguage = $targetLanguage !== '' ? $targetLanguage : 'Polish';
        $plan = [];
        $total = 0;
        foreach (array_values($texts) as $text) {
            $chunks = TextChunks::split($text, self::MAX_SOURCE_CHARS);
            if ($chunks === []) {
                $chunks = [$text];
            }
            $plan[] = $chunks;
            $total += count($chunks);
        }
        $done = 0;
        $out = [];
        if ($onProgress !== null) {
            // Pasek postępu ma znać liczbę fragmentów, zanim ruszy pierwszy — inaczej
            // przez cały pierwszy (najdłuższy w odczuciu) fragment pokazuje "1/1".
            $onProgress(0, max(1, $total));
        }
        foreach ($plan as $chunks) {
            $parts = [];
            foreach ($chunks as $chunk) {
                $parts[] = $this->translateOne($chunk);
                $done++;
                if ($onProgress !== null) {
                    $onProgress($done, max(1, $total));
                }
            }
            $out[] = count($parts) === 1 ? $parts[0] : implode("\n\n", $parts);
        }

        return $out;
    }

    private function translateOne(string $text): string
    {
        $text = $this->utf8($text);
        $lang = $this->targetLanguage;
        $prompts = [
            "/no_think\nTranslate into {$lang}. Keep HTML tags, markdown syntax, URLs, @handles and code unchanged. Return only the translation. Do not reason.\n\n" . $text,
            "/no_think\n{$lang} translation only:\n" . $text,
        ];
        // Jedna próba na wariant promptu. Ponawianie było wcześniej zagnieżdżone
        // (2 prompty × 3 próby × ewentualna powtórka = 12 żądań) i zamieniało jeden
        // niedziałający fragment w kilkadziesiąt minut ciszy w strumieniu postępu.
        foreach ($prompts as $prompt) {
            try {
                $out = trim($this->generateOnce($prompt, 2048));
                if ($out !== '') {
                    return $out;
                }
            } catch (FetchException) {
                continue;
            }
        }

        return $text;
    }

    private function utf8(string $text): string
    {
        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if (is_string($clean)) {
                return $clean;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $clean = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (is_string($clean)) {
                return $clean;
            }
        }

        return $text;
    }

    private function generateOnce(string $prompt, int $numPredict, ?int $timeout = null): string
    {
        $openAi = $this->backend === LlmEndpoint::BACKEND_OPENAI;
        $payload = $openAi
            ? [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => true,
                'max_tokens' => $numPredict,
                'temperature' => 0.1,
            ]
            : [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => true,
                'think' => false,
                'keep_alive' => '15m',
                'options' => [
                    'num_predict' => $numPredict,
                    'temperature' => 0.1,
                    'think' => false,
                ],
            ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new FetchException('Nie udało się przygotować żądania tłumaczenia.');
        }
        // Timeout dotyczy teraz ciszy między porcjami, nie całego generowania — długi,
        // ale żywy fragment nie jest już zrywany w połowie.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => $timeout ?? $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);
        $path = $openAi ? '/v1/chat/completions' : '/api/generate';
        $handle = @fopen($this->baseUrl . $path, 'r', false, $context);
        if (!is_resource($handle)) {
            throw new FetchException('Serwer modeli ładuje model albo nie odpowiada. Ponawiam…');
        }
        // Gniazdo budzi się co $heartbeatSeconds, żeby dać sygnał życia nawet wtedy, gdy model
        // dopiero przetwarza prompt i nie wysłał jeszcze ani jednego tokenu. Zerwanie następuje
        // dopiero po $maxSilence ciszy z rzędu.
        $maxSilence = $timeout ?? $this->timeoutSeconds;
        stream_set_timeout($handle, max(1, min($this->heartbeatSeconds, $maxSilence)));
        $content = '';
        $lastData = microtime(true);
        try {
            while (true) {
                $line = fgets($handle);
                if ($line === false) {
                    if (!(stream_get_meta_data($handle)['timed_out'] ?? false)) {
                        break;
                    }
                    if (microtime(true) - $lastData >= $maxSilence) {
                        throw new FetchException('Serwer modeli zamilkł w trakcie generowania. Ponawiam…');
                    }
                    if ($this->onActivity !== null) {
                        ($this->onActivity)(mb_strlen($content));
                    }
                    continue;
                }
                $lastData = microtime(true);
                $delta = self::deltaFromStreamLine($line);
                if ($delta === null) {
                    continue;
                }
                $content .= $delta;
                if ($this->onActivity !== null) {
                    ($this->onActivity)(mb_strlen($content));
                }
            }
        } finally {
            fclose($handle);
        }
        $content = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $content));
        if ($content === '') {
            throw new FetchException('Tłumaczenie nie wyszło: pusta odpowiedź.');
        }

        return $content;
    }

    private function isTransient(string $message): bool
    {
        $hay = strtolower($message);
        foreach ([
            'input stream',
            'load',
            'loading',
            'runner',
            'terminated',
            'busy',
            'connection',
            'timeout',
            'pusta',
            'empty',
            'ponawiam',
            'unavailable',
            'out of memory',
            'cuda',
            'kv cache',
        ] as $needle) {
            if (str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kawałek tekstu z jednej linii strumienia — NDJSON (Ollama) albo SSE (llama.cpp/OpenAI).
     * null oznacza linię bez treści: pustą, komentarz SSE albo znacznik końca.
     */
    public static function deltaFromStreamLine(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ':')) {
            return null;
        }
        if (str_starts_with($line, 'data:')) {
            $line = trim(substr($line, 5));
            if ($line === '' || $line === '[DONE]') {
                return null;
            }
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }
        if (is_string($error) && $error !== '') {
            throw new FetchException('Serwer modeli: ' . $error);
        }
        foreach ([
            $decoded['response'] ?? null,
            $decoded['message']['content'] ?? null,
            $decoded['choices'][0]['delta']['content'] ?? null,
            $decoded['choices'][0]['text'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function contentFromPayload(array $decoded): string
    {
        $candidates = [
            $decoded['response'] ?? null,
            $decoded['message']['content'] ?? null,
            $decoded['choices'][0]['message']['content'] ?? null,
            $decoded['choices'][0]['text'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $clean = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $candidate));
            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }
}
