<?php

declare(strict_types=1);

namespace XArticlePdf;

final class FileArchive
{
    private const MAX_ITEMS = 40;

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new FetchException('Nie można utworzyć archiwum plików.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function save(
        string $body,
        string $filename,
        string $mime,
        string $title,
        string $sourceUrl,
        string $format,
        ?ArticleDocument $source = null,
    ): array {
        $id = bin2hex(random_bytes(16));
        $item = [
            'id' => $id,
            'filename' => $filename,
            'mime' => $mime,
            'title' => $title !== '' ? $title : $filename,
            'source' => $sourceUrl,
            'format' => $format,
            'created' => time(),
            'bytes' => strlen($body),
        ];
        file_put_contents($this->path($id), $body);
        if ($source !== null) {
            file_put_contents(
                $this->sourcePath($id),
                json_encode($source->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            $item['hasSource'] = true;
        } else {
            $item['hasSource'] = false;
        }
        $list = $this->list();
        array_unshift($list, $item);
        $this->writeList(array_slice($list, 0, self::MAX_ITEMS));
        $this->prune($list);

        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $file = $this->directory . '/index.json';
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && is_file($this->path($row['id']))) {
                $row['hasSource'] = is_file($this->sourcePath($row['id']));
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{item: array<string, mixed>, path: string}|null
     */
    public function get(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }
        foreach ($this->list() as $item) {
            if (($item['id'] ?? '') === $id) {
                $path = $this->path($id);
                if (is_file($path)) {
                    return ['item' => $item, 'path' => $path];
                }
            }
        }

        return null;
    }

    public function source(string $id): ?ArticleDocument
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($this->sourcePath($id))) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->sourcePath($id)), true);
        if (!is_array($decoded)) {
            return null;
        }

        return ArticleDocument::fromArray($decoded);
    }

    public function delete(string $id): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return false;
        }
        $kept = [];
        $found = false;
        foreach ($this->list() as $item) {
            if (($item['id'] ?? '') === $id) {
                $found = true;
                continue;
            }
            $kept[] = $item;
        }
        $path = $this->path($id);
        $hadFile = is_file($path);
        $sourcePath = $this->sourcePath($id);
        $hadSource = is_file($sourcePath);
        if ($hadFile) {
            @unlink($path);
        }
        if ($hadSource) {
            @unlink($sourcePath);
        }
        if (!$found && !$hadFile) {
            return false;
        }
        $this->writeList($kept);

        return true;
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.bin';
    }

    private function sourcePath(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function writeList(array $list): void
    {
        file_put_contents(
            $this->directory . '/index.json',
            json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function prune(array $list): void
    {
        $keep = [];
        foreach (array_slice($list, 0, self::MAX_ITEMS) as $item) {
            if (isset($item['id']) && is_string($item['id'])) {
                $keep[$item['id']] = true;
            }
        }
        $files = scandir($this->directory);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $id = null;
            if (str_ends_with($file, '.bin')) {
                $id = substr($file, 0, -4);
            } elseif (str_ends_with($file, '.json') && $file !== 'index.json') {
                $id = substr($file, 0, -5);
            }
            if ($id === null || isset($keep[$id])) {
                continue;
            }
            @unlink($this->directory . '/' . $file);
        }
    }
}
