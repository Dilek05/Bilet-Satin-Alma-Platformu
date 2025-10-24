<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

if (!$kullanici || !firma_admin_mi($kullanici)) {
    redirect('/sayfalar/giris.php');
}

$firmaId = $kullanici['company_id'] ?? null;
if (!$firmaId) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Firma bulunamadı</title><link rel="stylesheet" href="/stil.css"></head><body>';
    echo '<div class="card"><h1>Firma ataması yapılmamış</h1><p class="lead">Bu kullanıcı herhangi bir firmaya bağlı değil. Lütfen sistem yöneticisine başvurun.</p>';
    echo '<div class="actions"><a class="btn" href="/sayfalar/ana-sayfa.php">Ana Sayfa</a></div></div></body></html>';
    exit;
}

$firmaSorgu = $pdo->prepare('SELECT id, name FROM bus_company WHERE id = :id');
$firmaSorgu->execute([':id' => $firmaId]);
$firmaBilgi = $firmaSorgu->fetch(PDO::FETCH_ASSOC);

if (!$firmaBilgi) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Firma bulunamadı</title><link rel="stylesheet" href="/stil.css"></head><body>';
    echo '<div class="card"><h1>Firma bulunamadı</h1><p class="lead">Atandığınız firma sistemde mevcut değil. Lütfen sistem yöneticisine başvurun.</p>';
    echo '<div class="actions"><a class="btn" href="/sayfalar/ana-sayfa.php">Ana Sayfa</a></div></div></body></html>';
    exit;
}

$hatalar = [];
$basarilar = [];

function tl_to_kurus(string $girdi): int
{
    $temiz = trim(str_replace(['TL', 'tl', ' '], '', $girdi));
    $temiz = str_replace('.', '', $temiz);
    $temiz = str_replace(',', '.', $temiz);
    if ($temiz === '') {
        throw new RuntimeException('Fiyat değeri boş.');
    }
    if (!is_numeric($temiz)) {
        throw new RuntimeException('Fiyat değeri sayısal olmalıdır.');
    }
    return (int) round((float)$temiz * 100);
}

function dt_input_to_sql(string $deger): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deger);
    if (!$dt) {
        throw new RuntimeException('Tarih alanı hatalı formatta.');
    }
    return $dt->format('Y-m-d H:i:s');
}

