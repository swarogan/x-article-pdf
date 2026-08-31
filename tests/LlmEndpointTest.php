<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\LlmEndpoint;

final class LlmEndpointTest extends TestCase
{
    public function testNormalizesHostWithoutScheme(): void
    {
        $this->assertSame('http://192.168.1.110:8080', LlmEndpoint::normalizeUrl('192.168.1.110:8080'));
        $this->assertSame('http://192.168.1.110:8080', LlmEndpoint::normalizeUrl(' 192.168.1.110:8080/ '));
        $this->assertSame('https://llm.lan:8443', LlmEndpoint::normalizeUrl('https://llm.lan:8443'));
        $this->assertSame('http://127.0.0.1:11434', LlmEndpoint::normalizeUrl('http://127.0.0.1:11434/'));
    }

    public function testAssumesDefaultPortWhenOnlyHostGiven(): void
    {
        $this->assertSame('http://llm.lan', LlmEndpoint::normalizeUrl('llm.lan'));
    }

    public function testRejectsGarbageAndNonHttpSchemes(): void
    {
        $this->assertNull(LlmEndpoint::normalizeUrl(''));
        $this->assertNull(LlmEndpoint::normalizeUrl('file:///etc/passwd'));
        $this->assertNull(LlmEndpoint::normalizeUrl('http://'));
        $this->assertNull(LlmEndpoint::normalizeUrl('ala ma kota'));
    }

    public function testRecognisesOllamaTagsPayload(): void
    {
        $this->assertSame(
            LlmEndpoint::BACKEND_OLLAMA,
            LlmEndpoint::backendForPath('/api/tags', ['models' => [['name' => 'gemma4:e2b']]]),
        );
    }

    public function testRecognisesOpenAiModelsPayload(): void
    {
        $this->assertSame(
            LlmEndpoint::BACKEND_OPENAI,
            LlmEndpoint::backendForPath('/v1/models', ['object' => 'list', 'data' => [['id' => 'gemma-3-4b']]]),
        );
    }

    /**
     * llama-server zwraca na /v1/models zarówno 'data', jak i ollamowe 'models'.
     * O protokole decyduje ścieżka, która odpowiedziała, a nie kolejność kluczy.
     */
    public function testLlamaCppHybridPayloadIsRecognisedAsOpenAi(): void
    {
        $payload = [
            'models' => [['name' => '/mnt/models/gemma-4-12B.gguf', 'model' => '/mnt/models/gemma-4-12B.gguf']],
            'object' => 'list',
            'data' => [['id' => '/mnt/models/gemma-4-12B.gguf', 'object' => 'model']],
        ];
        $this->assertSame(LlmEndpoint::BACKEND_OPENAI, LlmEndpoint::backendForPath('/v1/models', $payload));
    }

    public function testNotFoundBodyOnTagsIsNotAnOllamaServer(): void
    {
        $this->assertNull(LlmEndpoint::backendForPath('/api/tags', [
            'error' => ['message' => 'File Not Found', 'code' => 404],
        ]));
    }

    public function testUnknownPayloadHasNoBackend(): void
    {
        $this->assertNull(LlmEndpoint::backendForPath('/api/tags', ['hello' => 'world']));
        $this->assertNull(LlmEndpoint::backendForPath('/v1/models', []));
    }

    public function testManualHostIsTheOnlyCandidateSoNothingElseAnswersInItsPlace(): void
    {
        $this->assertSame(['http://192.168.1.110:8080'], LlmEndpoint::candidates('192.168.1.110:8080'));
    }

    public function testWithoutManualHostLocalDefaultsAreProbed(): void
    {
        $candidates = LlmEndpoint::candidates(null);
        $this->assertContains('http://127.0.0.1:11434', $candidates);
        $this->assertContains('http://127.0.0.1:8080', $candidates);
        $this->assertSame(array_values(array_unique($candidates)), $candidates);
    }

    public function testInvalidManualHostFallsBackToLocalDefaults(): void
    {
        $candidates = LlmEndpoint::candidates('ala ma kota');
        $this->assertNotContains('ala ma kota', $candidates);
        $this->assertContains('http://127.0.0.1:11434', $candidates);
    }
}
