<?php

declare(strict_types=1);

namespace XArticlePdf;

final class XUrlParser
{
    public static function parse(string $raw): ?ParsedXUrl
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (!str_contains($raw, '://')) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $allowed = [
            'x.com',
            'twitter.com',
            'mobile.twitter.com',
            'fxtwitter.com',
            'fixupx.com',
            'vxtwitter.com',
        ];
        if (!in_array($host, $allowed, true)) {
            return null;
        }

        $path = $parts['path'];

        if (preg_match('#/(?:i/web/status|i/status|status)/(\d+)#', $path, $m)) {
            $handle = null;
            if (preg_match('#^/([^/]+)/status/\d+#', $path, $h) && !in_array($h[1], ['i', 'intent'], true)) {
                $handle = $h[1];
            }

            return new ParsedXUrl('status', $m[1], $handle);
        }

        if (preg_match('#/i/article/(\d+)#', $path, $m)) {
            return new ParsedXUrl('article', $m[1]);
        }

        return null;
    }
}
