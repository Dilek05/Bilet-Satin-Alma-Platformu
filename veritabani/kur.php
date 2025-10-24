<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
ob_implicit_flush(true);

echo "== Kurulum baslatildi ==\n";

try {
    $pdo = vt_baglanti();
    echo "[OK] SQLite baglantisi acildi\n";

    $semaYol  = __DIR__ . '/sema.sql';
    $tohumYol = __DIR__ . '/tohum.sql';

    if (!file_exists($semaYol)) {
        throw new RuntimeException("sema.sql bulunamadi: $semaYol");
    }
    if (!file_exists($tohumYol)) {
        echo "[UYARI] tohum.sql bulunamadi, sadece sema yuklenecek\n";
    }

    $pdo->beginTransaction();

    $sema = file_get_contents($semaYol);
    $pdo->exec($sema);
    echo "[OK] sema.sql uygulandi\n";

    if (file_exists($tohumYol)) {
        $tohum = file_get_contents($tohumYol);
        if ($tohum && trim($tohum) !== '') {
            $pdo->exec($tohum);
            echo "[OK] tohum.sql uygulandi\n";
        } else {
            echo "[UYARI] tohum.sql bos, atlandi\n";
        }
    }

    $pdo->commit();
    echo "[OK] Veritabani kuruldu / guncellendi\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[HATA] " . $e->getMessage() . "\n";
    exit(1);
}

echo "== Kurulum bitti ==\n";
