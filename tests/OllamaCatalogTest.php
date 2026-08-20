<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\OllamaCatalog;

final class OllamaCatalogTest extends TestCase
{
    public function testParsesAndSortsModelNames(): void
    {
        $names = OllamaCatalog::namesFromTags([
            'models' => [
                ['name' => 'bge-m3:latest'],
                ['name' => 'qwen3.6:35b'],
                ['name' => 'hf.co/tencent/Hy-MT2-7B-GGUF:Q4_K_M'],
                ['model' => 'gemma4:12b'],
                ['name' => 'qwen3.6:35b'],
            ],
        ]);
        $this->assertSame([
            'gemma4:12b',
            'hf.co/tencent/Hy-MT2-7B-GGUF:Q4_K_M',
            'qwen3.6:35b',
        ], $names);
    }

    public function testReadsThinkingAndChatPayloads(): void
    {
        $this->assertSame(
            '',
            \XArticlePdf\OllamaTranslator::contentFromPayload(['response' => '', 'thinking' => 'Cześć']),
        );
        $this->assertSame(
            'Hej',
            \XArticlePdf\OllamaTranslator::contentFromPayload(['response' => "<think>hmm</think>\nHej"]),
        );
        $this->assertSame('', \XArticlePdf\OllamaTranslator::contentFromPayload(['response' => '']));
    }

    public function testValidatesModelNames(): void
    {
        $this->assertTrue(OllamaCatalog::isValidName('qwen3.6:35b'));
        $this->assertTrue(OllamaCatalog::isValidName('hf.co/tencent/Hy-MT2-7B-GGUF:Q4_K_M'));
        $this->assertFalse(OllamaCatalog::isValidName(''));
        $this->assertFalse(OllamaCatalog::isValidName("bad\nname"));
    }
}
