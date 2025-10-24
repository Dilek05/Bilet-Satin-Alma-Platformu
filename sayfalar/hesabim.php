<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';
require_once __DIR__ . '/../genel/pdf.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

if (!$kullanici || !yolcu_mu($kullanici)) {
    redirect('/sayfalar/giris.php');
}

$hatalar = [];
$basarilar = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';

    try {
        csrf_token_kontrol($_POST['_token'] ?? null);
    } catch (Throwable $e) {
        $hatalar[] = $e->getMessage();
        $islem = '';
    }

    if ($islem === 'bakiye_yukle' && !$hatalar) {
        $tutarMetin = trim((string)($_POST['yukleme_tutari'] ?? ''));

        if ($tutarMetin === '') {
            $hatalar[] = 'Yükleme tutarını boş bırakmamalısın.';
        } else {
            $temiz = str_replace(['TL', 'tl', ' '], '', $tutarMetin);
            $temiz = str_replace('.', '', $temiz);
            $temiz = str_replace(',', '.', $temiz);

            if (!is_numeric($temiz) || (float)$temiz <= 0) {
                $hatalar[] = 'Tutarı sayıyla yazmalısın.';
            } else {
                $tutarKurus = (int) round(((float)$temiz) * 100);

                if ($tutarKurus < 5000) {
                    $hatalar[] = 'En az 50 TL yükleyebilirsin.';
                } elseif ($tutarKurus > 1000000) {
                    $hatalar[] = 'Tek seferde 10.000 TL üzerinde yükleme kabul etmiyoruz.';
                } else {
                    $yukle = $pdo->prepare('UPDATE user SET balance_kurus = balance_kurus + :tutar WHERE id = :id');
                    $yukle->execute([
                        ':tutar' => $tutarKurus,
                        ':id' => $kullanici['id'],
                    ]);

                    $yeniBakiye = $kullanici['balance_kurus'] + $tutarKurus;
                    $_SESSION['aktif_kullanici']['balance_kurus'] = $yeniBakiye;
                    $kullanici['balance_kurus'] = $yeniBakiye;

                    $basarilar[] = number_format($tutarKurus / 100, 2, ',', '.') . ' TL hemen bakiyene eklendi.';
                }
            }
        }
    } elseif ($islem === 'bilet_iptal' && !$hatalar) {
        try {
            $biletId = guvenli_id($_POST['bilet_id'] ?? '', 'Geçersiz bilet seçimi.');

            $pdo->beginTransaction();

            $sorgu = $pdo->prepare(
                "SELECT tk.id, tk.trip_id, tk.seat_number, tk.price_paid_kurus, tk.status, tr.departure_time
                 FROM tickets tk
                 JOIN trips tr ON tr.id = tk.trip_id
                 WHERE tk.id = :id AND tk.user_id = :user_id"
            );
            $sorgu->execute([
                ':id' => $biletId,
                ':user_id' => $kullanici['id'],
            ]);
            $bilet = $sorgu->fetch(PDO::FETCH_ASSOC);

            if (!$bilet) {
                throw new RuntimeException('Bu bileti bulamadık, silinmiş olabilir.');
            }
            if ($bilet['status'] !== 'ALINDI') {
                throw new RuntimeException('Bu bilet zaten iptal edilmiş.');
            }

            $simdi = new DateTimeImmutable('now');
            $kalkis = new DateTimeImmutable($bilet['departure_time']);
            if (($kalkis->getTimestamp() - $simdi->getTimestamp()) < 3600) {
                throw new RuntimeException('Kalkışa 1 saatten az kaldığı için artık iptal edemiyoruz.');
            }

            $iptal = $pdo->prepare("UPDATE tickets SET status = 'IPTAL', cancelled_at = CURRENT_TIMESTAMP WHERE id = :id");
            $iptal->execute([':id' => $biletId]);

            $koltukBosalt = $pdo->prepare('DELETE FROM booked_seats WHERE trip_id = :trip AND seat_number = :seat');
            $koltukBosalt->execute([
                ':trip' => $bilet['trip_id'],
                ':seat' => $bilet['seat_number'],
            ]);

            $bakiyeIade = $pdo->prepare('UPDATE user SET balance_kurus = balance_kurus + :tutar WHERE id = :id');
            $bakiyeIade->execute([
                ':tutar' => (int) $bilet['price_paid_kurus'],
                ':id' => $kullanici['id'],
            ]);

            $pdo->commit();

            $yeniBakiye = $kullanici['balance_kurus'] + (int) $bilet['price_paid_kurus'];
            $_SESSION['aktif_kullanici']['balance_kurus'] = $yeniBakiye;
            $kullanici['balance_kurus'] = $yeniBakiye;

            $basarilar[] = 'Bilet iptal edildi, ücret hesabına geri döndü.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $hatalar[] = $e->getMessage();
        }
    }
}

$biletSorgu = $pdo->prepare(
    "SELECT tk.id, tk.trip_id, tk.status, tk.seat_number, tk.price_paid_kurus, tk.purchased_at, tk.cancelled_at,
        tk.coupon_code, tk.pdf_path, tr.origin_city, tr.destination_city, tr.departure_time, tr.arrival_time,
        bc.name AS firma_adi
     FROM tickets tk
     JOIN trips tr ON tr.id = tk.trip_id
     JOIN bus_company bc ON bc.id = tr.company_id
     WHERE tk.user_id = :user_id
     ORDER BY tk.purchased_at DESC"
);
$biletSorgu->execute([':user_id' => $kullanici['id']]);
$biletler = $biletSorgu->fetchAll(PDO::FETCH_ASSOC);

