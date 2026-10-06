<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\LlmEndpoint;
use XArticlePdf\OllamaTranslator;

/**
 * Sedno problemu "Połączenie przerwane": przy `stream => false` przez cały czas generowania
 * fragmentu nie leciał ani jeden bajt, a ponawianie było zagnieżdżone i mnożyło ciszę.
 */
final class OllamaTranslatorLiveStreamTest extends TestCase
{
    private ?FakeLlmServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function translator(string $mode): OllamaTranslator
    {
        $this->server = new FakeLlmServer($mode);

        return new OllamaTranslator($this->server->baseUrl(), 'model', 10, 'Polish', LlmEndpoint::BACKEND_OLLAMA);
    }

    public function testActivityIsReportedWhileTheModelIsStillGenerating(): void
    {
        $ticks = [];
        $translator = $this->translator('slow');
        $translator->onActivity(static function (int $chars) use (&$ticks): void {
            $ticks[] = [microtime(true), $chars];
        });

        $start = microtime(true);
        $out = $translator->translate(['cokolwiek'], 'Polish');
        $end = microtime(true);

        $this->assertSame(['Ala ma kota'], $out);
        $this->assertGreaterThanOrEqual(2, count($ticks), 'Strumień musi dać sygnał życia więcej niż raz.');
        $this->assertLessThan(
            $end - 0.2,
            $ticks[0][0],
            'Pierwszy sygnał musi przyjść wyraźnie przed końcem generowania, nie po nim.',
        );
        $this->assertGreaterThan($ticks[0][1], $ticks[count($ticks) - 1][1], 'Licznik znaków ma rosnąć.');
        $this->assertGreaterThan(0.5, $end - $start, 'Atrapa generuje ~0,9 s — inaczej test nic nie sprawdza.');
    }

    public function testHeartbeatKeepsTicksComingWhileTheModelIsStillSilent(): void
    {
        $this->server = new FakeLlmServer('silent-then-fast');
        $translator = new OllamaTranslator(
            $this->server->baseUrl(),
            'model',
            10,
            'Polish',
            LlmEndpoint::BACKEND_OLLAMA,
            heartbeatSeconds: 1,
        );
        $ticks = [];
        $translator->onActivity(static function (int $chars) use (&$ticks): void {
            $ticks[] = [microtime(true), $chars];
        });

        $start = microtime(true);
        $out = $translator->translate(['cokolwiek'], 'Polish');

        $this->assertSame(['Ala ma kota'], $out);
        $silentTicks = array_filter($ticks, static fn (array $t): bool => $t[1] === 0);
        $this->assertNotEmpty(
            $silentTicks,
            'Przetwarzanie promptu też musi dawać sygnał życia — inaczej cisza zrywa strumień.',
        );
        $this->assertLessThan(
            $start + 1.9,
            array_values($silentTicks)[0][0],
            'Sygnał ma przyjść w trakcie ciszy, a nie dopiero z pierwszym tokenem.',
        );
    }

    public function testTotalIsKnownBeforeTheFirstFragmentFinishes(): void
    {
        $translator = $this->translator('failing');
        $seen = [];
        $translator->translate(['jeden', 'dwa', 'trzy'], 'Polish', static function (int $current, int $total) use (&$seen): void {
            $seen[] = [$current, $total];
        });

        $this->assertSame([0, 3], $seen[0] ?? null, 'Pasek postępu musi znać liczbę fragmentów od razu.');
    }

    public function testFailingFragmentIsNotRetriedTwelveTimes(): void
    {
        $translator = $this->translator('failing');

        $out = $translator->translate(['oryginał'], 'Polish');

        $this->assertSame(['oryginał'], $out, 'Nieudany fragment zostaje w oryginale.');
        $this->assertLessThanOrEqual(
            2,
            $this->server?->requestCount() ?? 99,
            'Jeden fragment to najwyżej dwa żądania — wcześniej zagnieżdżone pętle dawały dwanaście.',
        );
    }
}
