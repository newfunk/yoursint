<?php
declare(strict_types=1);

$v = (string) (@filemtime(__DIR__ . '/style.css') ?: time());
$jv = (string) (@filemtime(__DIR__ . '/app.js') ?: time());

$shareId = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['g'] ?? '')) ?? '';
$shareId = strlen($shareId) === 8 ? $shareId : '';
$share = null;
if ($shareId !== '' && is_file(__DIR__ . '/data/shares/' . $shareId . '.json')) {
    $raw = json_decode((string) file_get_contents(__DIR__ . '/data/shares/' . $shareId . '.json'), true);
    if (is_array($raw) && !empty($raw['poem'])) {
        $share = $raw;
    }
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$baseUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'dev.yoursitehulp.nl')
    . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/yourgame/yoursint')), '/') . '/';

$brand = htmlspecialchars((string) ($share['profile']['brand'] ?? $share['profile']['host'] ?? 'jouw bedrijf'), ENT_QUOTES, 'UTF-8');
$pageTitle = $share
    ? 'Sinterklaasgedicht voor ' . $brand . ' · YourSint'
    : 'YourSint — Sinterklaasgedicht voor jouw bedrijf | Yourhosting';
$poemSnippet = '';
if ($share) {
    $lines = preg_split('/\R/u', trim((string) $share['poem'])) ?: [];
    $poemSnippet = implode(' ', array_slice(array_values(array_filter(array_map('trim', $lines))), 0, 2));
    if (mb_strlen($poemSnippet) > 140) {
        $poemSnippet = mb_substr($poemSnippet, 0, 137) . '…';
    }
}
$pageDesc = $share
    ? ('Gedicht van Sinterklaas voor ' . strip_tags($brand) . ': ' . $poemSnippet)
    : 'Laat Sinterklaas een persoonlijk gedicht schrijven over jouw bedrijf — op basis van je website. Van Yourhosting.';
