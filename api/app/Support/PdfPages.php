<?php

declare(strict_types=1);

namespace App\Support;

use Barryvdh\DomPDF\PDF;

/**
 * Renders a PDF and numbers its pages, "1 / 3", at the bottom right.
 */
final class PdfPages
{
    public static function render(PDF $pdf): string
    {
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        // A core font on purpose: digits and a slash need no Ethiopic face,
        // and the stamp stays plain text in the content stream.
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $size = 7;
        $text = '{PAGE_NUM} / {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('00 / 00', $font, $size);

        $canvas->page_text(
            $canvas->get_width() - 28 - $width,
            $canvas->get_height() - 22,
            $text,
            $font,
            $size,
            [0.392, 0.455, 0.545],
        );

        return $pdf->output();
    }
}
