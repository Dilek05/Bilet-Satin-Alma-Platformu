<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';
require_once __DIR__ . '/../genel/pdf.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);
$yolcuMu = $kullanici ? yolcu_mu($kullanici) : false;
$adminMi = $kullanici ? admin_mi($kullanici) : false;

$seferId = $_GET['id'] ?? '';
if ($seferId === '') {
    redirect('/sayfalar/ana-sayfa.php');
}

try {
    $seferId = guvenli_id($seferId, 'Geçersiz sefer numarası.');
} catch (RuntimeException $e) {
    http_response_code(400);
    echo 'Geçersiz sefer numarası.';
    exit;
}

$seferSorgu = $pdo->prepare(
    'SELECT t.*, bc.name AS firma_adi
     FROM trips t
     JOIN bus_company bc ON bc.id = t.company_id
     WHERE t.id = :id'
);
$seferSorgu->execute([':id' => $seferId]);
$sefer = $seferSorgu->fetch(PDO::FETCH_ASSOC);

if (!$sefer) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Sefer Bulunamadı</title><link rel="stylesheet" href="/stil.css"></head><body>';
    echo '<div class="card"><h1>Sefer bulunamadı</h1><p class="lead">Aradığınız sefer sistemde yer almıyor. Ana sayfaya dönerek tekrar arayabilirsiniz.</p>';
    echo '<div class="actions"><a class="btn" href="/sayfalar/ana-sayfa.php">Ana Sayfa</a></div></div></body></html>';
    exit;
}

$kapasite = (int) $sefer['capacity'];
$fiyatKurus = (int) $sefer['price_kurus'];
$fiyatTL = number_format($fiyatKurus / 100, 2, ',', '.');
$kullaniciBakiyesi = $kullanici ? (int) $kullanici['balance_kurus'] : 0;
$bakiyeYeterliMi = $kullanici ? $kullaniciBakiyesi >= $fiyatKurus : false;

$kalkisZamani = new DateTimeImmutable($sefer['departure_time']);
$simdi = new DateTimeImmutable('now');
$kalkisaKalanSaniye = $kalkisZamani->getTimestamp() - $simdi->getTimestamp();
$satinalmaMumkun = $kalkisaKalanSaniye > 0;
$sonBirSaatIcinde = $kalkisaKalanSaniye < 3600;

$kuponlar = [];
$kuponVeri = [];
$kuponSorgu = $pdo->prepare(
    "SELECT c.*, bc.name AS firma_adi,
            (SELECT COUNT(*) FROM tickets t WHERE t.coupon_code = c.code AND t.status = 'ALINDI') AS kullanilan
     FROM coupons c
     LEFT JOIN bus_company bc ON bc.id = c.company_id
     WHERE (c.company_id IS NULL OR c.company_id = :firma)
       AND (c.start_time IS NULL OR c.start_time <= datetime('now'))
       AND (c.end_time IS NULL OR c.end_time >= datetime('now'))
     ORDER BY c.company_id IS NOT NULL DESC, c.code ASC"
);
$kuponSorgu->execute([':firma' => $sefer['company_id']]);
foreach ($kuponSorgu->fetchAll(PDO::FETCH_ASSOC) as $kupon) {
    if ($kupon['usage_limit'] !== null && (int)$kupon['kullanilan'] >= (int)$kupon['usage_limit']) {
        continue;
    }
    $kuponlar[] = $kupon;
    $kuponVeri[] = [
        'code' => $kupon['code'],
        'kind' => $kupon['kind'],
        'value' => (int) $kupon['rate_or_kurus'],
    ];
}

