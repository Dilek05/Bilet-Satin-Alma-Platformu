<?php
/**
 * Aynı bağlantıyı her sefer yeniden açmakla uğraşmayalım diye,
 * tek bir PDO nesnesini saklayıp tekrar tekrar kullanıyoruz.
 */
function vt_baglanti(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dosya = __DIR__ . '/../bilet-satis.sqlite';

    $pdo = new PDO('sqlite:' . $dosya);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;'); // aynı anda birkaç kişi girince veritabanı huysuzlanmasın diye bu modu açıyoruz

    return $pdo;
}