foreach ($biletler as &$bilet) {
    if (!$bilet['pdf_path']) {
        continue;
    }

    $relativePath = ltrim($bilet['pdf_path'], '/');
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    $lines = [
        'Bilet Numarasi: ' . $bilet['id'],
        'Sefer: ' . $bilet['firma_adi'] . ' - ' . $bilet['origin_city'] . ' -> ' . $bilet['destination_city'],
        'Koltuk: #' . $bilet['seat_number'],
        'Kalkis: ' . $bilet['departure_time'],
        'Varis: ' . $bilet['arrival_time'],
        'Odenen Tutar: ' . number_format($bilet['price_paid_kurus'] / 100, 2, ',', '.') . ' TL',
        'Kupon: ' . ($bilet['coupon_code'] ?: 'Yok'),
        'Satin Alma: ' . ($bilet['purchased_at'] ?? ''),
    ];

    $targetDir = dirname($absolutePath);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    bilet_pdf_olustur($absolutePath, $lines);
}
unset($bilet);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Hesabım</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="user-meta">
        <span><?= htmlspecialchars($kullanici['full_name']) ?></span>
        <span class="badge">Bakiye: <?= number_format($kullanici['balance_kurus'] / 100, 2, ',', '.') ?> TL</span>
      </div>
      <nav class="nav-links">
        <a href="/sayfalar/ana-sayfa.php">Ana Sayfa</a>
        <a href="/sayfalar/hesabim.php" class="btn">Hesabım</a>
        <?php if (admin_mi($kullanici)): ?>
          <a href="/sayfalar/admin-panel.php">Admin Paneli</a>
        <?php endif; ?>
        <?php if (firma_admin_mi($kullanici)): ?>
          <a href="/sayfalar/firma-panel.php">Firma Paneli</a>
        <?php endif; ?>
        <a href="/sayfalar/cikis.php">Çıkış</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="page-intro">
      <h1>Hesabım ve Biletlerim</h1>
      <p class="lead">Buradan bakiyeni kontrol edebilir, istersen bilet iptal edip ücretini geri alabilirsin.</p>
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
      <h2>Biletlerim</h2>
      <?php if (!$biletler): ?>
        <div class="empty-state">Şimdilik alınmış bir biletin görünmüyor.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Sefer</th>
              <th>Koltuk</th>
              <th>Kalkış</th>
              <th>Varış</th>
              <th>Durum</th>
              <th>Ücret</th>
              <th>İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($biletler as $bilet): ?>
              <?php
                $iptalEdilebilir = ($bilet['status'] === 'ALINDI')
                  && ((new DateTimeImmutable($bilet['departure_time']))->getTimestamp() - (new DateTimeImmutable('now'))->getTimestamp() >= 3600);
              ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($bilet['firma_adi']) ?></strong><br>
                  <?= htmlspecialchars($bilet['origin_city']) ?> → <?= htmlspecialchars($bilet['destination_city']) ?>
                </td>
                <td>#<?= htmlspecialchars($bilet['seat_number']) ?></td>
                <td><?= htmlspecialchars($bilet['departure_time']) ?></td>
                <td><?= htmlspecialchars($bilet['arrival_time']) ?></td>
                <td>
                  <?php if ($bilet['status'] === 'ALINDI'): ?>
                    <span class="badge">Aktif</span>
                  <?php else: ?>
                    <span class="badge badge-muted">İptal</span><br>
                    <small><?= htmlspecialchars($bilet['cancelled_at'] ?? '') ?></small>
                  <?php endif; ?>
                </td>
                <td><?= number_format($bilet['price_paid_kurus'] / 100, 2, ',', '.') ?> TL</td>
                <td>
                  <?php if ($bilet['pdf_path']): ?>
                    <a class="btn" href="<?= htmlspecialchars('/' . ltrim($bilet['pdf_path'], '/')) ?>" target="_blank">PDF</a>
                  <?php endif; ?>
                  <?php if ($iptalEdilebilir): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_token_form(); ?>
                      <input type="hidden" name="islem" value="bilet_iptal">
                      <input type="hidden" name="bilet_id" value="<?= htmlspecialchars($bilet['id']) ?>">
                      <button type="submit">İptal Et</button>
                    </form>
                  <?php elseif ($bilet['status'] === 'ALINDI'): ?>
                    <small>İptal süresi dolmuş</small>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Bakiye Yükleme</h2>
      <p class="muted">Alışverişe devam etmek için bakiyeni buradan yükseltebilirsin. Limitler: minimum 50 TL, maksimum 10.000 TL.</p>
      <form method="post" class="stack-form">
        <?= csrf_token_form(); ?>
        <input type="hidden" name="islem" value="bakiye_yukle">
        <label>
          Yükleme Tutarı (TL)
          <input type="number" name="yukleme_tutari" min="50" max="10000" step="10" required placeholder="Örn. 100">
        </label>
        <div class="actions">
          <button type="submit">Bakiyemi Yükle</button>
        </div>
      </form>
    </section>
  </main>

  <footer class="footer">
    Bilet Satış Platformu · <?= date('Y') ?>
  </footer>
</body>
</html>