$hatalar = [];
$basariMesaji = null;
$seciliKoltuk = null;
$girilenKupon = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'satinal') {
    if (!$kullanici) {
        redirect('/sayfalar/giris.php');
    }
    if (!$yolcuMu) {
        $hatalar[] = 'Bilet satın alma işlemi yalnızca yolcu hesabıyla gerçekleştirilebilir.';
    }

    $koltukNoHam = $_POST['koltuk_no'] ?? '';
    $koltukNo = filter_var($koltukNoHam, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $kapasite],
    ]);
    if ($koltukNo === false) {
        $hatalar[] = 'Geçerli bir koltuk seçmelisiniz.';
    } else {
        $seciliKoltuk = $koltukNo;
    }

    $girilenKupon = trim($_POST['kupon_kodu'] ?? '');
    $girilenKuponUpper = $girilenKupon !== ''
        ? (function_exists('mb_strtoupper') ? mb_strtoupper($girilenKupon, 'UTF-8') : strtoupper($girilenKupon))
        : '';

    if (!$hatalar) {
        try {
            $pdo->beginTransaction();

            if (!$satinalmaMumkun) {
                throw new RuntimeException('Sefer için bilet satış süresi sona erdi.');
            }
            if ($sonBirSaatIcinde) {
                throw new RuntimeException('Sefer kalkışına 1 saatten az kaldığı için bilet satışı yapılmamaktadır.');
            }

            $kuponBilgi = null;
            $kuponIndirim = 0;
            $kuponKodKaydet = null;
            $kuponMesaj = '';

            if ($girilenKuponUpper !== '') {
                $kuponSorgu = $pdo->prepare('SELECT * FROM coupons WHERE code = :code COLLATE NOCASE');
                $kuponSorgu->execute([':code' => $girilenKuponUpper]);
                $kuponBilgi = $kuponSorgu->fetch(PDO::FETCH_ASSOC);

                if (!$kuponBilgi) {
                    throw new RuntimeException('Girilen kupon kodu bulunamadı.');
                }

                if ($kuponBilgi['company_id'] !== null && $kuponBilgi['company_id'] !== $sefer['company_id']) {
                    throw new RuntimeException('Bu kupon yalnızca ilgili firmanın seferlerinde kullanılabilir.');
                }

                if (!empty($kuponBilgi['start_time'])) {
                    $baslangic = new DateTimeImmutable($kuponBilgi['start_time']);
                    if ($simdi < $baslangic) {
                        throw new RuntimeException('Kupon henüz kullanıma açılmamış.');
                    }
                }
                if (!empty($kuponBilgi['end_time'])) {
                    $bitis = new DateTimeImmutable($kuponBilgi['end_time']);
                    if ($simdi > $bitis) {
                        throw new RuntimeException('Kuponun son kullanma tarihi geçmiş.');
                    }
                }

                if ($kuponBilgi['usage_limit'] !== null) {
                    $kullanimSorgu = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE coupon_code = :code AND status = 'ALINDI'");
                    $kullanimSorgu->execute([':code' => $kuponBilgi['code']]);
                    $kullanimSayisi = (int) $kullanimSorgu->fetchColumn();
                    if ($kullanimSayisi >= (int) $kuponBilgi['usage_limit']) {
                        throw new RuntimeException('Kupon kullanım limiti dolmuş.');
                    }
                }

                $kuponKodKaydet = $kuponBilgi['code'];
            }

            $seatCheck = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE trip_id = :trip AND seat_number = :seat AND status = 'ALINDI'");
            $seatCheck->execute([':trip' => $seferId, ':seat' => $seciliKoltuk]);
            if ((int) $seatCheck->fetchColumn() > 0) {
                throw new RuntimeException('Seçtiğiniz koltuk az önce başka bir yolcu tarafından alındı. Lütfen farklı bir koltuk seçin.');
            }

            $odenecekTutar = $fiyatKurus;
            if ($kuponBilgi) {
                if ($kuponBilgi['kind'] === 'YUZDE') {
                    $kuponIndirim = intdiv($odenecekTutar * (int) $kuponBilgi['rate_or_kurus'], 100);
                    $kuponMesaj = $kuponBilgi['rate_or_kurus'] . '% indirim uygulandı.';
                } else {
                    $kuponIndirim = (int) $kuponBilgi['rate_or_kurus'];
                    $kuponMesaj = number_format($kuponIndirim / 100, 2, ',', '.') . ' TL indirim uygulandı.';
                }
                if ($kuponIndirim > $odenecekTutar) {
                    $kuponIndirim = $odenecekTutar;
                }
                $odenecekTutar -= $kuponIndirim;
            }

            $bakiyeSorgu = $pdo->prepare('SELECT balance_kurus FROM user WHERE id = :id');
            $bakiyeSorgu->execute([':id' => $kullanici['id']]);
            $guncelBakiye = (int) $bakiyeSorgu->fetchColumn();

            if ($guncelBakiye < $odenecekTutar) {
                throw new RuntimeException('Bakiyeniz bu bilet için yeterli değil.');
            }

            $ticketId = uuid_v4();
            $pdfRelPath = 'biletler/' . $ticketId . '.pdf';

            $biletEkle = $pdo->prepare(
                'INSERT INTO tickets (id, trip_id, user_id, seat_number, status, price_paid_kurus, coupon_code, pdf_path)
                 VALUES (:id, :trip_id, :user_id, :seat, \'ALINDI\', :fiyat, :kupon, :pdf)'
            );
            $biletEkle->execute([
                ':id' => $ticketId,
                ':trip_id' => $seferId,
                ':user_id' => $kullanici['id'],
                ':seat' => $seciliKoltuk,
                ':fiyat' => $odenecekTutar,
                ':kupon' => $kuponKodKaydet,
                ':pdf' => $pdfRelPath,
            ]);

            $koltukEkle = $pdo->prepare(
                'INSERT OR IGNORE INTO booked_seats (id, trip_id, seat_number) VALUES (:id, :trip, :seat)'
            );
            $koltukEkle->execute([
                ':id' => uuid_v4(),
                ':trip' => $seferId,
                ':seat' => $seciliKoltuk,
            ]);

            $bakiyeDus = $pdo->prepare(
                'UPDATE user SET balance_kurus = balance_kurus - :tutar WHERE id = :id'
            );
            $bakiyeDus->execute([':tutar' => $odenecekTutar, ':id' => $kullanici['id']]);

            if ($kuponBilgi) {
                $kuponBagla = $pdo->prepare(
                    'INSERT INTO user_coupons (id, user_id, coupon_id, used_count)
                     VALUES (:id, :user_id, :coupon_id, 1)
                     ON CONFLICT(user_id, coupon_id) DO UPDATE SET used_count = used_count + 1'
                );
                $kuponBagla->execute([
                    ':id' => uuid_v4(),
                    ':user_id' => $kullanici['id'],
                    ':coupon_id' => $kuponBilgi['id'],
                ]);
            }

            $pdo->commit();

            $yeniBakiye = $guncelBakiye - $odenecekTutar;
            $_SESSION['aktif_kullanici']['balance_kurus'] = $yeniBakiye;
            $kullanici['balance_kurus'] = $yeniBakiye;

            $pdfKlasor = dirname(__DIR__) . '/biletler';
            if (!is_dir($pdfKlasor)) {
                mkdir($pdfKlasor, 0777, true);
            }

            $pdfTamYol = $pdfKlasor . '/' . $ticketId . '.pdf';
            $pdfSatirlar = [
                'Bilet Satış Platformu',
                '-------------------------',
                'Bilet No: ' . $ticketId,
                'Yolcu: ' . $kullanici['full_name'],
                'Firma: ' . $sefer['firma_adi'],
                'Güzergâh: ' . $sefer['origin_city'] . ' -> ' . $sefer['destination_city'],
                'Kalkış: ' . $sefer['departure_time'],
                'Varış: ' . $sefer['arrival_time'],
                'Koltuk: ' . $seciliKoltuk,
                'Ödenen Tutar: ' . number_format($odenecekTutar / 100, 2, ',', '.') . ' TL',
                $kuponKodKaydet ? 'Kupon: ' . $kuponKodKaydet : 'Kupon: Kullanılmadı',
                'İşlem Zamanı: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ];

            $pdfLink = null;
            try {
                bilet_pdf_olustur($pdfTamYol, $pdfSatirlar);
                $pdfLink = '/' . $pdfRelPath;
            } catch (Throwable $pdfHata) {
                // PDF üretimi başarısız olabilir; kullanıcıya bilgi verelim.
            }

            $basariMesaji = "Koltuk {$seciliKoltuk} için biletiniz oluşturuldu. Ödenen tutar: " .
                number_format($odenecekTutar / 100, 2, ',', '.') . " TL.";
            if ($kuponMesaj !== '') {
                $basariMesaji .= ' ' . $kuponMesaj;
            }
            if ($pdfLink) {
                $basariMesaji .= ' <a href="' . htmlspecialchars($pdfLink) . '" target="_blank" rel="noopener">PDF bileti indir</a>.';
            } else {
                $basariMesaji .= ' PDF bileti oluşturulamadı, lütfen daha sonra tekrar deneyin.';
            }

            $seciliKoltuk = null;
            $girilenKupon = '';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $hatalar[] = $e->getMessage();
        }
    }
}

$koltukSorgu = $pdo->prepare(
    "SELECT seat_number
     FROM tickets
     WHERE trip_id = :trip_id AND status = 'ALINDI'"
);
$koltukSorgu->execute([':trip_id' => $seferId]);
$doluKoltuklar = array_map('intval', array_column($koltukSorgu->fetchAll(PDO::FETCH_ASSOC), 'seat_number'));
$bosKoltukSayisi = $kapasite - count($doluKoltuklar);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($sefer['origin_city']) ?> → <?= htmlspecialchars($sefer['destination_city']) ?> - Sefer Detayı</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="user-meta">
        <?php if ($kullanici): ?>
          <span><?= htmlspecialchars($kullanici['full_name']) ?></span>
          <?php if ($yolcuMu): ?>
            <span class="badge">Bakiye: <?= number_format($kullanici['balance_kurus'] / 100, 2, ',', '.') ?> TL</span>
          <?php endif; ?>
        <?php else: ?>
          <span>Bilet Satış Platformu</span>
        <?php endif; ?>
      </div>
      <nav class="nav-links">
        <a href="/sayfalar/ana-sayfa.php">Ana Sayfa</a>
        <?php if ($kullanici): ?>
          <?php if (yolcu_mu($kullanici)): ?>
            <a href="/sayfalar/hesabim.php">Hesabım</a>
          <?php endif; ?>
          <?php if (admin_mi($kullanici)): ?>
            <a href="/sayfalar/admin-panel.php">Admin Panel</a>
          <?php endif; ?>
          <?php if (firma_admin_mi($kullanici)): ?>
            <a href="/sayfalar/firma-panel.php">Firma Paneli</a>
          <?php endif; ?>
          <a href="/sayfalar/cikis.php">Çıkış</a>
        <?php else: ?>
          <a href="/sayfalar/giris.php">Giriş Yap</a>
          <a href="/sayfalar/kayit.php" class="btn">Kayıt Ol</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="page-intro">
      <a href="/sayfalar/ana-sayfa.php" class="back-link">← Listeye dön</a>
      <h1><?= htmlspecialchars($sefer['firma_adi']) ?> | <?= htmlspecialchars($sefer['origin_city']) ?> → <?= htmlspecialchars($sefer['destination_city']) ?></h1>
      <p class="lead">
        Kalkış: <strong><?= htmlspecialchars($sefer['departure_time']) ?></strong> ·
        Varış: <strong><?= htmlspecialchars($sefer['arrival_time']) ?></strong> ·
        Bilet fiyatı: <strong><?= $fiyatTL ?> TL</strong>
      </p>
    </section>

    <?php if ($adminMi): ?>
      <section class="panel" id="koltuklar">
        <h2>Bilet Satın Alma</h2>
        <p class="muted">Yonetici hesabi bu sayfadan bilet satin alma islemi yapamaz.</p>
      </section>
    <?php else: ?>
      <section class="panel" id="koltuklar">
        <h2>Bilet Satın Alma</h2>
      <?php if ($basariMesaji): ?>
        <div class="alert success"><?= $basariMesaji ?></div>
      <?php endif; ?>
      <?php if ($hatalar): ?>
        <div class="alert danger">
          <ul>
            <?php foreach ($hatalar as $hata): ?>
              <li><?= htmlspecialchars($hata) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if (!$kullanici): ?>
        <p class="muted">Bilet satın almak için lütfen önce <a href="/sayfalar/giris.php">giriş yapın</a>.</p>
      <?php elseif (!$yolcuMu): ?>
        <p class="muted">Bu işlem yalnızca yolcu profilleri için kullanılabilir.</p>
      <?php elseif ($bosKoltukSayisi <= 0): ?>
        <p class="muted">Bu seferde boş koltuk kalmadı.</p>
      <?php elseif (!$satinalmaMumkun): ?>
        <p class="muted">Sefer satış süresi sona erdi.</p>
      <?php elseif ($sonBirSaatIcinde): ?>
        <p class="muted">Sefer kalkışına 1 saatten az kaldığı için bilet satışı kapatıldı.</p>
      <?php else: ?>
        <div class="balance-info">
          <span>Standart bilet fiyatı: <strong><?= $fiyatTL ?> TL</strong></span>
          <span>Bakiyeniz: <strong><?= number_format($kullanici['balance_kurus'] / 100, 2, ',', '.') ?> TL</strong></span>
        </div>
        <?php if (!$bakiyeYeterliMi): ?>
          <p class="balance-warning">Bakiyeniz standart fiyatı karşılamıyor. Kupon kullanarak veya bakiye yükleyerek satın alma işlemini tamamlayabilirsiniz.</p>
        <?php endif; ?>
        <?php if ($kuponlar): ?>
          <div class="coupon-box">
            <h3>Kullanabileceğiniz Kuponlar</h3>
            <ul class="coupon-list">
              <?php foreach ($kuponlar as $kupon): ?>
                <li class="coupon-item">
                  <span class="code"><?= htmlspecialchars($kupon['code']) ?></span>
                  <span class="detail">
                    <?php if ($kupon['kind'] === 'YUZDE'): ?>
                      %<?= (int) $kupon['rate_or_kurus'] ?> indirim
                    <?php else: ?>
                      <?= number_format($kupon['rate_or_kurus'] / 100, 2, ',', '.') ?> TL indirim
                    <?php endif; ?>
                    <?php if ($kupon['company_id']): ?>
                      · <?= htmlspecialchars($kupon['firma_adi']) ?> seferleri
                    <?php else: ?>
                      · Tüm firmalar
                    <?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <form method="post" class="stack-form purchase-form">
          <?= csrf_token_form(); ?>
          <input type="hidden" name="islem" value="satinal">
          <div class="seat-section">
            <div class="seat-section-header">
              <h3>Koltuk Seçimi</h3>
              <p class="muted">Toplam kapasite: <?= $kapasite ?> · Boş koltuk: <?= max(0, $bosKoltukSayisi) ?></p>
              <div class="seat-guide">
                <span class="seat sample available"><span>Müsait</span></span>
                <span class="seat sample taken"><span>Dolu</span></span>
                <span class="seat sample yours"><span>Seçiminiz</span></span>
              </div>
            </div>
            <div class="seat-grid">
              <?php
              $firstAvailableMarked = false;
              for ($koltuk = 1; $koltuk <= $kapasite; $koltuk++):
                  $dolu = in_array($koltuk, $doluKoltuklar, true);
                  $checkedAttr = '';
                  $requiredAttr = '';
                  if (!$dolu && !$firstAvailableMarked) {
                      $requiredAttr = 'required';
                      $firstAvailableMarked = true;
                  }
                  if (!$dolu && $seciliKoltuk === $koltuk) {
                      $checkedAttr = 'checked';
                  }
                ?>
                <label class="seat <?= $dolu ? 'taken' : 'available' ?>">
                  <input type="radio" name="koltuk_no" value="<?= $koltuk ?>" <?= $requiredAttr ?> <?= $checkedAttr ?> <?= $dolu ? 'disabled' : '' ?>>
                  <span><?= $koltuk ?></span>
                </label>
              <?php endfor; ?>
            </div>
          </div>
          <label>
            Kupon Kodu (opsiyonel)
            <input type="text" name="kupon_kodu" placeholder="Örn. EKIM20" value="<?= htmlspecialchars($girilenKupon) ?>">
          </label>
          <div class="price-preview" id="price-preview">Ödenecek tutar: <strong><?= $fiyatTL ?> TL</strong></div>
          <div class="actions">
            <button type="submit">Bileti Satın Al</button>
          </div>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </main>

  <footer class="footer">
    Bilet Satış Platformu · <?= date('Y') ?>
  </footer>

  <script>
    const basePriceKurus = <?= json_encode($fiyatKurus) ?>;
    const couponList = <?= json_encode($kuponVeri, JSON_UNESCAPED_UNICODE) ?>;
    const couponInput = document.querySelector('input[name="kupon_kodu"]');
    const pricePreview = document.getElementById('price-preview');

    function formatTL(kurus) {
      const tl = (kurus / 100).toFixed(2).replace('.', ',');
      return `${tl} TL`;
    }

    function findCoupon(code) {
      if (!code) { return null; }
      const target = code.trim().toUpperCase();
      return couponList.find(c => c.code.toUpperCase() === target) || null;
    }

    function updatePreview() {
      const code = couponInput.value;
      const coupon = findCoupon(code);
      let indirim = 0;

      if (coupon) {
        if (coupon.kind === 'YUZDE') {
          indirim = Math.floor((basePriceKurus * coupon.value) / 100);
        } else {
          indirim = coupon.value;
        }
        if (indirim > basePriceKurus) {
          indirim = basePriceKurus;
        }
        const odenecek = basePriceKurus - indirim;
        pricePreview.innerHTML = `Ödenecek tutar: <strong>${formatTL(odenecek)}</strong> · ${formatTL(indirim)} indirim uygulanır.`;
      } else if (code.trim() !== '') {
        pricePreview.innerHTML = `Ödenecek tutar: <strong>${formatTL(basePriceKurus)}</strong> · Kupon bulunamadı.`;
      } else {
        pricePreview.innerHTML = `Ödenecek tutar: <strong>${formatTL(basePriceKurus)}</strong>`;
      }
    }

    if (couponInput) {
      couponInput.addEventListener('input', updatePreview);
    }
    updatePreview();
  </script>
</body>
</html>

