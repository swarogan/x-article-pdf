<?php

declare(strict_types=1);

namespace XArticlePdf;

final class FileName
{
    private const MAX_LENGTH = 80;

    private const POLISH = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
    ];

    /**
     * Nazwa pliku bez rozszerzenia: slug z tytułu, ASCII, bezpieczny w nagłówku
     * Content-Disposition. Gdy z tytułu nie zostaje ani jedna litera łacińska
     * (np. same znaki CJK), zostaje stara nazwa handle-id.
     */
    public static function base(ArticleDocument $doc): string
    {
        $slug = self::slug($doc->title);
        if ($slug !== '') {
            return $slug;
        }
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $doc->author->handle . '-' . $doc->id);

        return $fallback ?? 'x-article';
    }

    private static function slug(string $title): string
    {
        $ascii = strtolower(strtr($title, self::POLISH));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        if (strlen($slug) <= self::MAX_LENGTH) {
            return $slug;
        }
        $cut = substr($slug, 0, self::MAX_LENGTH);
        // nie zostawiaj połówki słowa: tnij po ostatnim myślniku, jeśli slug się na nim nie kończył
        if ($slug[self::MAX_LENGTH] !== '-') {
            $lastHyphen = strrpos($cut, '-');
            if ($lastHyphen !== false) {
                $cut = substr($cut, 0, $lastHyphen);
            }
        }

        return trim($cut, '-');
    }
}
