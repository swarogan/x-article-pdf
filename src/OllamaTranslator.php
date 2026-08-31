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
    ) {
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
        foreach ($prompts as $prompt) {
            try {
                $out = trim($this->generate($prompt, 2048));
                if ($out !== '') {
                    return $out;
                }
            } catch (FetchException $e) {
                if ($this->isTransient($e->getMessage())) {
                    try {
                        $out = trim($this->generate($prompt, 2048));
                        if ($out !== '') {
                            return $out;
                        }
                    } catch (FetchException) {
                        continue;
                    }
                }
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

    private function generate(string $prompt, int $numPredict = 2048): string
    {
        $last = null;
        for ($try = 1; $try <= 3; $try++) {
            try {
                return $this->generateOnce($prompt, $numPredict);
            } catch (FetchException $e) {
                $last = $e;
                if ($try === 3 || !$this->isTransient($e->getMessage())) {
                    throw $e;
                }
                sleep($try * 2);
            }
        }
        throw $last ?? new FetchException('Tłumaczenie nie wyszło.');
    }

    private function generateOnce(string $prompt, int $numPredict, ?int $timeout = null): string
    {
        $openAi = $this->backend === LlmEndpoint::BACKEND_OPENAI;
        $payload = $openAi
            ? [
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => false,
                'max_tokens' => $numPredict,
                'temperature' => 0.1,
            ]
            : [
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
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
        $response = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($response === false) {
            throw new FetchException('Serwer modeli ładuje model albo nie odpowiada. Ponawiam…');
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new FetchException('Serwer modeli zwrócił niepoprawną odpowiedź.');
        }
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }
        if (is_string($error) && $error !== '') {
            throw new FetchException('Serwer modeli: ' . $error);
        }
        $content = self::contentFromPayload($decoded);
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
