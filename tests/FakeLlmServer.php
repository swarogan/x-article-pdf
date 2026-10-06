<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

/**
 * Atrapa serwera modeli na loopbacku. Testy strumienia muszą widzieć prawdziwe gniazdo,
 * bo sprawdzają, kiedy bajty docierają — nie da się tego udawać mockiem.
 */
final class FakeLlmServer
{
    /** @var resource */
    private $process;
    public readonly int $port;
    private readonly string $counterFile;

    public function __construct(string $mode = 'slow')
    {
        $this->port = self::freePort();
        $this->counterFile = tempnam(sys_get_temp_dir(), 'fakellm');
        file_put_contents($this->counterFile, '');
        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open(
            ['php', '-d', 'output_buffering=0', '-S', '127.0.0.1:' . $this->port, __DIR__ . '/fixtures/fake_llm.php'],
            $descriptors,
            $pipes,
            null,
            ['FAKE_LLM_MODE' => $mode, 'FAKE_LLM_COUNTER' => $this->counterFile, 'PATH' => getenv('PATH') ?: '/usr/bin'],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Nie udało się uruchomić atrapy serwera modeli.');
        }
        $this->process = $process;
        $this->waitUntilUp();
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function requestCount(): int
    {
        return strlen((string) file_get_contents($this->counterFile));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->counterFile);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException('Brak wolnego portu: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilUp(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('Atrapa serwera modeli nie wstała.');
    }
}
