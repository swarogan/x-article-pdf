<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\JobControl;

final class JobControlTest extends TestCase
{
    private function control(): JobControl
    {
        return new JobControl(sys_get_temp_dir() . '/xpdf-jobs-' . bin2hex(random_bytes(4)));
    }

    public function testFreshJobIsNotCancelled(): void
    {
        $this->assertFalse($this->control()->isCancelled('a1b2c3d4e5f60718'));
    }

    public function testCancelIsVisibleToAnotherProcessReadingTheSameDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/xpdf-jobs-' . bin2hex(random_bytes(4));
        (new JobControl($dir))->cancel('a1b2c3d4e5f60718');
        $this->assertTrue((new JobControl($dir))->isCancelled('a1b2c3d4e5f60718'));
    }

    public function testCancelTouchesOnlyTheGivenJob(): void
    {
        $control = $this->control();
        $control->cancel('a1b2c3d4e5f60718');
        $this->assertFalse($control->isCancelled('ffffffffffffffff'));
    }

    public function testClearRemovesTheSignalSoTheIdCanBeReused(): void
    {
        $control = $this->control();
        $control->cancel('a1b2c3d4e5f60718');
        $control->clear('a1b2c3d4e5f60718');
        $this->assertFalse($control->isCancelled('a1b2c3d4e5f60718'));
    }

    public function testRejectsIdsThatCouldEscapeTheDirectory(): void
    {
        $control = $this->control();
        foreach (['', '../../etc/passwd', 'ab', 'ZZZZ', 'a1b2/c3d4'] as $bad) {
            $this->assertFalse(JobControl::isValidId($bad), $bad);
            $control->cancel($bad);
            $this->assertFalse($control->isCancelled($bad), $bad);
        }
    }

    public function testAcceptsHexIdsOfTheLengthTheClientGenerates(): void
    {
        $this->assertTrue(JobControl::isValidId(bin2hex(random_bytes(8))));
        $this->assertTrue(JobControl::isValidId(bin2hex(random_bytes(16))));
    }
}
