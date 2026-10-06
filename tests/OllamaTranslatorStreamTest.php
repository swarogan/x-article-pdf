<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\OllamaTranslator;

/**
 * Model woła się strumieniowo, żeby przeglądarka dostawała sygnał życia w trakcie
 * generowania fragmentu, a nie dopiero po nim.
 */
final class OllamaTranslatorStreamTest extends TestCase
{
    public function testReadsDeltaFromOllamaLine(): void
    {
        $this->assertSame('Ala ', OllamaTranslator::deltaFromStreamLine('{"response":"Ala ","done":false}'));
    }

    public function testReadsDeltaFromOpenAiServerSentEvent(): void
    {
        $line = 'data: {"choices":[{"delta":{"content":"ma kota"}}]}';
        $this->assertSame('ma kota', OllamaTranslator::deltaFromStreamLine($line));
    }

    public function testIgnoresKeepAliveAndDoneMarkers(): void
    {
        $this->assertNull(OllamaTranslator::deltaFromStreamLine(''));
        $this->assertNull(OllamaTranslator::deltaFromStreamLine('data: [DONE]'));
        $this->assertNull(OllamaTranslator::deltaFromStreamLine(': ping'));
    }

    public function testReportsServerErrorSentMidStream(): void
    {
        $this->expectException(\XArticlePdf\FetchException::class);
        $this->expectExceptionMessageMatches('/out of memory/i');
        OllamaTranslator::deltaFromStreamLine('{"error":"model requires more system memory, out of memory"}');
    }
}
