<?php
require_once __DIR__ . '/../ayarlar/veritabani.php';
require_once __DIR__ . '/../genel/oturum.php';

$pdo = vt_baglanti();
$kullanici = aktif_kullanici($pdo);

$nereden = trim($_GET['nereden'] ?? '');
$nereye  = trim($_GET['nereye'] ?? '');

function normalize_turkish_text(string $metin): string
{
    static $harita = [
        "\u{00C7}" => 'C',
        "\u{00E7}" => 'c',
        "\u{011E}" => 'G',
        "\u{011F}" => 'g',
        "\u{0130}" => 'I',
        'I'        => 'I',
        "\u{0131}" => 'i',
        "\u{00D6}" => 'O',
        "\u{00F6}" => 'o',
        "\u{015E}" => 'S',
        "\u{015F}" => 's',
        "\u{00DC}" => 'U',
        "\u{00FC}" => 'u',
        "\u{00C2}" => 'A',
        "\u{00E2}" => 'a',
        "\u{00CA}" => 'E',
        "\u{00EA}" => 'e',
        "\u{00D4}" => 'O',
        "\u{00F4}" => 'o',
        "\u{00DB}" => 'U',
        "\u{00FB}" => 'u',
    ];

    return strtr($metin, $harita);
}

function turkish_city_label(string $sehir, array $harita): string
{
    $anahtar = normalize_turkish_text($sehir);
    return $harita[$anahtar] ?? $sehir;
}

$bilinenSehirler = [
    'Ankara',
    "\u{0130}stanbul",
    "\u{0130}zmir",
    'Antalya',
    'Mersin',
    "Mu\u{011F}la",
    "Ayd\u{131}n",
    'Bursa',
    "Bal\u{131}kesir",
    "Karab\u{FC}k",
    'Trabzon',
];

$turkceSehirHaritasi = [];
foreach ($bilinenSehirler as $sehirAdi) {
    $turkceSehirHaritasi[normalize_turkish_text($sehirAdi)] = $sehirAdi;
}

$sql = "SELECT t.id,
               bc.name AS firma,
               t.origin_city,
               t.destination_city,
               t.departure_time,
               t.arrival_time,
               t.price_kurus,
               t.capacity
        FROM trips t
        JOIN bus_company bc ON bc.id = t.company_id
        WHERE 1 = 1";
$parametreler = [];

if ($nereden !== '') {
    $sql .= " AND t.origin_city LIKE :origin";
    $parametreler[':origin'] = '%' . normalize_turkish_text($nereden) . '%';
}
if ($nereye !== '') {
    $sql .= " AND t.destination_city LIKE :destination";
    $parametreler[':destination'] = '%' . normalize_turkish_text($nereye) . '%';
}

$sql .= " ORDER BY t.departure_time ASC";

$st = $pdo->prepare($sql);
$st->execute($parametreler);
$seferler = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <title>Bilet Sat&#305;&#351; Platformu - Ana Sayfa</title>
  <link rel="stylesheet" href="/stil.css">
</head>
<body>
  <header class="topbar">
    <div class="container">
      <div class="user-meta">
        <?php if ($kullanici): ?>
          <span><?= htmlspecialchars($kullanici['full_name']) ?></span>
          <span class="badge">Bakiye: <?= number_format($kullanici['balance_kurus'] / 100, 2, ',', '.') ?> TL</span>
        <?php else: ?>
          <span>Bilet Sat&#305;&#351; Platformu</span>
        <?php endif; ?>
      </div>
      <nav class="nav-links">
        <a href="/sayfalar/ana-sayfa.php">Ana Sayfa</a>
        <?php if ($kullanici): ?>
          <?php if (yolcu_mu($kullanici)): ?>
            <a href="/sayfalar/hesabim.php">Hesab&#305;m</a>
          <?php endif; ?>
          <?php if (admin_mi($kullanici)): ?>
            <a href="/sayfalar/admin-panel.php">Admin Panel</a>
          <?php endif; ?>
          <?php if (firma_admin_mi($kullanici)): ?>
            <a href="/sayfalar/firma-panel.php">Firma Paneli</a>
          <?php endif; ?>
          <a href="/sayfalar/cikis.php">&#199;&#305;k&#305;&#351;</a>
        <?php else: ?>
          <a href="/sayfalar/giris.php">Giri&#351; Yap</a>
          <a href="/sayfalar/kayit.php" class="btn">Kay&#305;t Ol</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="page-intro">
      <h1>Otob&#252;s Seferlerini Ke&#351;fet</h1>
      <p class="lead">Kalk&#305;&#351; ve var&#305;&#351; noktalar&#305;n&#305; se&#231;erek an&#305;nda uygun seferleri listeleyebilir, dilersen detay sayfas&#305;ndan koltuk se&#231;imine ilerleyebilirsin.</p>
    </section>

    <section class="panel">
      <h2>Sefer Arama</h2>
      <form method="get" class="inline-form">
        <label>
          Nereden
          <input name="nereden" list="sehirler" placeholder="&#214;rn. Karab&#252;k" value="<?= htmlspecialchars($nereden) ?>">
        </label>
        <label>
          Nereye
          <input name="nereye" list="sehirler" placeholder="&#214;rn. Ankara" value="<?= htmlspecialchars($nereye) ?>">
        </label>
        <div class="actions">
          <button type="submit">Seferleri Listele</button>
          <?php if ($nereden !== '' || $nereye !== ''): ?>
            <a class="btn" href="/sayfalar/ana-sayfa.php">Filtreyi temizle</a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Sefer Sonu&#231;lar&#305;</h2>
      <?php if (!$seferler): ?>
        <div class="empty-state">
          &#350;u an g&#246;sterilecek sefer bulunamad&#305;. Farkl&#305; bir kalk&#305;&#351; veya var&#305;&#351; noktas&#305; deneyebilirsin.
        </div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Firma</th>
              <th>Kalk&#305;&#351;</th>
              <th>Var&#305;&#351;</th>
              <th>Hareket</th>
              <th>Var&#305;&#351;</th>
              <th>Fiyat</th>
              <th>&#304;&#351;lem</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($seferler as $sefer): ?>
              <tr>
                <td><?= htmlspecialchars($sefer['firma']) ?></td>
                <td><?= htmlspecialchars(turkish_city_label($sefer['origin_city'], $turkceSehirHaritasi)) ?></td>
                <td><?= htmlspecialchars(turkish_city_label($sefer['destination_city'], $turkceSehirHaritasi)) ?></td>
                <td><?= htmlspecialchars($sefer['departure_time']) ?></td>
                <td><?= htmlspecialchars($sefer['arrival_time']) ?></td>
                <td><?= number_format($sefer['price_kurus'] / 100, 2, ',', '.') ?> TL</td>
                <td>
                  <a class="btn" href="/sayfalar/sefer.php?id=<?= urlencode($sefer['id']) ?>">Detay</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>

  <footer class="footer">
    Bilet Sat&#305;&#351; Platformu &copy; <?= date('Y') ?>
  </footer>

  <datalist id="sehirler">
    <?php foreach ($bilinenSehirler as $sehirAdi): ?>
      <option value="<?= htmlspecialchars($sehirAdi) ?>">
    <?php endforeach; ?>
  </datalist>
</body>
</html>
