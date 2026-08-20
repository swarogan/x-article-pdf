<?php

declare(strict_types=1);

namespace XArticlePdf;

final class TextChunks
{
    /**
     * @return list<string>
     */
    public static function split(string $text, int $maxChars = 3500): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if ($maxChars < 32) {
            $maxChars = 32;
        }
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $parts = [];
        $rest = $text;
        while (mb_strlen($rest) > $maxChars) {
            $slice = mb_substr($rest, 0, $maxChars);
            $break = self::breakAt($slice, $maxChars);
            $parts[] = trim(mb_substr($rest, 0, $break));
            $rest = trim(mb_substr($rest, $break));
        }
        if ($rest !== '') {
            $parts[] = $rest;
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function breakAt(string $slice, int $maxChars): int
    {
        $min = (int) floor($maxChars * 0.35);
        foreach (["\n\n", "\n", '. ', '? ', '! ', '; ', ', '] as $needle) {
            $pos = mb_strrpos($slice, $needle);
            if ($pos !== false && $pos >= $min) {
                return $pos + mb_strlen($needle);
            }
        }

        return $maxChars;
    }
}
