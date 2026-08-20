<?php

declare(strict_types=1);

namespace XArticlePdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class PdfExporter
{
    public function __construct(private readonly string $tempDir)
    {
        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new FetchException('Nie można utworzyć katalogu tymczasowego PDF.');
        }
    }

    public function build(string $html): string
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 16,
            'margin_bottom' => 18,
            'tempDir' => $this->tempDir,
        ]);
        $mpdf->SetDisplayMode('fullwidth');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public static function filename(ArticleDocument $doc): string
    {
        $base = $doc->author->handle . '-' . $doc->id;
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'x-article';

        return $base . '.pdf';
    }
}
