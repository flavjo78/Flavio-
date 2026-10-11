<?php
/* =========================================================================
   PREDIZIONE — generatore PDF minimo (senza librerie esterne)
   -------------------------------------------------------------------------
   Basta per una ricevuta di una pagina: testo in Helvetica, righe posizionate.
   Font standard (non incorporato) -> codifica Latin-1: gli accenti vanno
   "appiattiti" (à->a) prima di scrivere, lo fa pred_pdf_ascii().
   ========================================================================= */

function pred_pdf_ascii(string $t): string {
    $map = ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','À'=>'A','È'=>'E','É'=>'E',
            'Ì'=>'I','Ò'=>'O','Ù'=>'U','’'=>"'",'‘'=>"'",'“'=>'"','”'=>'"','–'=>'-','—'=>'-','€'=>'EUR','·'=>'-'];
    $t = strtr($t, $map);
    // rimuove eventuali residui non-ASCII
    return preg_replace('/[^\x20-\x7E]/', '', $t);
}

class PredPDF {
    private array $lines = [];
    private float $pw = 595.28;   // A4 in punti
    private float $ph = 841.89;

    /** Aggiunge una riga di testo. $y conta dall'ALTO della pagina. */
    public function text(float $x, float $y, float $size, string $t, bool $bold = false): void {
        $this->lines[] = [$x, $this->ph - $y, $size, pred_pdf_ascii($t), $bold];
    }

    private function esc(string $t): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    }

    public function output(): string {
        $content = "";
        foreach ($this->lines as $l) {
            $font = $l[4] ? '/F2' : '/F1';
            $content .= "BT\n$font " . number_format($l[2], 1, '.', '') . " Tf\n"
                      . "1 0 0 1 " . number_format($l[0], 2, '.', '') . " " . number_format($l[1], 2, '.', '') . " Tm\n"
                      . "(" . $this->esc($l[3]) . ") Tj\nET\n";
        }
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                 . number_format($this->pw, 2, '.', '') . " " . number_format($this->ph, 2, '.', '')
                 . "] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>";
        $objs[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pdf = "%PDF-1.4\n";
        $off = [];
        for ($i = 1; $i <= 6; $i++) {
            $off[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        }
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    public function save(string $path): bool {
        return @file_put_contents($path, $this->output(), LOCK_EX) !== false;
    }
}