$ogUrl = $share ? ($baseUrl . '?g=' . rawurlencode($shareId)) : $baseUrl;
$ogImage = $baseUrl . 'assets/og-image.jpg';
$ogImageW = 1200;
$ogImageH = 630;
$ogImageAlt = 'YourSint: Joeri met sintmijter. Sinterklaas schrijft een gedicht over jouw bedrijf.';
if (!empty($share['screenshot']) && is_file(__DIR__ . '/' . $share['screenshot'])) {
    $shotSize = @getimagesize(__DIR__ . '/' . $share['screenshot']);
    if (is_array($shotSize) && $shotSize[0] > 0 && $shotSize[1] > 0) {
        $ogImage = $baseUrl . ltrim((string) $share['screenshot'], '/');
        $ogImageW = (int) $shotSize[0];
        $ogImageH = (int) $shotSize[1];
        $ogImageAlt = 'Screenshot van de website van ' . html_entity_decode($brand, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#7A1424">
  <meta name="description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="canonical" href="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="YourSint · Yourhosting">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:locale" content="nl_NL">
  <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:secure_url" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="<?= $ogImageW ?>">
  <meta property="og:image:height" content="<?= $ogImageH ?>">
  <meta property="og:image:alt" content="<?= htmlspecialchars($ogImageAlt, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:image:alt" content="<?= htmlspecialchars($ogImageAlt, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" href="favicon.ico" sizes="any">
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="icon" href="assets/icon-32.png" type="image/png" sizes="32x32">
  <link rel="icon" href="assets/icon-192.png" type="image/png" sizes="192x192">
  <link rel="apple-touch-icon" href="apple-touch-icon.png" sizes="180x180">
  <link rel="manifest" href="site.webmanifest">
  <meta name="apple-mobile-web-app-title" content="YourSint">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css?v=<?= htmlspecialchars($v) ?>">
</head>
<body<?= $share ? ' class="has-share"' : '' ?>>
  <div class="sparkles" aria-hidden="true"></div>

  <header class="topbar">
    <a class="brand" href="https://www.yourhosting.nl/" target="_blank" rel="noopener">
      <img src="assets/yh-logo.svg" alt="Yourhosting" class="logo">
    </a>
    <span class="season-pill">5 december · YourSint</span>
  </header>

  <main class="stage">
    <!-- Start -->
    <section class="panel" id="scrStart"<?= $share ? ' hidden' : '' ?>>
      <div class="hero-copy">
        <img class="joeri-hero" src="assets/joeri-sint.jpg" width="160" height="160" alt="Joeri met sintmijter" decoding="async">
        <p class="eyebrow">Yourhosting · Sint-editie</p>
        <h1>Your<span>Sint</span></h1>
        <p class="lead">Vul de URL van je website in. Sinterklaas leest je homepage… en schrijft er een persoonlijk gedicht over.</p>
      </div>

      <form class="url-form" id="startForm" autocomplete="on">
        <label for="inUrl">Website-URL</label>
        <div class="url-row">
          <input id="inUrl" name="url" type="text" inputmode="url" placeholder="jouwbedrijf.nl" required maxlength="300" autocomplete="url">
          <button class="btn" type="submit" id="btnStart">Maak mijn gedicht</button>
        </div>
        <p class="hint">Geen login nodig. Je kunt zo vaak een nieuw gedicht maken als je wilt.</p>
        <p class="status" id="startStatus" role="status"></p>
      </form>

      <div class="loading" id="loadingBox" hidden>
        <img class="joeri-load" src="assets/joeri-sint.jpg" width="96" height="96" alt="" aria-hidden="true" decoding="async">
        <ol class="steps" id="loadSteps">
          <li data-step="fetch">Homepage ophalen…</li>
          <li data-step="shot">Screenshot maken…</li>
          <li data-step="poem">Sinterklaas schrijft… (tekst of screenshot)</li>
        </ol>
      </div>
    </section>

    <!-- Resultaat -->
    <section class="panel result" id="scrResult"<?= $share ? '' : ' hidden' ?>>
      <div class="result-grid">
        <aside class="site-card">
          <div class="browser">
            <div class="browser-bar">
              <span class="dot"></span><span class="dot"></span><span class="dot"></span>
              <span class="browser-url" id="resultUrl">https://…</span>
            </div>
            <div class="browser-body" id="shotBox">
              <div class="shot-placeholder">Screenshot laden…</div>
            </div>
          </div>
          <div class="site-meta">
            <h2 id="resultBrand">Bedrijf</h2>
            <p id="resultTitle" class="muted"></p>
          </div>
        </aside>

        <article class="poem-card" id="poemCard">
          <header class="poem-head">
            <button type="button" class="seal" id="sealBtn" title="S" aria-label="Zegel van Sinterklaas">S</button>
            <div>
              <p class="poem-kicker">Gedicht van Sinterklaas</p>
              <h2 id="poemFor">Voor jouw bedrijf</h2>
            </div>
            <img class="joeri-poem" src="assets/joeri-sint.jpg" width="72" height="72" alt="" aria-hidden="true" decoding="async">
          </header>
          <pre class="poem" id="poemText"></pre>
          <div class="poem-actions">
            <button type="button" class="btn" id="btnShare">Deel link</button>
            <button type="button" class="btn ghost" id="btnAgain">Nog een gedicht</button>
            <button type="button" class="btn ghost" id="btnCopy">Kopieer tekst</button>
            <button type="button" class="btn ghost" id="btnRestart">Andere website</button>
          </div>
          <div class="share-box" id="shareBox" hidden>
            <label for="shareUrl">Deellink</label>
            <div class="share-row">
              <input type="text" id="shareUrl" readonly>
              <button type="button" class="btn ghost" id="btnCopyLink">Kopieer</button>
              <a class="btn ghost" id="btnWhatsApp" href="#" target="_blank" rel="noopener">WhatsApp</a>
            </div>
          </div>
          <div class="egg-box" id="eggBox" hidden>
            <p class="egg-title">Psst… gevonden!</p>
            <p class="egg-text">Wil je dat Sinterklaas dit gedicht als écht sinterklaasliedje zingt?</p>
            <button type="button" class="btn" id="btnSong">Maak een liedje</button>
            <p class="status" id="songStatus" role="status"></p>
            <div class="song-player" id="songPlayer" hidden></div>
          </div>
          <p class="status" id="resultStatus" role="status"></p>
        </article>
      </div>

      <aside class="nova-cta">
        <p><strong>Leuk, hè?</strong> Met YourNova bouw je in minuten een website die écht bij jouw bedrijf past — AI die meedenkt, jij die beslist.</p>
        <a class="btn ghost" href="https://www.yourhosting.nl/" target="_blank" rel="noopener">Ontdek Yourhosting →</a>
      </aside>
    </section>
  </main>

  <!-- Modal (geen alert) -->
  <div class="modal" id="modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <h3 id="modalTitle">Melding</h3>
      <p id="modalMsg"></p>
      <button type="button" class="btn" id="modalOk">Sluiten</button>
    </div>
  </div>

  <script>
    window.YOURSINT_BOOT = <?= json_encode([
        'shareId' => $shareId ?: null,
        'share' => $share ? [
            'ok' => true,
            'poem' => $share['poem'] ?? '',
            'profile' => $share['profile'] ?? [],
            'screenshot' => $share['screenshot'] ?? null,
            'share_id' => $share['id'] ?? $shareId,
            'share_url' => $ogUrl,
            'song' => $share['song'] ?? null,
        ] : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="app.js?v=<?= htmlspecialchars($jv) ?>" defer></script>
</body>
</html>
