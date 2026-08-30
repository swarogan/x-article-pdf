<?php

declare(strict_types=1);

namespace XArticlePdf;

final class InlineHtml
{
    public static function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#</?(html|head|body|script|style|iframe)(?:\s[^>]*)?>#i', '', $html) ?? $html;
        $html = preg_replace('/<[^>]*$/u', '', $html) ?? $html;
        $out = preg_replace_callback(
            '/<\/?[a-zA-Z][^>]*>|[^<]+|</u',
            static function (array $m): string {
                $part = $m[0];
                if ($part === '' || $part[0] !== '<' || $part === '<') {
                    return htmlspecialchars(
                        html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8',
                    );
                }
                if (preg_match('/^<br\s*\/?>$/i', $part)) {
                    return '<br />';
                }
                if (preg_match('/^<\/(a|strong|em|b|i|u|s)>$/i', $part, $tm)) {
                    return '</' . self::tag($tm[1]) . '>';
                }
                if (preg_match('/^<(a|strong|em|b|i|u|s)(\s[^>]*)?>$/i', $part, $tm)) {
                    $tag = self::tag($tm[1]);
                    if ($tag !== 'a') {
                        return '<' . $tag . '>';
                    }
                    $href = '';
                    if (preg_match('/\bhref\s*=\s*"([^"]*)"/i', $part, $hm)
                        || preg_match("/\bhref\s*=\s*'([^']*)'/i", $part, $hm)) {
                        $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    if ($href === '' || !preg_match('/^(https?:|mailto:|#)/i', $href)) {
                        return '<a>';
                    }

                    return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                }

                return '';
            },
            $html,
        );
        if (!is_string($out)) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return self::closeTags($out);
    }

    private static function tag(string $name): string
    {
        $name = strtolower($name);

        return match ($name) {
            'b' => 'strong',
            'i' => 'em',
            default => $name,
        };
    }

    private static function closeTags(string $html): string
    {
        $open = [];
        if (preg_match_all('#</?(a|strong|em|u|s)\b[^>]*>#i', $html, $matches, PREG_SET_ORDER) === false) {
            return $html;
        }
        foreach ($matches as $match) {
            $name = self::tag($match[1]);
            if (str_starts_with($match[0], '</')) {
                for ($i = count($open) - 1; $i >= 0; $i--) {
                    if ($open[$i] === $name) {
                        array_splice($open, $i, 1);
                        break;
                    }
                }
                continue;
            }
            $open[] = $name;
        }
        while ($open !== []) {
            $html .= '</' . array_pop($open) . '>';
        }

        return $html;
    }
}
