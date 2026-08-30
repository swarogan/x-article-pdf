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
            LlmEndpoint::backendFromPayload(['models' => [['name' => 'gemma4:e2b']]]),
        );
    }

    public function testRecognisesOpenAiModelsPayload(): void
    {
        $this->assertSame(
            LlmEndpoint::BACKEND_OPENAI,
            LlmEndpoint::backendFromPayload(['object' => 'list', 'data' => [['id' => 'gemma-3-4b', 'object' => 'model']]]),
        );
    }

    public function testUnknownPayloadHasNoBackend(): void
    {
        $this->assertNull(LlmEndpoint::backendFromPayload(['hello' => 'world']));
        $this->assertNull(LlmEndpoint::backendFromPayload([]));
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
