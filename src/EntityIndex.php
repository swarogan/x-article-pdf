<?php

declare(strict_types=1);

namespace XArticlePdf;

final class EntityIndex
{
    /**
     * @param array<string, array<string, mixed>> $byName
     * @param array<int, array<string, mixed>> $byIndex
     */
    public function __construct(
        private readonly array $byName,
        private readonly array $byIndex,
        private readonly bool $preferIndex,
    ) {
    }

    public static function from(mixed $raw): self
    {
        $byName = [];
        $byIndex = [];
        if (!is_array($raw)) {
            return new self([], [], false);
        }

        $isList = array_is_list($raw);
        $hasDraftKeys = false;
        $i = 0;
        foreach ($raw as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = is_array($entry['value'] ?? null) ? $entry['value'] : $entry;
            if (!is_array($value) || !isset($value['type'])) {
                continue;
            }
            $byIndex[$i] = $value;
            if (array_key_exists('key', $entry) && $entry['key'] !== null && $entry['key'] !== '') {
                $byName[(string) $entry['key']] = $value;
                $hasDraftKeys = true;
            } else {
                $byName[(string) $key] = $value;
            }
            $i++;
        }

        // {key,value}[] keeps Draft.js keys; entityRanges.key is that key, not list index.
        return new self($byName, $byIndex, $isList && !$hasDraftKeys);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(mixed $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $name = (string) $key;
        $index = is_int($key) || (is_string($key) && ctype_digit($key)) ? (int) $key : null;
        if ($this->preferIndex) {
            if ($index !== null && isset($this->byIndex[$index])) {
                return $this->byIndex[$index];
            }

            return $this->byName[$name] ?? null;
        }

        if (isset($this->byName[$name])) {
            return $this->byName[$name];
        }
        if ($index !== null) {
            return $this->byIndex[$index] ?? null;
        }

        return null;
    }
}
