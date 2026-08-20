<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\FileArchive;

final class FileArchiveTest extends TestCase
{
    public function testSavesAndListsThenGetsFile(): void
    {
        $dir = sys_get_temp_dir() . '/xpdf-arch-' . bin2hex(random_bytes(4));
        $archive = new FileArchive($dir);
        $item = $archive->save("hello", 'a.md', 'text/markdown', 'Hello', 'https://x.com/a/status/1', 'md');
        $this->assertSame('a.md', $item['filename']);
        $list = $archive->list();
        $this->assertCount(1, $list);
        $this->assertSame($item['id'], $list[0]['id']);
        $got = $archive->get($item['id']);
        $this->assertNotNull($got);
        $this->assertSame('hello', file_get_contents($got['path']));
    }

    public function testDeletesOneItemAndLeavesTheRest(): void
    {
        $dir = sys_get_temp_dir() . '/xpdf-arch-' . bin2hex(random_bytes(4));
        $archive = new FileArchive($dir);
        $keep = $archive->save('keep', 'a.md', 'text/markdown', 'Keep', 'https://x.com/a/status/1', 'md');
        $drop = $archive->save('drop', 'b.pdf', 'application/pdf', 'Drop', 'https://x.com/b/status/2', 'pdf');
        $this->assertTrue($archive->delete($drop['id']));
        $this->assertFalse($archive->delete($drop['id']));
        $this->assertFalse($archive->delete('not-an-id'));
        $list = $archive->list();
        $this->assertCount(1, $list);
        $this->assertSame($keep['id'], $list[0]['id']);
        $this->assertNull($archive->get($drop['id']));
        $this->assertFileDoesNotExist($dir . '/' . $drop['id'] . '.bin');
        $this->assertFileExists($dir . '/' . $keep['id'] . '.bin');
    }
}
