<?php

/**
 * Ufak tefek PDF çıktısı lazımsa satırları toparlayıp tek seferde burada dosyaya basıyoruz.
 *
 * @param string $dosyaTamYol PDF'in kaydedileceği tam yol
 * @param array<int,string> $satirlar PDF'e yazacağımız satırlar
 */
function bilet_pdf_olustur(string $dosyaTamYol, array $satirlar): void
{
    $satirlar = array_map(static function ($satir) {
        $metin = (string) $satir;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ISO-8859-9//TRANSLIT', $metin);
            if ($converted !== false) {
                $metin = $converted;
            }
        }
        $metin = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $metin);
        return $metin;
    }, $satirlar);

    $icerikSatirlari = [
        'BT',
        '/F1 12 Tf',
        '16 TL',
        '1 0 0 1 72 800 Tm',
    ];

    $ilkSatir = true;
    foreach ($satirlar as $satir) {
        if ($ilkSatir) {
            $ilkSatir = false;
        } else {
            $icerikSatirlari[] = 'T*'; // hadi yeni satıra inelim
        }
        $icerikSatirlari[] = '(' . $satir . ') Tj';
    }
    $icerikSatirlari[] = 'ET';

    $icerik = implode("\n", $icerikSatirlari) . "\n";
    $icerikUzunluk = strlen($icerik);

    $nesneler = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        "<< /Length {$icerikUzunluk} >>\nstream\n{$icerik}endstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsetler = [0];

    foreach ($nesneler as $index => $icerikNesnesi) {
        $offsetler[] = strlen($pdf);
        $objNo = $index + 1;
        $pdf .= $objNo . " 0 obj\n" . $icerikNesnesi . "\nendobj\n";
    }

    $xrefBaslangic = strlen($pdf);
    $pdf .= "xref\n0 " . (count($nesneler) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach (array_slice($offsetler, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer << /Size " . (count($nesneler) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefBaslangic}\n%%EOF";

    file_put_contents($dosyaTamYol, $pdf);
}
