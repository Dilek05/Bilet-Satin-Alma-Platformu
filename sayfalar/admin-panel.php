<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

if (!$kullanici || !admin_mi($kullanici)) {
    redirect('/sayfalar/giris.php');
}

$hatalar = [];
$basarilar = [];

function tarih_input_format(?string $deger): string
{
    if (!$deger) {
        return '';
    }
    try {
        return (new DateTimeImmutable($deger))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';

    try {
        if ($islem === 'firma_ekle') {
            $ad = trim($_POST['firma_ad'] ?? '');
            $logo = trim($_POST['logo_yol'] ?? '');
            if ($ad === '') {
                throw new RuntimeException('Firma adı boş bırakılamaz.');
            }
            $ekle = $pdo->prepare('INSERT INTO bus_company (id, name, logo_path) VALUES (:id, :ad, :logo)');
            $ekle->execute([
                ':id' => uuid_v4(),
                ':ad' => $ad,
                ':logo' => $logo !== '' ? $logo : null,
            ]);
            $basarilar[] = 'Yeni firma eklendi.';
        } elseif ($islem === 'firma_guncelle') {
            $id = $_POST['firma_id'] ?? '';
            $ad = trim($_POST['firma_ad'] ?? '');
            $logo = trim($_POST['logo_yol'] ?? '');
            if ($id === '' || $ad === '') {
                throw new RuntimeException('Firma güncellemesi için tüm bilgileri doldurun.');
            }
            $guncelle = $pdo->prepare('UPDATE bus_company SET name = :ad, logo_path = :logo WHERE id = :id');
            $guncelle->execute([
                ':ad' => $ad,
                ':logo' => $logo !== '' ? $logo : null,
                ':id' => $id,
            ]);
            if ($guncelle->rowCount() === 0) {
                throw new RuntimeException('Firma bulunamadı veya değişiklik yapılmadı.');
            }
            $basarilar[] = 'Firma bilgileri güncellendi.';
        } elseif ($islem === 'firma_sil') {
            $id = $_POST['firma_id'] ?? '';
            if ($id === '') {
                throw new RuntimeException('Silinecek firma seçilmedi.');
            }
            $sil = $pdo->prepare('DELETE FROM bus_company WHERE id = :id');
            $sil->execute([':id' => $id]);
            if ($sil->rowCount() === 0) {
                throw new RuntimeException('Firma silinemedi.');
            }
            $basarilar[] = 'Firma başarıyla silindi.';
        } elseif ($islem === 'firma_admin_ekle') {
            $adSoyad = trim($_POST['ad_soyad'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $parola = $_POST['parola'] ?? '';
            $firmaId = $_POST['firma_id'] ?? '';
            if ($adSoyad === '' || $email === '' || $parola === '' || $firmaId === '') {
                throw new RuntimeException('Firma admini eklemek için tüm alanları doldurun.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Geçerli bir e-posta adresi girin.');
            }
            $firmaVar = $pdo->prepare('SELECT COUNT(*) FROM bus_company WHERE id = :id');
            $firmaVar->execute([':id' => $firmaId]);
            if ((int) $firmaVar->fetchColumn() === 0) {
                throw new RuntimeException('Seçilen firma bulunamadı.');
            }
            if (kullanici_getir_email($pdo, $email)) {
                throw new RuntimeException('Bu e-posta adresi zaten kullanılıyor.');
            }
            $yeni = kullanici_kaydet($pdo, $adSoyad, $email, $parola, 'FIRMA_ADMIN', $firmaId);
            $basarilar[] = 'Yeni firma admini oluşturuldu: ' . htmlspecialchars($yeni['full_name']);
        } elseif ($islem === 'kupon_ekle') {
            $kod = strtoupper(trim($_POST['kupon_kod'] ?? ''));
            $tur = $_POST['kupon_tur'] ?? 'YUZDE';
            $deger = (int)($_POST['kupon_deger'] ?? 0);
            $limit = $_POST['kupon_limit'] === '' ? null : (int)$_POST['kupon_limit'];
            $baslangic = $_POST['kupon_baslangic'] ?? '';
            $bitis = $_POST['kupon_bitis'] ?? '';
            $firmaId = $_POST['kupon_firma_id'] ?? '';

            if ($kod === '' || $deger <= 0) {
                throw new RuntimeException('Kupon kodu ve indirim değeri zorunludur.');
            }
            if (!in_array($tur, ['YUZDE', 'SABIT'], true)) {
                throw new RuntimeException('Kupon türü geçersiz.');
            }
            $firmaId = $firmaId !== '' ? $firmaId : null;
            if ($firmaId) {
                $firmaVar = $pdo->prepare('SELECT COUNT(*) FROM bus_company WHERE id = :id');
                $firmaVar->execute([':id' => $firmaId]);
                if ((int)$firmaVar->fetchColumn() === 0) {
                    throw new RuntimeException('Seçilen firma bulunamadı.');
                }
            }

            $baslangicSql = null;
            $bitisSql = null;
            if ($baslangic !== '') {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $baslangic);
                if (!$dt) {
                    throw new RuntimeException('Başlangıç tarihi formatı hatalı.');
                }
                $baslangicSql = $dt->format('Y-m-d H:i:s');
            }
            if ($bitis !== '') {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $bitis);
                if (!$dt) {
                    throw new RuntimeException('Bitiş tarihi formatı hatalı.');
                }
                $bitisSql = $dt->format('Y-m-d H:i:s');
            }

            $ekle = $pdo->prepare(
                'INSERT INTO coupons (id, code, rate_or_kurus, kind, usage_limit, start_time, end_time, company_id)
                 VALUES (:id, :code, :deger, :tur, :limit, :baslangic, :bitis, :firma)'
            );
            $ekle->execute([
                ':id' => uuid_v4(),
                ':code' => $kod,
                ':deger' => $deger,
                ':tur' => $tur,
                ':limit' => $limit,
                ':baslangic' => $baslangicSql,
                ':bitis' => $bitisSql,
                ':firma' => $firmaId,
            ]);
            $basarilar[] = 'Kupon başarıyla eklendi.';
        } elseif ($islem === 'kupon_sil') {
            $id = $_POST['kupon_id'] ?? '';
            if ($id === '') {
                throw new RuntimeException('Silinecek kupon bulunamadı.');
            }
            $sil = $pdo->prepare('DELETE FROM coupons WHERE id = :id');
            $sil->execute([':id' => $id]);
            if ($sil->rowCount() === 0) {
                throw new RuntimeException('Kupon silinemedi.');
            }
            $basarilar[] = 'Kupon silindi.';
        }
    } catch (Throwable $e) {
        $hatalar[] = $e->getMessage();
    }
}

$firmalar = $pdo->query('SELECT id, name, logo_path, created_at FROM bus_company ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$firmaHarita = [];
foreach ($firmalar as $firma) {
    $firmaHarita[$firma['id']] = $firma;
}

$firmaAdminleri = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.company_id, bc.name AS firma_adi, u.created_at
     FROM user u
     LEFT JOIN bus_company bc ON bc.id = u.company_id
     WHERE u.role = 'FIRMA_ADMIN'
     ORDER BY u.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$kuponlar = $pdo->query(
    "SELECT c.*, bc.name AS firma_adi
     FROM coupons c
     LEFT JOIN bus_company bc ON bc.id = c.company_id
     ORDER BY c.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Admin Paneli</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="user-meta">
        <span><?= htmlspecialchars($kullanici['full_name']) ?></span>
        <span class="badge">Admin</span>
      </div>
      <nav class="nav-links">
        <a href="/sayfalar/ana-sayfa.php">Ana Sayfa</a>
        <a href="/sayfalar/admin-panel.php" class="btn">Admin Paneli</a>
        <a href="/sayfalar/cikis.php">Çıkış</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="page-intro">
      <h1>Admin Paneli</h1>
      <p class="lead">Firmaları, firma adminlerini ve kuponları bu ekrandan yönetebilirsiniz.</p>
    </section>

    <?php if ($basarilar): ?>
      <div class="alert success">
        <ul>
          <?php foreach ($basarilar as $mesaj): ?>
            <li><?= htmlspecialchars($mesaj) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($hatalar): ?>
      <div class="alert danger">
        <ul>
          <?php foreach ($hatalar as $mesaj): ?>
            <li><?= htmlspecialchars($mesaj) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="panel">
      <h2>Yeni Firma Ekle</h2>
      <form method="post" class="stack-form">
        <input type="hidden" name="islem" value="firma_ekle">
        <label>
          Firma Adı
          <input type="text" name="firma_ad" required>
        </label>
        <label>
          Logo Yolu
          <input type="text" name="logo_yol" placeholder="/varliklar/logo.png">
        </label>
        <div class="actions">
          <button type="submit">Kaydet</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Firmalar</h2>
      <?php if (!$firmalar): ?>
        <div class="empty-state">Henüz firma eklenmemiş.</div>
      <?php else: ?>
        <div class="form-table">
          <?php foreach ($firmalar as $firma): ?>
            <form method="post" class="form-row">
              <input type="hidden" name="islem" value="firma_guncelle">
              <input type="hidden" name="firma_id" value="<?= htmlspecialchars($firma['id']) ?>">
              <div>
                <strong><?= htmlspecialchars($firma['name']) ?></strong><br>
                <small>Oluşturulma: <?= htmlspecialchars($firma['created_at']) ?></small>
              </div>
              <label>
                Ad
                <input type="text" name="firma_ad" value="<?= htmlspecialchars($firma['name']) ?>" required>
              </label>
              <label>
                Logo
                <input type="text" name="logo_yol" value="<?= htmlspecialchars($firma['logo_path'] ?? '') ?>">
              </label>
              <div class="actions">
                <button type="submit">Güncelle</button>
              </div>
            </form>
            <form method="post" class="form-row danger-row" onsubmit="return confirm('Firmayı silmek istediğinize emin misiniz? Bu işlem geri alınamaz.');">
              <input type="hidden" name="islem" value="firma_sil">
              <input type="hidden" name="firma_id" value="<?= htmlspecialchars($firma['id']) ?>">
              <div>Firmanın tüm verileri silinir.</div>
              <div class="actions">
                <button type="submit">Firmayı Sil</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Yeni Firma Admini Oluştur</h2>
      <form method="post" class="stack-form">
        <input type="hidden" name="islem" value="firma_admin_ekle">
        <label>
          Ad Soyad
          <input type="text" name="ad_soyad" required>
        </label>
        <label>
          E-posta
          <input type="email" name="email" required>
        </label>
        <label>
          Parola
          <input type="password" name="parola" minlength="6" required>
        </label>
        <label>
          Firma
          <select name="firma_id" required>
            <option value="">Firma seçin</option>
            <?php foreach ($firmalar as $firma): ?>
              <option value="<?= htmlspecialchars($firma['id']) ?>"><?= htmlspecialchars($firma['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="actions">
          <button type="submit">Firma Admini Ekle</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Firma Adminleri</h2>
      <?php if (!$firmaAdminleri): ?>
        <div class="empty-state">Kayıtlı firma admini bulunmuyor.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Ad Soyad</th>
              <th>E-posta</th>
              <th>Firma</th>
              <th>Oluşturulma</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($firmaAdminleri as $admin): ?>
              <tr>
                <td><?= htmlspecialchars($admin['full_name']) ?></td>
                <td><?= htmlspecialchars($admin['email']) ?></td>
                <td><?= htmlspecialchars($admin['firma_adi'] ?? '-') ?></td>
                <td><?= htmlspecialchars($admin['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Kupon Yönetimi</h2>
      <form method="post" class="stack-form">
        <input type="hidden" name="islem" value="kupon_ekle">
        <label>
          Kupon Kodu
          <input type="text" name="kupon_kod" required>
        </label>
        <label>
          Tür
          <select name="kupon_tur">
            <option value="YUZDE">Yüzde</option>
            <option value="SABIT">Sabit (Kuruş)</option>
          </select>
        </label>
        <label>
          Değer
          <input type="number" name="kupon_deger" min="1" required>
        </label>
        <label>
          Kullanım Limiti
          <input type="number" name="kupon_limit" min="1" placeholder="Sınırsız için boş bırakın">
        </label>
        <label>
          Başlangıç (opsiyonel)
          <input type="datetime-local" name="kupon_baslangic">
        </label>
        <label>
          Bitiş (opsiyonel)
          <input type="datetime-local" name="kupon_bitis">
        </label>
        <label>
          Firma
          <select name="kupon_firma_id">
            <option value="">Tüm firmalar</option>
            <?php foreach ($firmalar as $firma): ?>
              <option value="<?= htmlspecialchars($firma['id']) ?>"><?= htmlspecialchars($firma['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="actions">
          <button type="submit">Kupon Oluştur</button>
        </div>
      </form>

      <?php if ($kuponlar): ?>
        <div class="form-table">
          <?php foreach ($kuponlar as $kupon): ?>
            <form method="post" class="form-row">
              <input type="hidden" name="islem" value="kupon_guncelle">
              <input type="hidden" name="kupon_id" value="<?= htmlspecialchars($kupon['id']) ?>">
              <div>
                <strong><?= htmlspecialchars($kupon['code']) ?></strong><br>
                <small>Oluşturulma: <?= htmlspecialchars($kupon['created_at']) ?></small>
              </div>
              <label>
                Tür
                <select name="kupon_tur">
                  <option value="YUZDE" <?= $kupon['kind'] === 'YUZDE' ? 'selected' : '' ?>>Yüzde</option>
                  <option value="SABIT" <?= $kupon['kind'] === 'SABIT' ? 'selected' : '' ?>>Sabit</option>
                </select>
              </label>
              <label>
                Değer
                <input type="number" name="kupon_deger" min="1" value="<?= htmlspecialchars($kupon['rate_or_kurus']) ?>" required>
              </label>
              <label>
                Limit
                <input type="number" name="kupon_limit" min="1" value="<?= htmlspecialchars($kupon['usage_limit'] ?? '') ?>" placeholder="Sınırsız için boş bırakın">
              </label>
              <label>
                Başlangıç
                <input type="datetime-local" name="kupon_baslangic" value="<?= htmlspecialchars(tarih_input_format($kupon['start_time'])) ?>">
              </label>
              <label>
                Bitiş
                <input type="datetime-local" name="kupon_bitis" value="<?= htmlspecialchars(tarih_input_format($kupon['end_time'])) ?>">
              </label>
              <div class="actions">
                <button type="submit">Güncelle</button>
              </div>
            </form>
            <form method="post" class="form-row danger-row" onsubmit="return confirm('Kuponu silmek istediğinize emin misiniz?');">
              <input type="hidden" name="islem" value="kupon_sil">
              <input type="hidden" name="kupon_id" value="<?= htmlspecialchars($kupon['id']) ?>">
              <div>Kupon silinecek ve tekrar kullanılamayacak.</div>
              <div class="actions">
                <button type="submit">Kuponu Sil</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">Henüz kupon oluşturulmamış.</div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="footer">
    Bilet Satış Platformu · <?= date('Y') ?>
  </footer>
</body>
</html>
