<?php

declare(strict_types=1);

namespace XArticlePdf;

class MediaStore
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new FetchException('Nie można utworzyć katalogu na obrazki.');
        }
    }

    public function localPath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }
        foreach ($this->urlVariants($url) as $candidate) {
            if (!$this->isAllowedHost($candidate)) {
                continue;
            }
            $ext = $this->guessExtension($candidate);
            if ($ext === '.webp') {
                $ext = '.jpg';
            }
            $path = $this->directory . '/' . hash('sha256', $candidate) . $ext;
            if (!is_file($path)) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 20,
                        'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) x-article-pdf/1.0\r\n",
                        'follow_location' => 1,
                        'ignore_errors' => true,
                    ],
                ]);
                $data = @file_get_contents($candidate, false, $context);
                if ($data === false || $data === '') {
                    continue;
                }
                file_put_contents($path, $data);
            }
            $this->cache[$url] = $path;

            return $path;
        }

        return null;
    }

    private function isAllowedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if ($host === '') {
            return false;
        }
        if (str_ends_with($host, '.twimg.com') || $host === 'pbs.x.com') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return !in_array($host, ['localhost', 'localhost.localdomain'], true);
    }

    private function guessExtension(string $url): string
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $format = strtolower((string) ($query['format'] ?? ''));
        if (in_array($format, ['png', 'webp', 'gif', 'jpg', 'jpeg'], true)) {
            return $format === 'jpeg' ? '.jpg' : '.' . $format;
        }
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (['.png', '.webp', '.gif', '.jpeg', '.jpg'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return $ext === '.jpeg' ? '.jpg' : $ext;
            }
        }

        return '.jpg';
    }

    /**
     * @return list<string>
     */
    private function urlVariants(string $url): array
    {
        $out = [$url];
        $jpg = preg_replace('/format=webp/i', 'format=jpg', $url);
        if (is_string($jpg) && $jpg !== $url) {
            $out[] = $jpg;
        }
        if (str_contains($url, 'pbs.twimg.com')) {
            if (preg_match('/name=/', $url)) {
                $medium = preg_replace('/name=[^&]+/', 'name=medium', $url);
                if (is_string($medium) && $medium !== $url) {
                    array_unshift($out, $medium);
                }
            } else {
                $join = str_contains($url, '?') ? '&' : '?';
                array_unshift($out, $url . $join . 'name=medium');
                $out[] = $url . $join . 'name=orig';
            }
        }

        return array_values(array_unique($out));
    }
}
