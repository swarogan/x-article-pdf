<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\FetchException;
use XArticlePdf\LlmEndpoint;
use XArticlePdf\OllamaTranslator;

/**
 * Ładowanie modelu do VRAM trwa minutami. Przez ten czas przeglądarka musi dostawać
 * sygnał życia, inaczej strumień postępu zrywa się z ciszy.
 */
final class OllamaTranslatorWarmupTest extends TestCase
{
    private function deadServer(): OllamaTranslator
    {
        // port zamknięty na loopbacku: połączenie odrzucane natychmiast, bez czekania na sieć
        return new OllamaTranslator('http://127.0.0.1:9', 'model', 1, 'Polish', LlmEndpoint::BACKEND_OPENAI);
    }

    public function testEveryWarmupAttemptReportsProgress(): void
    {
        $seen = [];
        try {
            $this->deadServer()->warmup(static function (int $try, int $max) use (&$seen): void {
                $seen[] = [$try, $max];
            }, attempts: 4, attemptTimeout: 1, pauseSeconds: 0);
            $this->fail('Martwy serwer powinien zakończyć się wyjątkiem.');
        } catch (FetchException) {
            // oczekiwane
        }
        $this->assertCount(4, $seen);
        $this->assertSame([1, 4], $seen[0]);
        $this->assertSame([4, 4], $seen[3]);
    }

    public function testWarmupStopsImmediatelyWhenTheCallbackCancelsTheJob(): void
    {
        $calls = 0;
        $this->expectException(\XArticlePdf\JobCancelledException::class);
        $this->deadServer()->warmup(static function () use (&$calls): void {
            $calls++;
            throw new \XArticlePdf\JobCancelledException('Przerwano.');
        }, attempts: 30, attemptTimeout: 1, pauseSeconds: 0);
    }
}
