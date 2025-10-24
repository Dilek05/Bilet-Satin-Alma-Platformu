<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

if ($kullanici) {
    redirect('/sayfalar/ana-sayfa.php');
}

$hatalar = [];
$email = trim($_POST['email'] ?? '');
$parola = $_POST['parola'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_token_kontrol($_POST['_token'] ?? null);
    } catch (Throwable $e) {
        $hatalar[] = $e->getMessage();
    }

    if (!$hatalar) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hatalar[] = 'Geçerli bir e-posta adresi girmeni istiyorum.';
        } else {
            $kayit = kullanici_getir_email($pdo, $email);
            if (!$kayit || !password_verify($parola, $kayit['password_hash'])) {
                $hatalar[] = 'E-posta ya da parola uyuşmadı.';
            }
        }

        if (!$hatalar && isset($kayit)) {
            unset($kayit['password_hash']);
            giris_yap($kayit);
            redirect('/sayfalar/ana-sayfa.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Giriş Yap</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <p class="back-home"><a href="/sayfalar/ana-sayfa.php">← Ana sayfaya dön</a></p>
  <div class="card">
    <h1>Tekrar hoş geldin!</h1>
    <p class="lead">Hesabını kullanarak biletlerini yönet ve yeni yolculuklar planla. Henüz üye değil misin?
      <a href="/sayfalar/kayit.php">Hemen kayıt ol</a>.
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
        E-posta
        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
      </label>
      <label>
        Parola
        <input type="password" name="parola" required>
      </label>
      <div class="actions">
        <button type="submit">Giriş Yap</button>
      </div>
    </form>
  </div>
</body>
</html>
