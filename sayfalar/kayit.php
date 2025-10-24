<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

if ($kullanici) {
    redirect('/sayfalar/ana-sayfa.php');
}

$hatalar = [];
$adSoyad = trim($_POST['ad_soyad'] ?? '');
$email = trim($_POST['email'] ?? '');
$parola = $_POST['parola'] ?? '';
$parolaTekrar = $_POST['parola_tekrar'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_token_kontrol($_POST['_token'] ?? null);
    } catch (Throwable $e) {
        $hatalar[] = $e->getMessage();
    }

    if (!$hatalar) {
        if ($adSoyad === '') {
            $hatalar[] = 'Ad soyad kısmını boş bırakamazsın.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hatalar[] = 'Geçerli bir e-posta adresi girmelisin.';
        } elseif (kullanici_getir_email($pdo, $email)) {
            $hatalar[] = 'Bu e-posta ile daha önce kayıt olunmuş.';
        }

        if (strlen($parola) < 6) {
            $hatalar[] = 'Parolan minimum 6 karakter olsun ki biraz güvenli olsun.';
        }

        if ($parola !== $parolaTekrar) {
            $hatalar[] = 'Parolalar birbiriyle aynı değil.';
        }

        if (!$hatalar) {
            $yeniKullanici = kullanici_kaydet($pdo, $adSoyad, $email, $parola);
            giris_yap($yeniKullanici);
            redirect('/sayfalar/ana-sayfa.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Kayıt Ol</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <p class="back-home"><a href="/sayfalar/ana-sayfa.php">← Ana sayfaya dön</a></p>
  <div class="card">
    <h1>Aramıza katıl</h1>
    <p class="lead">Dakikalar içinde hesap oluşturup yolculuk planına başlayabilirsin. Zaten hesabın var mı?
      <a href="/sayfalar/giris.php">Giriş yap</a>.
    </p>

    <?php if ($hatalar): ?>
      <div class="hata">
        <ul>
          <?php foreach ($hatalar as $hata): ?>
            <li><?= htmlspecialchars($hata) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" class="stack-form">
      <?= csrf_token_form(); ?>
      <label>
        Ad Soyad
        <input type="text" name="ad_soyad" value="<?= htmlspecialchars($adSoyad) ?>" required>
      </label>
      <label>
        E-posta
        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
      </label>
      <label>
        Parola
        <input type="password" name="parola" required minlength="6">
      </label>
      <label>
        Parola (tekrar)
        <input type="password" name="parola_tekrar" required minlength="6">
      </label>
      <div class="actions">
        <button type="submit">Kayıt Ol</button>
      </div>
    </form>
  </div>
</body>
</html>