function datetime_for_input(?string $value): string
{
    if (!$value) {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';

    try {
        csrf_token_kontrol($_POST['_token'] ?? null);
        if ($islem === 'sefer_ekle') {
            $nereden = trim($_POST['origin'] ?? '');
            $nereye = trim($_POST['destination'] ?? '');
            $kalkis = $_POST['departure'] ?? '';
            $varis = $_POST['arrival'] ?? '';
            $fiyat = $_POST['price'] ?? '';
            $kapasite = (int)($_POST['capacity'] ?? 0);

            if ($nereden === '' || $nereye === '' || $kalkis === '' || $varis === '' || $fiyat === '' || $kapasite <= 0) {
                throw new RuntimeException('Sefer oluşturmak için tüm alanlar gereklidir.');
            }

            $kalkisSql = dt_input_to_sql($kalkis);
            $varisSql = dt_input_to_sql($varis);
            if ($kalkisSql >= $varisSql) {
                throw new RuntimeException('Varış zamanı kalkıştan sonra olmalıdır.');
            }

            $fiyatKurus = tl_to_kurus($fiyat);
            if ($fiyatKurus <= 0 || $fiyatKurus > 1000000) {
                throw new RuntimeException('Fiyat 0\'dan büyük ve en fazla 10.000 TL olmalıdır.');
            }

            $ekle = $pdo->prepare(
                'INSERT INTO trips (id, company_id, origin_city, destination_city, departure_time, arrival_time, price_kurus, capacity)
                 VALUES (:id, :firma, :origin, :destination, :departure, :arrival, :price, :capacity)'
            );
            $ekle->execute([
                ':id' => uuid_v4(),
                ':firma' => $firmaId,
                ':origin' => $nereden,
                ':destination' => $nereye,
                ':departure' => $kalkisSql,
                ':arrival' => $varisSql,
                ':price' => $fiyatKurus,
                ':capacity' => $kapasite,
            ]);
            $basarilar[] = 'Yeni sefer başarıyla oluşturuldu.';
        } elseif ($islem === 'sefer_guncelle') {
            $seferId = guvenli_id($_POST['trip_id'] ?? '', 'Sefer bulunamad��.');
            $nereden = trim($_POST['origin'] ?? '');
            $nereye = trim($_POST['destination'] ?? '');
            $kalkis = $_POST['departure'] ?? '';
            $varis = $_POST['arrival'] ?? '';
            $fiyat = $_POST['price'] ?? '';
            $kapasite = (int)($_POST['capacity'] ?? 0);

            if ($seferId === '') {
                throw new RuntimeException('Sefer bulunamadı.');
            }

            $kontrol = $pdo->prepare('SELECT id FROM trips WHERE id = :id AND company_id = :firma');
            $kontrol->execute([':id' => $seferId, ':firma' => $firmaId]);
            if (!$kontrol->fetch()) {
                throw new RuntimeException('Sefer bu firmaya ait değil.');
            }

            if ($nereden === '' || $nereye === '' || $kalkis === '' || $varis === '' || $fiyat === '' || $kapasite <= 0) {
                throw new RuntimeException('Sefer güncellemesi için tüm alanlar gereklidir.');
            }

            $kalkisSql = dt_input_to_sql($kalkis);
            $varisSql = dt_input_to_sql($varis);
            if ($kalkisSql >= $varisSql) {
                throw new RuntimeException('Varış zamanı kalkıştan sonra olmalıdır.');
            }

            $fiyatKurus = tl_to_kurus($fiyat);
            if ($fiyatKurus <= 0 || $fiyatKurus > 1000000) {
                throw new RuntimeException('Fiyat 0\'dan büyük ve en fazla 10.000 TL olmalıdır.');
            }

            $guncelle = $pdo->prepare(
                'UPDATE trips
                 SET origin_city = :origin,
                     destination_city = :destination,
                     departure_time = :departure,
                     arrival_time = :arrival,
                     price_kurus = :price,
                     capacity = :capacity
                 WHERE id = :id AND company_id = :firma'
            );
            $guncelle->execute([
                ':origin' => $nereden,
                ':destination' => $nereye,
                ':departure' => $kalkisSql,
                ':arrival' => $varisSql,
                ':price' => $fiyatKurus,
                ':capacity' => $kapasite,
                ':id' => $seferId,
                ':firma' => $firmaId,
            ]);
            $basarilar[] = 'Sefer bilgileri güncellendi.';
        } elseif ($islem === 'sefer_sil') {
            $seferId = guvenli_id($_POST['trip_id'] ?? '', 'Silinecek sefer se��ilmedi.');
            if ($seferId === '') {
                throw new RuntimeException('Silinecek sefer seçilmedi.');
            }
            $sil = $pdo->prepare('DELETE FROM trips WHERE id = :id AND company_id = :firma');
            $sil->execute([':id' => $seferId, ':firma' => $firmaId]);
            if ($sil->rowCount() === 0) {
                throw new RuntimeException('Sefer silinemedi.');
            }
            $basarilar[] = 'Sefer silindi.';
        } elseif ($islem === 'kupon_ekle') {
            $kod = strtoupper(trim($_POST['kupon_kod'] ?? ''));
            $tur = $_POST['kupon_tur'] ?? 'YUZDE';
            $deger = (int)($_POST['kupon_deger'] ?? 0);
            $limit = $_POST['kupon_limit'] === '' ? null : (int)$_POST['kupon_limit'];
            $baslangic = $_POST['kupon_baslangic'] ?? '';
            $bitis = $_POST['kupon_bitis'] ?? '';

            if ($kod === '' || $deger <= 0) {
                throw new RuntimeException('Kupon kodu ve indirim değeri zorunludur.');
            }
            if (!in_array($tur, ['YUZDE', 'SABIT'], true)) {
                throw new RuntimeException('Kupon türü geçersiz.');
            }

            $baslangicSql = null;
            $bitisSql = null;
            if ($baslangic !== '') {
                $baslangicSql = dt_input_to_sql($baslangic);
            }
            if ($bitis !== '') {
                $bitisSql = dt_input_to_sql($bitis);
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
            $basarilar[] = 'Kupon oluşturuldu.';
        } elseif ($islem === 'kupon_guncelle') {
            $kuponId = guvenli_id($_POST['kupon_id'] ?? '', 'Kupon bulunamad��.');
            $tur = $_POST['kupon_tur'] ?? 'YUZDE';
            $deger = (int)($_POST['kupon_deger'] ?? 0);
            $limit = $_POST['kupon_limit'] === '' ? null : (int)$_POST['kupon_limit'];
            $baslangic = $_POST['kupon_baslangic'] ?? '';
            $bitis = $_POST['kupon_bitis'] ?? '';

            if ($kuponId === '') {
                throw new RuntimeException('Kupon bulunamadı.');
            }
            $kontrol = $pdo->prepare('SELECT id FROM coupons WHERE id = :id AND company_id = :firma');
            $kontrol->execute([':id' => $kuponId, ':firma' => $firmaId]);
            if (!$kontrol->fetch()) {
                throw new RuntimeException('Kupon bu firmaya ait değil.');
            }
            if (!in_array($tur, ['YUZDE', 'SABIT'], true) || $deger <= 0) {
                throw new RuntimeException('Kupon bilgileri hatalı.');
            }

            $baslangicSql = null;
            $bitisSql = null;
            if ($baslangic !== '') {
                $baslangicSql = dt_input_to_sql($baslangic);
            }
            if ($bitis !== '') {
                $bitisSql = dt_input_to_sql($bitis);
            }

            $guncelle = $pdo->prepare(
                'UPDATE coupons
                 SET rate_or_kurus = :deger,
                     kind = :tur,
                     usage_limit = :limit,
                     start_time = :baslangic,
                     end_time = :bitis
                 WHERE id = :id AND company_id = :firma'
            );
            $guncelle->execute([
                ':deger' => $deger,
                ':tur' => $tur,
                ':limit' => $limit,
                ':baslangic' => $baslangicSql,
                ':bitis' => $bitisSql,
                ':id' => $kuponId,
                ':firma' => $firmaId,
            ]);
            $basarilar[] = 'Kupon bilgileri güncellendi.';
        } elseif ($islem === 'kupon_sil') {
            $kuponId = guvenli_id($_POST['kupon_id'] ?? '', 'Silinecek kupon se��ilmedi.');
            if ($kuponId === '') {
                throw new RuntimeException('Silinecek kupon seçilmedi.');
            }
            $sil = $pdo->prepare('DELETE FROM coupons WHERE id = :id AND company_id = :firma');
            $sil->execute([':id' => $kuponId, ':firma' => $firmaId]);
            if ($sil->rowCount() === 0) {
                throw new RuntimeException('Kupon silinemedi.');
            }
            $basarilar[] = 'Kupon silindi.';
        }
    } catch (Throwable $e) {
        $hatalar[] = $e->getMessage();
    }
}

$seferSorgu = $pdo->prepare(
    'SELECT id, origin_city, destination_city, departure_time, arrival_time, price_kurus, capacity, created_at
     FROM trips
     WHERE company_id = :firma
     ORDER BY departure_time ASC'
);
$seferSorgu->execute([':firma' => $firmaId]);
$seferler = $seferSorgu->fetchAll(PDO::FETCH_ASSOC);

$kuponSorgu = $pdo->prepare(
    'SELECT id, code, kind, rate_or_kurus, usage_limit, start_time, end_time, created_at
     FROM coupons
     WHERE company_id = :firma
     ORDER BY created_at DESC'
);
$kuponSorgu->execute([':firma' => $firmaId]);
$kuponlar = $kuponSorgu->fetchAll(PDO::FETCH_ASSOC);

$sehirListesi = [
    'Ankara', 'İstanbul', 'İzmir', 'Antalya', 'Mersin', 'Muğla',
    'Aydın', 'Bursa', 'Balıkesir', 'Karabük', 'Trabzon', 'Adana',
    'Gaziantep', 'Eskişehir', 'Konya'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Firma Paneli</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="user-meta">
        <span><?= htmlspecialchars($kullanici['full_name']) ?></span>
        <span class="badge"><?= htmlspecialchars($firmaBilgi['name']) ?></span>
      </div>
      <nav class="nav-links">
        <a href="/sayfalar/ana-sayfa.php">Ana Sayfa</a>
        <a href="/sayfalar/firma-panel.php" class="btn">Firma Paneli</a>
        <a href="/sayfalar/cikis.php">Çıkış</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="page-intro">
      <h1>Firma Paneli</h1>
      <p class="lead">Fırmanıza ait seferleri ve kuponları bu ekrandan yönetebilirsiniz.</p>
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

    <datalist id="firma-sehirleri">
      <?php foreach ($sehirListesi as $sehir): ?>
        <option value="<?= htmlspecialchars($sehir) ?>">
      <?php endforeach; ?>
    </datalist>

    <section class="panel">
      <h2>Yeni Sefer Oluştur</h2>
      <form method="post" class="stack-form">
        <?php echo csrf_token_form(); ?>
        <input type="hidden" name="islem" value="sefer_ekle">
        <label class="inline-field">
          Nereden
          <input id="create-origin" name="origin" list="firma-sehirleri" class="inline-input" required>
        </label>
        <label class="inline-field">
          Nereye
          <input id="create-destination" name="destination" list="firma-sehirleri" class="inline-input" required>
        </label>
        <label>
          Kalkış
          <input type="datetime-local" name="departure" required>
        </label>
        <label>
          Varış
          <input type="datetime-local" name="arrival" required>
        </label>
        <label>
          Fiyat (TL)
          <input type="text" name="price" placeholder="Örn. 250" required>
        </label>
        <label>
          Kapasite
          <input type="number" name="capacity" min="1" required>
        </label>
        <div class="actions">
          <button type="submit">Sefer Ekle</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Seferler</h2>
      <?php if (!$seferler): ?>
        <div class="empty-state">Bu firmaya ait kayıtlı sefer bulunmuyor.</div>
      <?php else: ?>
        <div class="form-table">
          <?php foreach ($seferler as $sefer): ?>
            <form method="post" class="form-row">
              <?php echo csrf_token_form(); ?>
              <input type="hidden" name="islem" value="sefer_guncelle">
              <input type="hidden" name="trip_id" value="<?= htmlspecialchars($sefer['id']) ?>">
              <div>
                <strong><?= htmlspecialchars($sefer['origin_city']) ?> → <?= htmlspecialchars($sefer['destination_city']) ?></strong><br>
                <small>Oluşturulma: <?= htmlspecialchars($sefer['created_at']) ?></small>
              </div>
              <label class="inline-field">
                Nereden
                <input type="text" name="origin" list="firma-sehirleri" class="inline-input" value="<?= htmlspecialchars($sefer['origin_city']) ?>" required>
              </label>
              <label class="inline-field">
                Nereye
                <input type="text" name="destination" list="firma-sehirleri" class="inline-input" value="<?= htmlspecialchars($sefer['destination_city']) ?>" required>
              </label>
              <label>
                Kalkış
                <input type="datetime-local" name="departure" value="<?= htmlspecialchars(datetime_for_input($sefer['departure_time'])) ?>" required>
              </label>
              <label>
                Varış
                <input type="datetime-local" name="arrival" value="<?= htmlspecialchars(datetime_for_input($sefer['arrival_time'])) ?>" required>
              </label>
              <label>
                Fiyat (TL)
                <input type="text" name="price" value="<?= htmlspecialchars(number_format($sefer['price_kurus'] / 100, 2, '.', '')) ?>" required>
              </label>
              <label>
                Kapasite
                <input type="number" name="capacity" min="1" value="<?= htmlspecialchars($sefer['capacity']) ?>" required>
              </label>
              <div class="actions">
                <button type="submit">Güncelle</button>
              </div>
            </form>
            <form method="post" class="form-row danger-row" onsubmit="return confirm('Bu seferi silmek istediğinize emin misiniz?');">
              <?php echo csrf_token_form(); ?>
              <input type="hidden" name="islem" value="sefer_sil">
              <input type="hidden" name="trip_id" value="<?= htmlspecialchars($sefer['id']) ?>">
              <div>Seferi silmek tüm rezervasyonları iptal eder.</div>
              <div class="actions">
                <button type="submit">Seferi Sil</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Kuponlar</h2>
      <form method="post" class="stack-form">
        <?php echo csrf_token_form(); ?>
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
        <div class="actions">
          <button type="submit">Kupon Oluştur</button>
        </div>
      </form>

      <?php if ($kuponlar): ?>
        <div class="form-table">
          <?php foreach ($kuponlar as $kupon): ?>
            <form method="post" class="form-row">
              <?php echo csrf_token_form(); ?>
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
                <input type="datetime-local" name="kupon_baslangic" value="<?= htmlspecialchars(datetime_for_input($kupon['start_time'])) ?>">
              </label>
              <label>
                Bitiş
                <input type="datetime-local" name="kupon_bitis" value="<?= htmlspecialchars(datetime_for_input($kupon['end_time'])) ?>">
              </label>
              <div class="actions">
                <button type="submit">Güncelle</button>
              </div>
            </form>
            <form method="post" class="form-row danger-row" onsubmit="return confirm('Kuponu silmek istediğinize emin misiniz?');">
              <?php echo csrf_token_form(); ?>
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
        <div class="empty-state">Firma için kupon bulunmuyor.</div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="footer">
    Bilet Satış Platformu · <?= date('Y') ?>
  </footer>

  <script>
    document.querySelectorAll('.city-suggestions').forEach(function (group) {
      var targetId = group.dataset.target;
      var input = document.getElementById(targetId);
      if (!input) {
        return;
      }
      group.querySelectorAll('.city-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
          input.value = chip.dataset.value;
          input.focus();
        });
      });
    });
  </script>
</body>
</html>
