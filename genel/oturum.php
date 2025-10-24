<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';

function oturum_baslat(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function giris_yap(array $kullanici): void
{
    oturum_baslat();
    $_SESSION['kullanici_id'] = $kullanici['id'];
    $_SESSION['aktif_kullanici'] = $kullanici;
    // giriş yapınca CSRF jetonunu da tazeleyelim ki eski sayfalardan post gelmesin
    unset($_SESSION['_csrf_token'], $_SESSION['_csrf_token_time']);
}

function cikis_yap(): void
{
    oturum_baslat();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function aktif_kullanici(PDO $pdo): ?array
{
    oturum_baslat();

    if (!isset($_SESSION['kullanici_id'])) {
        return null;
    }

    if (isset($_SESSION['aktif_kullanici']) && $_SESSION['aktif_kullanici']['id'] === $_SESSION['kullanici_id']) {
        return $_SESSION['aktif_kullanici'];
    }

    $st = $pdo->prepare('SELECT id, full_name, email, role, company_id, balance_kurus FROM user WHERE id = :id');
    $st->execute([':id' => $_SESSION['kullanici_id']]);
    $kullanici = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$kullanici) {
        unset($_SESSION['kullanici_id'], $_SESSION['aktif_kullanici']);
        return null;
    }

    $_SESSION['aktif_kullanici'] = $kullanici;
    return $kullanici;
}

function admin_mi(?array $kullanici): bool
{
    return $kullanici !== null && strtoupper($kullanici['role']) === 'ADMIN';
}

function firma_admin_mi(?array $kullanici): bool
{
    return $kullanici !== null && strtoupper($kullanici['role']) === 'FIRMA_ADMIN';
}

function yolcu_mu(?array $kullanici): bool
{
    return $kullanici !== null && strtoupper($kullanici['role']) === 'USER';
}

/**
 * Formlar için kısa ömürlü bir CSRF jetonu üretip saklıyoruz.
 */
function csrf_token_uret(): string
{
    oturum_baslat();

    $token = $_SESSION['_csrf_token'] ?? null;
    $zaman = $_SESSION['_csrf_token_time'] ?? 0;

    if (!$token || (time() - $zaman) > 1800) { // 30 dakikada bir yenile
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['_csrf_token_time'] = time();
    }

    return $token;
}

/**
 * Formlara gizli input olarak eklemek için ufak bir yardımcı.
 */
function csrf_token_form(): string
{
    $token = csrf_token_uret();
    return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Gelen istekteki CSRF jetonunu doğrular; aksi halde istisna fırlatır.
 */
function csrf_token_kontrol(?string $token): void
{
    oturum_baslat();
    $beklenen = $_SESSION['_csrf_token'] ?? null;
    if (!$token || !$beklenen || !hash_equals($beklenen, $token)) {
        throw new RuntimeException('Güvenlik jetonu geçersiz görünüyor. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
}

/**
 * Basit anahtarların (UUID ya da kısa kodların) güvenli formatta olup olmadığını kontrol eder.
 * Varsayılan verilerdeki `trp-1` gibi kısa tanımlara da izin verebilmek için 3-60 arası uzunluğu kabul ediyoruz.
 */
function guvenli_id(string $deger, string $hataMesaji = 'Geçersiz kayıt anahtarı'): string
{
    $deger = trim($deger);
    if ($deger === '' || !preg_match('/^[a-z0-9-]{3,60}$/i', $deger)) {
        throw new RuntimeException($hataMesaji);
    }
    return $deger;
}

function kullanici_getir_email(PDO $pdo, string $email): ?array
{
    $normEmail = strtolower(trim($email));
    $st = $pdo->prepare('SELECT id, full_name, email, password_hash, role, company_id, balance_kurus FROM user WHERE email = :email');
    $st->execute([':email' => $normEmail]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kullanici_kaydet(PDO $pdo, string $adSoyad, string $email, string $parola, string $rol = 'USER', ?string $companyId = null): array
{
    $normEmail = strtolower(trim($email));
    $id = uuid_v4();
    $hash = password_hash($parola, PASSWORD_BCRYPT);

    $st = $pdo->prepare('INSERT INTO user (id, full_name, email, password_hash, role, company_id) VALUES (:id, :full_name, :email, :hash, :role, :company_id)');
    $st->execute([
        ':id' => $id,
        ':full_name' => $adSoyad,
        ':email' => $normEmail,
        ':hash' => $hash,
        ':role' => $rol,
        ':company_id' => $companyId,
    ]);

    $veriSt = $pdo->prepare('SELECT id, full_name, email, role, company_id, balance_kurus FROM user WHERE id = :id');
    $veriSt->execute([':id' => $id]);
    return $veriSt->fetch(PDO::FETCH_ASSOC);
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}
