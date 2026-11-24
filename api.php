<?php
/**
 * YourSint API — scrape homepage + Sinterklaasgedicht genereren.
 * Geen login. Rate-limited. SSRF-veilig (zelfde aanpak als Happy Birds).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$CFG = [
    'openai_api_key'     => '',
    'openai_model'       => 'gpt-5.2',
    'apiflash_key'       => '',
    'elevenlabs_api_key' => '',
    'elevenlabs_music'   => 'music_v2_5',
];
if (is_file(__DIR__ . '/config.php')) {
    $CFG = array_merge($CFG, (array) require __DIR__ . '/config.php');
}

$DATA = __DIR__ . '/data';
@mkdir("$DATA/shots", 0775, true);
@mkdir("$DATA/cache", 0775, true);
@mkdir("$DATA/shares", 0775, true);
@mkdir("$DATA/songs", 0775, true);

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ip_is_public(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return false;
    }
    $deny = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/3'];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $n = ip2long($ip);
        foreach ($deny as $c) {
            [$net, $bits] = explode('/', $c);
            $mask = -1 << (32 - (int) $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return true;
    }
    $l = strtolower($ip);
    return !preg_match('~^(::|::1|f[cd]|fe[89ab]|ff|::ffff:|64:ff9b:|2001:db8:)~', $l);
}

function public_url(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 300) {
        return null;
    }
    if (!preg_match('~^https?://~i', $raw)) {
        $raw = 'https://' . $raw;
    }
    $p = parse_url($raw);
    if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    $scheme = strtolower($p['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port !== 80 && $port !== 443) {
        return null;
    }
    $host = strtolower(rtrim($p['host'], '.'));
    if (!preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $host)) {
        return null;
    }
    $ips = gethostbynamel($host) ?: [];
    foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
        if (!empty($r['ipv6'])) {
            $ips[] = $r['ipv6'];
        }
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) {
            return null;
        }
    }
    $path = $p['path'] ?? '/';
    $query = isset($p['query']) ? '?' . $p['query'] : '';
    return [
        'url'  => $scheme . '://' . $host . ($port !== 80 && $port !== 443 ? ':' . $port : '') . $path . $query,
        'host' => $host,
        'port' => $port,
        'ip'   => $ips[0],
    ];
}

function resolve_location(string $base, string $loc): string
{
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $loc)) {
        return $loc;
    }
    $b = parse_url($base);
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($loc, '//')) {
        return $b['scheme'] . ':' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return $origin . $loc;
    }
    if (str_starts_with($loc, '?')) {
        return $origin . ($b['path'] ?? '/') . $loc;
    }
    $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
    return $origin . $dir . $loc;
}

function safe_fetch(string $raw, int $maxBytes = 250000): array
{
    $u = public_url($raw);
    if (!$u) {
        return ['ok' => false, 'error' => 'Ongeldige of onbekende URL (domein niet gevonden).'];
    }
    for ($hop = 0; $hop <= 5; $hop++) {
        $ch = curl_init($u['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 18,
            CURLOPT_CONNECTTIMEOUT => 8,
            // Geen CURLOPT_RANGE: partial gzip (206) levert binair vuil i.p.v. HTML.
            CURLOPT_ENCODING       => '', // accepteer gzip/br en decodeer automatisch
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; YourSint/1.0; +https://www.yourhosting.nl/; scraped-for-sinterklaas-poem)',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
            ],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [
                $u['host'] . ':' . $u['port'] . ':' . (str_contains($u['ip'], ':') ? '[' . $u['ip'] . ']' : $u['ip']),
            ],
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $peer = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false || $code === 0) {
            return ['ok' => false, 'error' => 'Website niet bereikbaar' . ($err ? " ($err)" : '') . '.'];
        }
        if ($peer !== '' && !ip_is_public($peer)) {
            return ['ok' => false, 'error' => 'Ongeldige of onbekende URL (domein niet gevonden).'];
        }
        $headers = substr((string) $res, 0, $hsize);
        if ($code >= 300 && $code < 400 && preg_match('~^Location:\s*(\S+)~mi', $headers, $m)) {
            $u = public_url(resolve_location($u['url'], trim($m[1])));
            if (!$u) {
                return ['ok' => false, 'error' => 'Website stuurt door naar een niet-toegestaan adres.'];
            }
            continue;
        }
        $body = substr((string) $res, $hsize);
        // Truncate ná decompressie
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes);
        }
        return [
            'ok'   => true,
            'url'  => $u['url'],
            'host' => $u['host'],
            'code' => $code,
            'body' => $body,
        ];
    }
    return ['ok' => false, 'error' => 'Te veel doorverwijzingen.'];
}

function rate(string $key, int $max, int $win): bool
{
    global $DATA;
    $f = "$DATA/rate_" . md5($key . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
    $t = time();
    $h = is_file($f)
        ? array_filter((array) json_decode((string) file_get_contents($f), true), fn($x) => $x > $t - $win)
        : [];
    if (count($h) >= $max) {
        return false;
    }
    $h[] = $t;
    file_put_contents($f, json_encode(array_values($h)));
    return true;
}

function meta_content(string $html, string $name): string
{
    $name = preg_quote($name, '~');
    if (preg_match('~<meta[^>]+(?:name|property)=["\']' . $name . '["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']' . $name . '["\']~i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

/**
 * Zoek interne "Over ons" / About-links op de homepage (zelfde host).
 * @return list<string> absolute URLs, uniek, max 3
 */
function find_about_urls(string $html, string $baseUrl, string $host): array
{
    $hostNorm = strtolower(preg_replace('~^www\.~i', '', $host) ?: $host);
    $found = [];
    if (!preg_match_all('~<a\b[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)</a>~is', $html, $mm, PREG_SET_ORDER)) {
        return [];
    }
    $pathHints = [
        'over-ons', 'overons', 'over_ons', '/over', 'over-mij', 'overmij',
        'wie-zijn-wij', 'wiezijnewij', 'over-het-bedrijf', 'het-team', 'ons-team',
        'about-us', 'about', 'our-story', 'our-team', 'meet-the-team', 'wie-ik-ben',
    ];
    $labelHints = [
        'over ons', 'over mij', 'over het bedrijf', 'wie zijn wij', 'ons team',
        'het team', 'about us', 'about', 'our story', 'our team', 'kennismaken',
    ];
    foreach ($mm as $m) {
        $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $label = strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
            continue;
        }
        $abs = resolve_location($baseUrl, $href);
        $p = parse_url($abs);
        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
            continue;
        }
        $linkHost = strtolower(preg_replace('~^www\.~i', '', (string) $p['host']) ?: (string) $p['host']);
        if ($linkHost !== $hostNorm) {
            continue; // alleen zelfde domein
        }
        $path = strtolower((string) ($p['path'] ?? '/'));
        $pathScore = 0;
        foreach ($pathHints as $hint) {
            if ($hint === '/over') {
                if (preg_match('~(^|/)over/?$~', $path)) {
                    $pathScore = 3;
                    break;
                }
                continue;
            }
            if (str_contains($path, $hint)) {
                $pathScore = 2;
                break;
            }
        }
        $labelScore = 0;
        foreach ($labelHints as $hint) {
            if ($label === $hint || str_starts_with($label, $hint . ' ') || str_contains($label, $hint)) {
                $labelScore = ($label === $hint) ? 3 : 2;
                break;
            }
        }
        if ($pathScore === 0 && $labelScore === 0) {
            continue;
        }
        // Skip blog/nieuws over-artikelen
        if (preg_match('~/(blog|nieuws|news|artikel|post)/~i', $path)) {
            continue;
        }
        $score = $pathScore + $labelScore;
        $found[$abs] = max($score, $found[$abs] ?? 0);
    }
    arsort($found);
    return array_slice(array_keys($found), 0, 3);
}

/**
 * Haal platte tekstsnippets uit HTML (hergebruik extract-logica, compact).
 */
function html_to_content_text(string $html, int $maxLen = 2500): string
{
    $clean = preg_replace('~<(script|style|noscript|svg|iframe|nav|footer|header)[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
    $clean = preg_replace('~<!--.*?-->~s', ' ', $clean) ?? $clean;
    $snippets = [];
    if (preg_match_all('~<(p|li|h1|h2|h3)[^>]*>(.*?)</\1>~is', $clean, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $block) {
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($block[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($t) < 25) {
                continue;
            }
            if (preg_match('/cookie|privacy|inschrijven|nieuwsbrief|alle rechten voorbehouden/iu', $t)) {
                continue;
            }
            $snippets[] = $t;
            if (count($snippets) >= 22) {
                break;
            }
        }
    }
    $text = implode(' ', $snippets);
    if (mb_strlen($text) < 80) {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
    return ys_clean_text($text, $maxLen);
}

/**
 * Bepaal of het een eenmanszaak/zzp is of een bedrijf met team.
 * @return array{size: 'solo'|'team'|'unknown', confidence: 'high'|'medium'|'low', evidence: list<string>}
 */
function detect_company_size(string $text): array
{
    $t = mb_strtolower($text, 'UTF-8');
    $evidence = [];
    $solo = 0;
    $team = 0;

    $soloPatterns = [
        'eenmanszaak'           => 5,
        'zzp'                   => 4,
        'zelfstandig ondernemer'=> 4,
        'freelancer'            => 3,
        'freelancen'            => 3,
        'ik werk alleen'        => 4,
        'in mijn eentje'        => 4,
        'alleen aan het werk'   => 3,
        'mijn bedrijf'          => 2,
        'mijn praktijk'         => 2,
        'mijn studio'           => 2,
        'opgericht door mij'    => 4,
        'ik ben de oprichter'   => 3,
        'ik help'               => 1,
        'ik adviseer'           => 1,
        'ik ontwerp'            => 1,
        'ik maak'               => 1,
        'mijn naam is'          => 2,
        'hallo, ik ben'         => 2,
        'hoi, ik ben'           => 2,
    ];
    $teamPatterns = [
        'ons team'              => 4,
        'onze medewerkers'      => 5,
        'onze collega'          => 4,
        'het team van'          => 3,
        'werken bij ons'        => 4,
        'vacature'              => 3,
        'wij zijn met'          => 3,
        'medewerkers'           => 3,
        'collega\'s'            => 3,
        'collegas'              => 3,
        'ons personeel'         => 4,
        'onze mensen'           => 3,
        'fulltime'              => 1,
        'parttime'              => 1,
        'join our team'         => 4,
        'our team'              => 3,
        'employees'             => 3,
        'staff of'              => 3,
        'wij hebben een team'   => 4,
        'samen met mijn team'   => 4,
        'met een team van'      => 4,
    ];

    foreach ($soloPatterns as $pat => $w) {
        if (str_contains($t, $pat)) {
            $solo += $w;
            $evidence[] = 'solo:' . $pat;
        }
    }
    foreach ($teamPatterns as $pat => $w) {
        if (str_contains($t, $pat)) {
            $team += $w;
            $evidence[] = 'team:' . $pat;
        }
    }

    // Tel meervoudige "wij/onze" vs "ik/mijn" (zachte signalen)
    $ik = preg_match_all('/\bik\b/u', $t) ?: 0;
    $mijn = preg_match_all('/\bmijn\b/u', $t) ?: 0;
    $wij = preg_match_all('/\bwij\b/u', $t) ?: 0;
    $onze = preg_match_all('/\bonze\b/u', $t) ?: 0;
    if ($ik + $mijn >= 6 && ($wij + $onze) <= 2) {
        $solo += 2;
        $evidence[] = 'solo:ik/mijn-dominant';
    }
    if ($wij + $onze >= 8 && $ik <= 2) {
        $team += 2;
        $evidence[] = 'team:wij/onze-dominant';
    }

    // Expliciete aantallen: "team van 12", "15 medewerkers"
    if (preg_match('/\b(?:team van|met)\s+(\d{1,3})\b/u', $t, $m) && (int) $m[1] >= 2) {
        $team += 4;
        $evidence[] = 'team:aantal=' . $m[1];
    }
    if (preg_match('/\b(\d{1,3})\s+medewerkers\b/u', $t, $m) && (int) $m[1] >= 2) {
        $team += 5;
        $evidence[] = 'team:medewerkers=' . $m[1];
    }

    if ($solo === 0 && $team === 0) {
        return ['size' => 'unknown', 'confidence' => 'low', 'evidence' => []];
    }
    if ($solo >= $team + 2) {
        return [
            'size' => 'solo',
            'confidence' => $solo >= 5 ? 'high' : 'medium',
            'evidence' => array_slice($evidence, 0, 8),
        ];
    }
    if ($team >= $solo + 2) {
        return [
            'size' => 'team',
            'confidence' => $team >= 5 ? 'high' : 'medium',
            'evidence' => array_slice($evidence, 0, 8),
        ];
    }
    // Gelijkspel: kies de hoogste, anders unknown
    if ($solo > $team) {
        return ['size' => 'solo', 'confidence' => 'low', 'evidence' => array_slice($evidence, 0, 8)];
    }
    if ($team > $solo) {
        return ['size' => 'team', 'confidence' => 'low', 'evidence' => array_slice($evidence, 0, 8)];
    }
    return ['size' => 'unknown', 'confidence' => 'low', 'evidence' => array_slice($evidence, 0, 8)];
}

/**
 * Verrijk profiel met over-pagina + bedrijfsgrootte (solo/team).
 */
function enrich_profile_company_size(array $profile, string $homepageHtml): array
{
    $host = (string) ($profile['host'] ?? '');
    $base = (string) ($profile['url'] ?? '');
    if ($host === '' || $base === '') {
        $profile['company_size'] = 'unknown';
        $profile['company_size_confidence'] = 'low';
        return $profile;
    }

    $aboutUrls = find_about_urls($homepageHtml, $base, $host);
    $aboutText = '';
    $aboutUrl = '';
    foreach ($aboutUrls as $cand) {
        $fetch = safe_fetch($cand, 180000);
        if (!$fetch['ok'] || ($fetch['code'] ?? 0) >= 400) {
            continue;
        }
        $txt = html_to_content_text((string) $fetch['body'], 2500);
        if (mb_strlen($txt) < 60) {
            continue;
        }
        $aboutText = $txt;
        $aboutUrl = (string) ($fetch['url'] ?? $cand);
        break;
    }

    $combined = trim(($profile['text'] ?? '') . "\n" . $aboutText);
    $det = detect_company_size($combined);
    // Extra: als alleen about-pagina sterk signaleert, geef die voorrang
    if ($aboutText !== '') {
        $aboutDet = detect_company_size($aboutText);
        if ($aboutDet['size'] !== 'unknown'
            && ($aboutDet['confidence'] === 'high'
                || ($det['size'] === 'unknown')
                || ($aboutDet['confidence'] === 'medium' && $det['confidence'] === 'low'))
        ) {
            $det = $aboutDet;
        }
        $profile['about_url'] = $aboutUrl;
        $profile['about_text'] = $aboutText;
        // Voeg about-tekst toe aan feitenbron (niet eindeloos)
        $merged = ys_clean_text(trim((string) ($profile['text'] ?? '') . "\n\n[Over-pagina]\n" . $aboutText), 4500);
        $profile['text'] = $merged;
        if (($profile['quality'] ?? '') === 'thin' && mb_strlen($aboutText) > 120) {
            $profile['quality'] = 'ok';
        }
    }

    $profile['company_size'] = $det['size'];
    $profile['company_size_confidence'] = $det['confidence'];
    $profile['company_size_evidence'] = $det['evidence'];
    return $profile;
}

function extract_site_profile(string $html, string $url, string $host): array
{
    // Binair/gzip-vuil herkennen (oude RANGE-bug of mislukte decompressie)
    $sample = substr($html, 0, 200);
    $printable = 0;
    $len = strlen($sample);
    for ($i = 0; $i < $len; $i++) {
        $c = ord($sample[$i]);
        if ($c === 9 || $c === 10 || $c === 13 || ($c >= 32 && $c < 127)) {
            $printable++;
        }
    }
    if ($len > 40 && ($printable / $len) < 0.7) {
        return [
            'url' => $url,
            'host' => preg_replace('~^www\.~i', '', $host) ?: $host,
            'brand' => '',
            'title' => '',
            'description' => '',
            'headings' => [],
            'text' => '',
            'quality' => 'binary',
            'fetched_at' => time(),
        ];
    }

    $title = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $desc = meta_content($html, 'description');
    if ($desc === '') {
        $desc = meta_content($html, 'og:description');
    }
    $ogTitle = meta_content($html, 'og:title');
    if ($ogTitle !== '' && ($title === '' || strcasecmp($title, $ogTitle) === 0 || preg_match('~^' . preg_quote($ogTitle, '~') . '\s*[-|]~iu', $title))) {
        // og:title is vaak schoner; gebruik 'm als paginatitel zwak is
        if ($title === '' || preg_match('/^' . preg_quote($ogTitle, '/') . '\s*[-–—|]\s*' . preg_quote($ogTitle, '/') . '$/iu', $title)) {
            $title = $ogTitle;
        }
    }
    if ($ogTitle !== '' && $title === '') {
        $title = $ogTitle;
    }

    $headings = [];
    if (preg_match_all('~<h([1-3])[^>]*>(.*?)</h\1>~is', $html, $mm, PREG_SET_ORDER)) {
        foreach (array_slice($mm, 0, 12) as $h) {
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t !== '' && mb_strlen($t) > 2) {
                $headings[] = $t;
            }
        }
    }

    // Belangrijkste content: paragrafen + lijstitems (niet heel de menu-brij)
    $clean = preg_replace('~<(script|style|noscript|svg|iframe|nav|footer|header)[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
    $clean = preg_replace('~<!--.*?-->~s', ' ', $clean) ?? $clean;
    $snippets = [];
    if (preg_match_all('~<(p|li|h1|h2|h3)[^>]*>(.*?)</\1>~is', $clean, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $block) {
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($block[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($t) < 35) {
                continue;
            }
            // Skip cookies / nav-clutter
            if (preg_match('/cookie|privacy|inschrijven|nieuwsbrief|alle rechten voorbehouden/iu', $t)) {
                continue;
            }
            $snippets[] = $t;
            if (count($snippets) >= 18) {
                break;
            }
        }
    }
    $text = implode(' ', $snippets);
    if (mb_strlen($text) < 120) {
        // Fallback: hele pagina
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
    }
    if (mb_strlen($text) > 3500) {
        $text = mb_substr($text, 0, 3500) . '…';
    }

    $brand = preg_replace('~^www\.~i', '', $host) ?: $host;
    $brand = preg_replace('~\.(nl|com|be|eu|net|org|io|app|shop|online)$~i', '', $brand) ?: $brand;
    $brand = mb_convert_case(str_replace(['-', '_'], ' ', $brand), MB_CASE_TITLE, 'UTF-8');
    if ($title !== '' && preg_match('~\s[\|\-–—]\s*([^|\-–—]{2,40})\s*$~u', $title, $tm)) {
        $cand = trim($tm[1]);
        if ($cand !== '' && mb_strlen($cand) <= 32 && substr_count($cand, ' ') <= 3) {
            $brand = $cand;
        }
    }
    // "Fave - Fave" → Fave
    if ($title !== '' && preg_match('/^(.+?)\s*[-–—|]\s*\1$/iu', $title, $tm)) {
        $title = trim($tm[1]);
        $brand = $title;
    }

    $cleanHeadings = [];
    foreach (array_slice($headings, 0, 10) as $h) {
        $h = ys_clean_text((string) $h, 120);
        if ($h !== '') {
            $cleanHeadings[] = $h;
        }
    }

    $text = ys_clean_text($text, 3500);
    $quality = 'ok';
    if (mb_strlen($text) < 80 && $desc === '' && $cleanHeadings === []) {
        $quality = 'thin';
    }

    return [
        'url'         => $url,
        'host'        => preg_replace('~^www\.~i', '', $host) ?: $host,
        'brand'       => ys_clean_text((string) $brand, 80),
        'title'       => ys_clean_text(mb_substr($title, 0, 180), 180),
        'description' => ys_clean_text(mb_substr($desc, 0, 500), 500),
        'headings'    => array_values(array_unique($cleanHeadings)),
        'text'        => $text,
        'quality'     => $quality,
        'fetched_at'  => time(),
    ];
}

function cache_key(string $host): string
{
    return sha1(strtolower($host));
}

function load_profile(string $host): ?array
{
    global $DATA;
    $f = $DATA . '/cache/' . cache_key($host) . '.json';
    if (!is_file($f) || filemtime($f) < time() - 3600) {
        return null;
    }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function save_profile(array $profile): void
{
    global $DATA;
    $f = $DATA . '/cache/' . cache_key($profile['host']) . '.json';
    file_put_contents($f, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function share_id_valid(string $id): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9]{8}$/', $id);
}

function share_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'dev.yoursitehulp.nl';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/yourgame/yoursint')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function save_share(string $poem, array $profile, ?string $screenshot): array
{
    global $DATA;
    $id = '';
    for ($i = 0; $i < 8; $i++) {
        $id = substr(bin2hex(random_bytes(6)), 0, 8);
        $path = "$DATA/shares/$id.json";
        if (!is_file($path)) {
            break;
        }
    }
    $payload = [
        'id'         => $id,
        'poem'       => $poem,
        'profile'    => [
            'host'            => $profile['host'] ?? '',
            'brand'           => $profile['brand'] ?? '',
            'title'           => $profile['title'] ?? '',
            'description'     => $profile['description'] ?? '',
            'url'             => $profile['url'] ?? '',
            'company_size'    => $profile['company_size'] ?? 'unknown',
            'about_url'       => $profile['about_url'] ?? '',
        ],
        'screenshot' => $screenshot,
        'created'    => time(),
    ];
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $url = share_public_base() . '?g=' . $id;
    return ['id' => $id, 'url' => $url];
}

function load_share(string $id): ?array
{
    global $DATA;
    if (!share_id_valid($id)) {
        return null;
    }
    $path = "$DATA/shares/$id.json";
    if (!is_file($path)) {
        return null;
    }
    // Shares blijven 90 dagen geldig
    if (filemtime($path) < time() - 90 * 86400) {
        return null;
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function fetch_screenshot(string $url, string $host): ?string
{
    global $CFG, $DATA;
    $rel = 'data/shots/' . cache_key($host) . '.jpg';
    $path = __DIR__ . '/' . $rel;
    if (is_file($path) && filemtime($path) > time() - 86400 && filesize($path) > 2000) {
        return $rel;
    }

    $img = null;
    $key = (string) ($CFG['apiflash_key'] ?? '');
    if ($key !== '') {
        $api = 'https://api.apiflash.com/v1/urltoimage'
            . '?access_key=' . rawurlencode($key)
            . '&url=' . rawurlencode($url)
            . '&format=jpeg&width=1280&height=900&response_type=image'
            . '&no_cookie_banners=true&no_ads=true&fresh=false&ttl=86400&quality=85';
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $res = curl_exec($ch);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($res !== false && $code === 200 && str_starts_with($ct, 'image/')) {
            $img = $res;
        }
    }

    // Geen lokale headless-Chrome-fallback meer (SSRF-risico): zonder ApiFlash geen screenshot.
    if (!$img) {
        return null;
    }

    $im = @imagecreatefromstring($img);
    if (!$im) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $cw = min($w, 1280);
    $ch2 = min($h, 900);
    $tw = 960;
    $th = (int) round($ch2 * ($tw / $cw));
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $cw, $ch2);
    imagejpeg($dst, $path, 82);
    imagedestroy($im);
    imagedestroy($dst);
    return is_file($path) ? $rel : null;
}

function poem_last_word(string $line): string
{
    $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $line = preg_replace('/[^\p{L}\p{N}\'\- ]+/u', '', $line) ?? $line;
    $parts = preg_split('/\s+/u', trim($line)) ?: [];
    $word = (string) end($parts);
    return mb_strtolower($word, 'UTF-8');
}

/**
 * Tel lettergrepen (Nederlands, benadering via klinkerclusters).
 * Genoeg voor metrum-controle: regels even lang, ~trocheïsche tetrameter.
 */
function poem_count_syllables(string $line): int
{
    $line = mb_strtolower(trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'UTF-8');
    $line = preg_replace('/[^\p{L}\s\'-]/u', ' ', $line) ?? $line;
    $total = 0;
    foreach (preg_split('/\s+/u', $line) ?: [] as $word) {
        $word = trim($word, "-'");
        if ($word === '') {
            continue;
        }
        $w = str_replace(['ĳ', 'ij'], 'Y', $word);
        // ae/oe/eu/ui/ou/au/ei/ey als één klankkern (Y dekt ij al)
        $w = preg_replace('/ae|oe|eu|ui|ou|au|ei|ey|aa|ee|oo|uu/u', 'V', $w) ?? $w;
        $n = preg_match_all('/[aeiouyáéíóúäëïöüVY]+/u', $w);
        $total += $n > 0 ? $n : 1;
    }
    return max(1, $total);
}

/**
 * Metrum/cadans-check voor sinterklaasrijm:
 * - per regel ~7–11 lettergrepen (trocheïsche tetrameter ± catalexis)
 * - rijmende regels (AABB-paar) ongeveer even lang
 * - geen extreme uitschieter in het hele gedicht
 *
 * @param list<string> $lines
 * @return list<string> problemen
 */
function poem_check_meter(array $lines): array
{
    $bad = [];
    $counts = [];
    foreach ($lines as $i => $line) {
        $n = poem_count_syllables($line);
        $counts[] = $n;
        if ($n < 7 || $n > 12) {
            $bad[] = 'regel ' . ($i + 1) . ": metrum kreupel ($n lettergrepen; mik op 8–10, bandbreedte 7–12)";
        }
    }
    $n = count($lines);
    for ($i = 0; $i + 1 < $n; $i += 2) {
        $a = $counts[$i] ?? 0;
        $b = $counts[$i + 1] ?? 0;
        if (abs($a - $b) > 3) {
            $bad[] = 'regels ' . ($i + 1) . '-' . ($i + 2)
                . ": ongelijke cadans ($a vs $b lettergrepen; max verschil 3)";
        }
    }
    if ($counts !== []) {
        $span = max($counts) - min($counts);
        if ($span > 4) {
            $bad[] = "gedicht wisselt te sterk in regellengte (min " . min($counts)
                . ", max " . max($counts) . "); houd alle regels ongeveer even lang";
        }
    }
    return $bad;
}

/** Alleen letters, lowercase. */
function poem_norm_word(string $word): string
{
    $word = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $word) ?? $word, 'UTF-8');
    return str_replace(['ĳ', 'ij'], 'Y', $word);
}

/**
 * Splits woord in klinker+coda-clusters (ruwe lettergrepen voor rijm).
 * @return list<string>
 */
function poem_syllable_parts(string $word): array
{
    $word = poem_norm_word($word);
    if ($word === '') {
        return [];
    }
    // Tweeklanken eerst samenvoegen tot één kern
    $word = preg_replace('/ae|oe|eu|ui|ou|au|ei|ey|aa|ee|oo|uu|Y/u', 'V', $word) ?? $word;
    if (!preg_match_all('/[aeiouyáéíóúäëïöüV]+[^aeiouyáéíóúäëïöüV]*/u', $word, $m)) {
        return [$word];
    }
    return $m[0];
}

/**
 * Rijmsleutel: bij ≥2 lettergrepen vanaf de voorlaatste kern (bv. indeling → "eling"),
 * anders vanaf de enige kern (kring → "ing"). Zo faalt indeling/kring terecht.
 */
function poem_rhyme_key(string $word): string
{
    $parts = poem_syllable_parts($word);
    if ($parts === []) {
        return '';
    }
    $n = count($parts);
    $key = $n >= 2 ? ($parts[$n - 2] . $parts[$n - 1]) : $parts[0];
    // Begin bij de klinker (onset weg): deling/telling → eling/elling
    $key = preg_replace('/^[^aeiouyáéíóúäëïöüV]+/u', '', $key) ?? $key;
    $key = mb_strtolower($key, 'UTF-8');
    $key = str_replace(['ouw'], ['ou'], $key);
    $key = preg_replace('/d$/u', 't', $key) ?? $key;
    $key = preg_replace('/^elling/u', 'eling', $key) ?? $key;
    // Zwakke restjes als enige sleutel zijn te mager
    return $key;
}

/** @deprecated alias — blijft voor debug/oude calls */
function poem_rhyme_tail(string $word): string
{
    return poem_rhyme_key($word);
}

/** Rijmsleutel van alleen de laatste lettergreep. */
function poem_last_syl_key(string $word): string
{
    $parts = poem_syllable_parts($word);
    if ($parts === []) {
        return '';
    }
    $key = (string) end($parts);
    $key = preg_replace('/^[^aeiouyáéíóúäëïöüV]+/u', '', $key) ?? $key;
    $key = preg_replace('/d$/u', 't', $key) ?? $key;
    return mb_strtolower($key, 'UTF-8');
}

function poem_words_rhyme(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }
    $al = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $a) ?? $a, 'UTF-8');
    $bl = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $b) ?? $b, 'UTF-8');
    if ($al === '' || $bl === '') {
        return false;
    }
    // Identiek eindwoord is GEEN rijm (plan/plan, klaar/klaar) — alleen herhaling.
    if ($al === $bl) {
        return false;
    }
    // Holrijm / zelfrijm via samenstelling: woonkamerplan/plan, kamerplan/plan — afwijzen.
    // Uitzondering: kort grammaticaal voorvoegsel zoals gezin/zin, bestaan/staan.
    $short = mb_strlen($al, 'UTF-8') <= mb_strlen($bl, 'UTF-8') ? $al : $bl;
    $long = $short === $al ? $bl : $al;
    if (mb_strlen($short, 'UTF-8') >= 3 && str_ends_with($long, $short) && $long !== $short) {
        $prefix = mb_substr($long, 0, mb_strlen($long, 'UTF-8') - mb_strlen($short, 'UTF-8'), 'UTF-8');
        if (!preg_match('/^(ge|be|ver|her|ont|a)$/u', $prefix)) {
            return false;
        }
        return true; // gezin/zin e.d.
    }

    $ka = poem_rhyme_key($a);
    $kb = poem_rhyme_key($b);
    $weak = ['e', 'en', 'er', 'el', 'em', 't', 's', 'n', 'ing', 'Vng'];
    if ($ka !== '' && $kb !== '' && $ka === $kb && mb_strlen($ka, 'UTF-8') >= 2
        && !in_array($ka, ['e', 'en', 'er', 'el', 'em', 't', 's', 'n'], true)
    ) {
        return true;
    }

    // Laatste lettergreep gelijk (seizoen/doen, wens/intens), maar NIET voor zwak -ing
    // bij ongelijk aantal lettergrepen (indeling/kring, woning/kring).
    $la = poem_last_syl_key($a);
    $lb = poem_last_syl_key($b);
    if ($la === '' || $lb === '' || $la !== $lb || mb_strlen($la, 'UTF-8') < 2) {
        return false;
    }
    if (in_array($la, $weak, true)) {
        $sa = count(poem_syllable_parts($a));
        $sb = count(poem_syllable_parts($b));
        // -ing mag alleen rijmen bij gelijk aantal lettergrepen (woning/kroning → al via full key)
        return $sa === $sb && $sa <= 2 && !in_array($la, ['ing', 'Vng'], true);
    }
    return true;
}

/**
 * @return array{ok:bool, bad_pairs:list<string>, lines:list<string>}
 */
function poem_check_aabb(string $poem): array
{
    $raw = preg_split('/\R/u', trim($poem)) ?: [];
    $lines = [];
    foreach ($raw as $l) {
        $l = trim($l);
        if ($l !== '') {
            $lines[] = $l;
        }
    }
    $n = count($lines);
    $bad = [];
    if ($n !== 8 && $n !== 12) {
        $bad[] = "verwacht exact 8 of 12 regels (nu $n)";
    }
    for ($i = 0; $i + 1 < $n; $i += 2) {
        $w1 = poem_last_word($lines[$i]);
        $w2 = poem_last_word($lines[$i + 1]);
        if ($w1 !== '' && $w1 === $w2) {
            $bad[] = 'regels ' . ($i + 1) . '-' . ($i + 2)
                . ": \"$w1\" / \"$w2\" is herhaling, geen rijm — kies twee verschillende woorden";
        } elseif (!poem_words_rhyme($w1, $w2)) {
            $bad[] = 'regels ' . ($i + 1) . '-' . ($i + 2) . ": \"$w1\" / \"$w2\"";
        }
    }
    $joined = mb_strtolower(implode(' ', $lines), 'UTF-8');
    $banned = [
        'vaar-na', 'oh zo blij', 'oh wat een', 'echt waar', 'vast heel fijn',
        'mijlpaal, blij', 'sint in de zee', 'cadeautje', 'goede sint in',
        'uitgebreide site', 'kijk eens rond', 'helemaal paraat', 'altijd goed, dat is',
        'passie en vakmanschap', 'fijne stream',
        'naar zoek', 'graag naar zoek', 'graag van hou', 'graag doe',
        'in zijn grote boek', // trekt bijna altijd geforceerd rijm aan
        'persoonlijke kring', 'jouw persoonlijke kring', 'in de kring',
        'zonder enige zwak', 'goed gehoord', 'dat wordt gehoord',
        'toch wel raar', 'toch een beetje raar', 'nietwaar',
        'vast heel fijn', 'oh zo blij', 'oh wat een',
        'dat mag je doen', // vaak lege rijmredder i.p.v. inhoud
    ];
    foreach ($banned as $b) {
        if (str_contains($joined, $b)) {
            $bad[] = "verboden vul/nonsens-patroon: \"$b\"";
        }
    }
    // Regel die alleen eindigt op een los emotiewoord na een komma → rijmdwang
    foreach ($lines as $idx => $line) {
        if (preg_match('/,\s*(blij|fijn|zijn|mee|zee|klaar|waar|goed|mooi|kring|pad|zaak)\s*$/iu', $line)) {
            $bad[] = 'regel ' . ($idx + 1) . ': geforceerd eindwoord na komma';
        }
        // Afgehakte werkwoorden om te rijmen (zoek/hou/doe/ga/kom zonder -t bij hij/zij)
        if (preg_match('/\b(hij|zij|men|sint|sinterklaas)\b.{0,40}\b(zoek|hou|doe|ga|kom|kijk|maak|vind)\s*$/iu', $line)) {
            $bad[] = 'regel ' . ($idx + 1) . ': werkwoord lijkt niet correct vervoegd (rijmdwang)';
        }
        // Abstracte "rijm-redders" zonder inhoud
        if (preg_match('/\b(persoonlijke\s+kring|enige\s+zwak|heel\s+intens)\s*[.!?]*\s*$/iu', $line)) {
            $bad[] = 'regel ' . ($idx + 1) . ': geforceerde rijmzin zonder betekenis';
        }
    }
    foreach (poem_check_meter($lines) as $meterIssue) {
        $bad[] = $meterIssue;
    }
    return ['ok' => $bad === [], 'bad_pairs' => $bad, 'lines' => $lines];
}

/** Maak tekst veilig voor JSON/OpenAI: geldige UTF-8, geen control chars. */
function ys_clean_text(string $text, int $maxLen = 0): string
{
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    // Verwijder NULL en overige C0/C1 controls behalve \n \r \t
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    // Vervang rare unicode separators / non-characters
    $text = preg_replace('/[\x{FFFE}\x{FFFF}\x{FEFF}]/u', '', $text) ?? $text;
    $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    if ($maxLen > 0 && mb_strlen($text, 'UTF-8') > $maxLen) {
        $text = mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return $text;
}

function screenshot_data_url(string $relPath): ?string
{
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    if (!preg_match('~^data/shots/[a-zA-Z0-9._-]+\.jpe?g$~', $relPath)) {
        return null;
    }
    $path = __DIR__ . '/' . $relPath;
    if (!is_file($path) || filesize($path) < 1000) {
        return null;
    }
    $bin = file_get_contents($path);
    if ($bin === false) {
        return null;
    }
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

function profile_needs_vision(array $profile): bool
{
    $quality = (string) ($profile['quality'] ?? 'ok');
    $textLen = mb_strlen((string) ($profile['text'] ?? ''), 'UTF-8');
    $descLen = mb_strlen((string) ($profile['description'] ?? ''), 'UTF-8');
    if (in_array($quality, ['binary', 'thin'], true)) {
        return true;
    }
    if ($textLen < 160 && $descLen < 40) {
        return true;
    }
    if ($textLen < 80) {
        return true;
    }
    return false;
}

function openai_chat(array $messages, float $temperature = 0.7): array
{
    global $CFG;
    $key = (string) ($CFG['openai_api_key'] ?? '');
    if ($key === '') {
        return ['ok' => false, 'error' => 'Gedichtendienst is nog niet geconfigureerd.'];
    }

    // Sanitize message contents (scraped HTML kan kapotte UTF-8 bevatten)
    foreach ($messages as &$msg) {
        if (!is_array($msg)) {
            continue;
        }
        if (isset($msg['role']) && is_string($msg['role'])) {
            $msg['role'] = ys_clean_text($msg['role'], 32);
        }
        if (isset($msg['content']) && is_string($msg['content'])) {
            $msg['content'] = ys_clean_text($msg['content']);
        } elseif (isset($msg['content']) && is_array($msg['content'])) {
            foreach ($msg['content'] as &$part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $part['text'] = ys_clean_text((string) $part['text']);
                }
            }
            unset($part);
        }
    }
    unset($msg);

    $model = (string) ($CFG['openai_model'] ?: 'gpt-5.2');
    $payload = [
        'model'       => $model,
        'temperature' => $temperature,
        'messages'    => $messages,
    ];
    // gpt-5.x: max_completion_tokens; oudere chat-modellen: max_tokens
    if (preg_match('/^gpt-5/i', $model) || preg_match('/^o[0-9]/i', $model)) {
        $payload['max_completion_tokens'] = 700;
    } else {
        $payload['max_tokens'] = 500;
    }
    $body = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($body === false || $body === '' || $body === 'null') {
        return [
            'ok'    => false,
            'error' => 'Kon het verzoek niet coderen (ongeldige tekens in website-tekst).',
            'debug' => ['json_error' => json_last_error_msg()],
        ];
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 55,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false) {
        return ['ok' => false, 'error' => 'Kon geen verbinding maken met de AI-dienst.' . ($err ? " ($err)" : '')];
    }
    $json = json_decode((string) $res, true);
    if ($code >= 400 || !is_array($json)) {
        $msg = is_array($json) ? ($json['error']['message'] ?? 'AI-fout') : 'AI-fout';
        return [
            'ok'    => false,
            'error' => 'Gedicht genereren mislukt: ' . $msg,
            'debug' => ['http' => $code, 'body_len' => strlen($body)],
        ];
    }
    $text = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
    $text = preg_replace('/^```(?:\w+)?\s*|\s*```$/u', '', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'De Sint had even geen inspiratie. Probeer opnieuw.'];
    }
    return ['ok' => true, 'text' => $text];
}

function generate_poem(array $profile, string $seed = '', ?string $screenshotRel = null): array
{
    global $CFG;
    if ((string) ($CFG['openai_api_key'] ?? '') === '') {
        return ['ok' => false, 'error' => 'Gedichtendienst is nog niet geconfigureerd.'];
    }

    $useVision = profile_needs_vision($profile);
    $imageDataUrl = null;
    if ($useVision && $screenshotRel) {
        $imageDataUrl = screenshot_data_url($screenshotRel);
    }
    // Vision alleen als we echt een plaatje hebben
    if ($useVision && !$imageDataUrl) {
        $useVision = false;
    }

    $companySize = (string) ($profile['company_size'] ?? 'unknown');
    if (!in_array($companySize, ['solo', 'team', 'unknown'], true)) {
        $companySize = 'unknown';
    }

    $angles = [
        'Focus op wat dit bedrijf uniek maakt voor klanten.',
        'Maak het speels en licht ironisch, zoals een klassiek sinterklaasgedicht.',
        'Schrijf alsof Sinterklaas het bedrijf net heeft ontdekt in zijn grote boek.',
        'Verwerk een knipoog naar de diensten of activiteiten van het bedrijf.',
    ];
    if ($companySize === 'solo') {
        $angles[] = 'Leg de nadruk op de ondernemer zelf: vakmanschap, persoonlijke aanpak, één persoon achter het werk.';
    } elseif ($companySize === 'team') {
        $angles[] = 'Leg de nadruk op het team achter het bedrijf en hun gezamenlijke vakmanschap.';
    } else {
        $angles[] = 'Focus op wat ze doen voor klanten; noem geen team of medewerkers tenzij dat duidelijk is.';
    }
    $angle = $angles[array_rand($angles)];
    if ($seed !== '') {
        $angle .= ' Variatie-seed: ' . mb_substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $seed) ?? '', 0, 40);
    }

    $sizeBlock = match ($companySize) {
        'solo' => <<<'SOLO'
BEDRIJFSGROOTTE: EENMANSZAAK / ZZP / ALLEEN ONDERNEMER (hoogste prioriteit)
- Schrijf in het enkelvoud over de ondernemer (hij/zij/die, of bij naam).
- NOOIT: team, medewerkers, collega's, "ze werken", "hun mensen", "het personeel".
- Wél: persoonlijke aanpak, zelf doen, eigen zaak, één-op-één, "in z'n eentje" / "zelf".
- Rijmwoorden kiezen die bij één persoon passen (zaak/plaats, advies/precies) — niet "team/dream".
SOLO,
        'team' => <<<'TEAM'
BEDRIJFSGROOTTE: BEDRIJF MET MEDEWERKERS / TEAM
- Je mag team, collega's of medewerkers noemen als dat past.
- Gebruik meervoud ("ze", "hun", "samen") wanneer je over de organisatie praat.
- Verzin geen exacte aantallen medewerkers tenzij die in de feiten staan.
TEAM,
        default => <<<'UNK'
BEDRIJFSGROOTTE: ONBEKEND
- Schrijf neutraal over "het bedrijf" / de merknaam.
- Noem GEEN team, medewerkers of "alleen werken" tenzij dat letterlijk in de feiten staat.
- Focus op diensten/producten voor klanten.
UNK,
    };

    $system = <<<PROMPT
Je bent Sinterklaas en schrijft Nederlandse sinterklaasgedichten over ondernemingen.
Je schrijft alsof het hardop voorgelezen wordt op pakjesavond: soepel ritme, geen kreupele regels.

OUTPUT
- Schrijf ALLEEN het gedicht: geen titel, geen inleiding, geen uitleg, geen markdown, geen aanhalingstekens, geen emoji's.

VORM (verplicht)
- Exact 8 of 12 regels (dus 4 of 6 coupletten van 2 regels).
- Rijmschema strikt AABB (gepaard rijm): regel 1↔2, 3↔4, enzovoort.
- Elke regel is één natuurlijke Nederlandse zin of zinsdeel.

METRUM / CADANS (verplicht — dit is waarom klassieke sinterklaasrijmpjes "lopen")
- Gebruik TROCHEEËN: beklemtoond–onbeklemtoond (TA-ta TA-ta TA-ta TA-ta).
  Voorbeeldritme: "SIN-ter-klaas ka-POEN-tje" / "ZIE ginds KOMT de STOOM-boot".
- Streef naar TROCHEÏSCHE TETRAMETER: ±4 heffingen per regel ≈ 8–10 lettergrepen.
  Bandbreedte: elke regel 7–11 lettergrepen. Nooit 5 of 14.
- Rijmende regels (een AABB-paar) moeten ongeveer EVEN LANG zijn (max ±2 lettergrepen verschil).
- Lees hardop in je hoofd: als je struikelt of moet versnellen/vertragen, herschrijf.
- Zet de klemtoon van het EINDwoord op het rijmende deel (niet "STRIPboek" als je op "boek" wilt rijmen).
- Formuleer zinnen zo dat het rijmwoord natuurlijk achteraan valt — herschik desnoods de zin
  (Onze Taal: niet "…dat jij vaak spijbelt" als slot, wél "…heeft Sinterklaas gehoord").
- Goed (loopt): "Je hebt dit jaar veel meegemaakt, dat weet de Sint heel goed."
- Fout (kreupel): "Sinterklaas weet goed dat jij in dit jaar best veel hebt meegemaakt."
- Fout (ongelijke lengte): korte regel + ellenlange regel in hetzelfde couplet.

Gouden regel (Onze Taal): INHOUD EERST, RIJM DAARNA — nooit andersom
- Een goed sinterklaasgedicht is inhoudelijk sterk. Rijm is het middel, niet het doel.
- Trek NOOIT rijmwoorden uit het ongerijmde: geen zin die alleen bestaat om een eindklank te redden.
- Werkwijze per couplet:
  1) Bedenk wát je wilt zeggen (feit over het bedrijf).
  2) Schrijf dat in twee zinnen die kloppen.
  3) Zoek pas daarna een rijm door te HERFORMULEREN (andere woordvolgorde / synoniem).
  4) Lukt het niet: gooi BEIDE regels weg en begin opnieuw met een ander feit — sleep geen los rijmwoord erbij.
- Test: schrap het eindrijmwoord — blijft er nog een zinvolle boodschap over? Zo nee → herschrijf.
- Goed: "Fay helpt met kleur en meubels op maat / Zo wordt jouw huis weer warm en prachtig strak."
  (beide regels zeggen iets over het bedrijf)
- Fout: "Zoek je hulp bij kleur of indeling / Bel Fay gerust voor jouw persoonlijke kring."
  ("kring" komt nergens vandaan — puur rijmdwang)

EINDRIJM
- Eindrijm = TWEE VERSCHILLENDE woorden met dezelfde klank (zelfde klinker ÉN slotklank).
  Goed: fijn/klein, mee/zee, plan/kan, kleur/deur, maat/staat, klaar/paar, deling/telling.
  Fout: plan/plan, klaar/klaar, winkel/winkel, stap/stap (herhaling ≠ rijm).
  Fout: woonkamerplan/plan, kamerplan/plan (holrijm — zelfde woord in samenstelling).
  Fout: indeling/kring, woning/kring, stijl/blijft, uitzicht/zucht.
  Alleen dezelfde uitgang -ing/-en is NIET genoeg als de klemtoon niet meedoet.
- Liever een simpeler, inhoudelijk ANDER eindwoord dan hetzelfde woord twee keer.

METRUM blijft verplicht (zie hierboven), maar offer NOOIT betekenis op voor ritme of rijm.
- GRAMMATICA gaat voor rijm: werkwoorden correct (geen "zoek" i.p.v. "zoekt").
- Stop NOOIT een los rijmwoord achter een komma (fout: "...mijlpaal, blij").

{$sizeBlock}

INHOUD (verplicht, zwaarder dan rijm)
- Toon: warm, speels, licht ironisch. Je/jij, nooit "u".
- Elke regel draagt informatie: dienst, product, aanpak, klant, plek, of een milde knipoog die daarbij past.
- Verwerk 2–4 CONCRETE details uit de website. Noem de branche expliciet.
- Geen hol "passie en vakmanschap". Geen absurde beelden. Blijf bij feiten van de site.
- Stem enkelvoud/meervoud af op BEDRIJFSGROOTTE.

VERBODEN (rijmdwang / leegte)
- Rijmredders zonder inhoud: "persoonlijke kring", "zonder enige zwak", "heel intens",
  "dat wordt gehoord", "goed gehoord", "nietwaar", "dat vindt de Sint toch raar" als losse filler.
- Clichés: "kijk eens rond op hun site", "pakjesavond paraat", "De Sint zat te denken / … schenken".
- Vulzinnen: "oh zo blij", "oh wat een", "vast heel fijn", "echt waar".

SLOT
- Eindig met iets inhoudelijks over het bedrijf (product, dienst, klantbelofte).
- Lichte Sint-knipoog mag alleen als die inhoudelijk past.

CONTROLE VOORDAT JE ANTWOORDT
1) Zegt élke regel iets over dit bedrijf — of is er een rijmwoord uit het ongerijmde?
2) Eindrijm per couplet: écht rijm én inhoudelijk passend?
3) Metrum: 7–11 lettergrepen, paren even lang, TA-ta-cadans?
4) Weet de lezer WAT het bedrijf doet?
5) Zo niet: herschrijf het couplet inhoudelijk, zoek daarna pas opnieuw rijm.
PROMPT;

    $host = ys_clean_text((string) ($profile['host'] ?? ''), 120);
    $brand = ys_clean_text((string) ($profile['brand'] ?? ''), 80);
    $title = ys_clean_text((string) ($profile['title'] ?? ''), 180);
    $desc = ys_clean_text((string) ($profile['description'] ?? ''), 500);
    $heads = [];
    foreach (($profile['headings'] ?? []) as $h) {
        $h = ys_clean_text((string) $h, 120);
        if ($h !== '') {
            $heads[] = $h;
        }
    }
    $pageText = ys_clean_text((string) ($profile['text'] ?? ''), 4000);
    $sizeLabel = match ($companySize) {
        'solo' => 'eenmanszaak / zzp / alleen ondernemer',
        'team' => 'bedrijf met medewerkers / team',
        default => 'onbekend — blijf neutraal',
    };
    $sizeConf = ys_clean_text((string) ($profile['company_size_confidence'] ?? 'low'), 20);
    $aboutUrl = ys_clean_text((string) ($profile['about_url'] ?? ''), 200);

    $facts = "Domein: {$host}\n"
        . "Merk/naam: {$brand}\n"
        . "Paginatitel: {$title}\n"
        . "Beschrijving: {$desc}\n"
        . "Bedrijfsgrootte: {$sizeLabel} (zekerheid: {$sizeConf})\n"
        . ($aboutUrl !== '' ? "Over-pagina: {$aboutUrl}\n" : '')
        . "Koppen: " . implode(' | ', $heads) . "\n"
        . "Website-tekst (homepage" . ($aboutUrl !== '' ? ' + over-pagina' : '') . "):\n"
        . ($pageText !== '' ? $pageText : '(weinig of geen tekst beschikbaar)');

    $userText = "Schrijf een sinterklaasgedicht over dit bedrijf.\n"
        . "Invalshoek: " . ys_clean_text($angle, 200) . "\n\n"
        . $facts . "\n\n";
    if ($imageDataUrl) {
        $userText .= "Er is weinig betrouwbare tekst van de homepage. "
            . "Gebruik daarom de bijgevoegde screenshot van de website als primaire bron: "
            . "lees zichtbare teksten, diensten, producten en sfeer af van het beeld.\n\n";
    }
    $userText .= "Onthoud: inhoud eerst, daarna pas rijm. Geen rijmwoorden uit het ongerijmde. "
        . "AABB + metrum dat loopt, enkelvoud/meervoud passend bij de bedrijfsgrootte.";

    $userContent = $imageDataUrl
        ? [
            ['type' => 'text', 'text' => $userText],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl, 'detail' => 'low']],
        ]
        : $userText;

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $userContent],
    ];

    $attempts = [];

    for ($try = 1; $try <= 5; $try++) {
        $temp = match ($try) {
            1 => 0.7,
            2 => 0.55,
            3 => 0.4,
            default => 0.3,
        };
        $res = openai_chat($messages, $temp);
        if (!$res['ok']) {
            return $res + ['source' => $imageDataUrl ? 'vision' : 'text'];
        }
        $poem = $res['text'];
        $check = poem_check_aabb($poem);
        $attempts[] = ['try' => $try, 'bad' => $check['bad_pairs']];
        if ($check['ok']) {
            return [
                'ok'     => true,
                'poem'   => implode("\n", $check['lines']),
                'tries'  => $try,
                'source' => $imageDataUrl ? 'vision' : 'text',
            ];
        }
        // Feedbackronde: herschrijf — geen "bijna-rijm" meer accepteren
        $messages[] = ['role' => 'assistant', 'content' => $poem];
        $messages[] = [
            'role' => 'user',
            'content' => "Dit gedicht faalt de controle en mag NIET zo blijven.\n"
                . "Problemen: " . implode('; ', $check['bad_pairs']) . "\n\n"
                . "Herschrijf het HELE gedicht (exact 8 of 12 regels, AABB).\n"
                . "INHOUD EERST: elke regel moet iets zeggen over dit bedrijf. "
                . "Trek geen rijmwoorden uit het ongerijmde — herformuleer of kies een ander feit.\n"
                . "METRUM: trocheeën (TA-ta), 8–10 lettergrepen, rijmparen even lang.\n"
                . "Alleen écht rijm. Geen bijna-rijm, geen vulzinnen, geen afgehakte werkwoorden.\n"
                . "Alleen het gedicht.",
        ];
    }

    // Geen half werk meer: liever een fout dan een gedicht dat niet rijmt
    return [
        'ok'     => false,
        'error'  => 'Het rijm wilde dit keer niet lukken. Klik op “Nog een gedicht” en probeer opnieuw.',
        'source' => $imageDataUrl ? 'vision' : 'text',
        'debug'  => ['attempts' => $attempts],
    ];
}

// ── Router ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $rawBody = (string) file_get_contents('php://input');
    $in = json_decode($rawBody, true);
    if (is_array($in)) {
        $_POST = array_merge($_POST, $in);
        if ($action === '') {
            $action = (string) ($in['action'] ?? 'generate');
        }
    }
}

switch ($action) {
case 'generate': {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'error' => 'POST vereist'], 405);
    }
    if (!rate('generate', 12, 600)) {
        out(['ok' => false, 'error' => 'Even rustig aan — te veel gedichten. Probeer het zo opnieuw.'], 429);
    }

    $rawUrl = (string) ($_POST['url'] ?? '');
    $force  = !empty($_POST['fresh']);
    $seed   = (string) ($_POST['seed'] ?? bin2hex(random_bytes(4)));

    $u = public_url($rawUrl);
    if (!$u) {
        out(['ok' => false, 'error' => 'Vul een geldige website-URL in (bijv. jouwbedrijf.nl).']);
    }

    $host = preg_replace('~^www\.~i', '', $u['host']) ?: $u['host'];
    $profile = $force ? null : load_profile($host);

    if (!$profile) {
        $fetch = safe_fetch($u['url']);
        if (!$fetch['ok']) {
            out(['ok' => false, 'error' => $fetch['error']]);
        }
        if (($fetch['code'] ?? 0) >= 400) {
            out(['ok' => false, 'error' => 'Website geeft foutcode ' . $fetch['code'] . '.']);
        }
        $profile = extract_site_profile((string) $fetch['body'], $fetch['url'], $fetch['host']);
        $profile = enrich_profile_company_size($profile, (string) $fetch['body']);
        save_profile($profile);
    } elseif (empty($profile['company_size'])) {
        // Oude cache zonder grootte: homepage opnieuw ophalen voor over-pagina
        $fetch = safe_fetch($profile['url'] ?? $u['url']);
        if ($fetch['ok'] && ($fetch['code'] ?? 0) < 400) {
            $profile = enrich_profile_company_size($profile, (string) $fetch['body']);
            save_profile($profile);
        } else {
            $profile['company_size'] = 'unknown';
            $profile['company_size_confidence'] = 'low';
        }
    }

    $needsVision = profile_needs_vision($profile);

    // Screenshot: bij magere tekst verplicht (vision), anders optioneel met rate-limit.
    $shotRel = null;
    if ($needsVision) {
        $shotRel = fetch_screenshot($profile['url'], $profile['host']);
    } elseif (rate('shot', 8, 600)) {
        $shotRel = fetch_screenshot($profile['url'], $profile['host']);
    }

    if ($needsVision && !$shotRel) {
        out([
            'ok'    => false,
            'error' => 'We vonden te weinig tekst op de website én de screenshot lukte niet. Probeer het later opnieuw.',
            'profile' => [
                'host'  => $profile['host'] ?? '',
                'title' => $profile['title'] ?? '',
                'brand' => $profile['brand'] ?? '',
            ],
            'debug' => [
                'quality'  => $profile['quality'] ?? null,
                'text_len' => mb_strlen((string) ($profile['text'] ?? ''), 'UTF-8'),
            ],
        ], 422);
    }

    $poemRes = generate_poem($profile, $seed, $shotRel);
    if (!$poemRes['ok']) {
        out([
            'ok'      => false,
            'error'   => $poemRes['error'],
            'profile' => [
                'host'  => $profile['host'],
                'title' => $profile['title'],
                'brand' => $profile['brand'],
            ],
            'screenshot' => $shotRel,
            'debug' => $poemRes['debug'] ?? null,
        ], 502);
    }

    $share = save_share($poemRes['poem'], $profile, $shotRel);
    out([
        'ok'   => true,
        'poem' => $poemRes['poem'],
        'profile' => [
            'host'         => $profile['host'],
            'brand'        => $profile['brand'],
            'title'        => $profile['title'],
            'description'  => $profile['description'],
            'url'          => $profile['url'],
            'company_size' => $profile['company_size'] ?? 'unknown',
            'about_url'    => $profile['about_url'] ?? '',
        ],
        'screenshot' => $shotRel,
        'seed'       => $seed,
        'share_id'   => $share['id'],
        'share_url'  => $share['url'],
        'tries'      => $poemRes['tries'] ?? null,
        'source'     => $poemRes['source'] ?? 'text',
        'debug'      => array_filter([
            'poem' => $poemRes['debug'] ?? null,
            'company_size' => $profile['company_size'] ?? null,
            'company_size_confidence' => $profile['company_size_confidence'] ?? null,
            'company_size_evidence' => $profile['company_size_evidence'] ?? null,
            'about_url' => $profile['about_url'] ?? null,
        ], static fn($v) => $v !== null && $v !== ''),
    ]);
}

case 'get': {
    $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
    $share = load_share($id);
    if (!$share) {
        out(['ok' => false, 'error' => 'Deze deellink bestaat niet (meer).'], 404);
    }
    out([
        'ok'         => true,
        'poem'       => $share['poem'] ?? '',
        'profile'    => $share['profile'] ?? [],
        'screenshot' => $share['screenshot'] ?? null,
        'share_id'   => $share['id'] ?? $id,
        'share_url'  => share_public_base() . '?g=' . ($share['id'] ?? $id),
    ]);
}

case 'poem': {
    // Alleen een nieuw gedicht op basis van gecachte site-info
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'error' => 'POST vereist'], 405);
    }
    if (!rate('poem', 20, 600)) {
        out(['ok' => false, 'error' => 'Even rustig aan — probeer het zo opnieuw.'], 429);
    }
    $host = strtolower(trim((string) ($_POST['host'] ?? '')));
    $host = preg_replace('~^www\.~i', '', $host) ?: $host;
    $seed = (string) ($_POST['seed'] ?? bin2hex(random_bytes(4)));
    if ($host === '' || !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $host)) {
        out(['ok' => false, 'error' => 'Onbekend domein. Start opnieuw met een URL.']);
    }
    $profile = load_profile($host);
    if (!$profile) {
        out(['ok' => false, 'error' => 'Website-info verlopen. Vul de URL opnieuw in.']);
    }
    $shotRel = null;
    $shotPath = __DIR__ . '/data/shots/' . cache_key($host) . '.jpg';
    if (is_file($shotPath)) {
        $shotRel = 'data/shots/' . cache_key($host) . '.jpg';
    } elseif (profile_needs_vision($profile)) {
        $shotRel = fetch_screenshot($profile['url'], $profile['host']);
    }
    $poemRes = generate_poem($profile, $seed, $shotRel);
    if (!$poemRes['ok']) {
        out(['ok' => false, 'error' => $poemRes['error'], 'debug' => $poemRes['debug'] ?? null], 502);
    }
    $share = save_share($poemRes['poem'], $profile, $shotRel);
    out([
        'ok'         => true,
        'poem'       => $poemRes['poem'],
        'seed'       => $seed,
        'host'       => $host,
        'profile'    => [
            'host'         => $profile['host'],
            'brand'        => $profile['brand'],
            'title'        => $profile['title'],
            'description'  => $profile['description'],
            'url'          => $profile['url'],
            'company_size' => $profile['company_size'] ?? 'unknown',
            'about_url'    => $profile['about_url'] ?? '',
        ],
        'screenshot' => $shotRel,
        'share_id'   => $share['id'],
        'share_url'  => $share['url'],
        'tries'      => $poemRes['tries'] ?? null,
        'debug'      => $poemRes['debug'] ?? null,
    ]);
}

case 'song': {
    // Easter egg: gedicht → Sinterklaasliedje via ElevenLabs Music
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        out(['ok' => false, 'error' => 'POST vereist'], 405);
    }
    if (!rate('song', 2, 3600)) {
        out(['ok' => false, 'error' => 'De Sint is hees — max 2 liedjes per uur. Probeer later opnieuw.'], 429);
    }
    if (!rate('song_day', 12, 86400)) {
        out(['ok' => false, 'error' => 'Vandaag genoeg gezongen. Morgen weer een liedje!'], 429);
    }

    $elKey = (string) ($CFG['elevenlabs_api_key'] ?? '');
    if ($elKey === '') {
        out(['ok' => false, 'error' => 'Liedjesdienst is nog niet geconfigureerd.'], 503);
    }

    $poem = trim((string) ($_POST['poem'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? 'jouw bedrijf'));
    $brand = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $brand) ?? $brand, 0, 60);
    $shareId = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['share_id'] ?? '')) ?? '';
    if (mb_strlen($poem) < 40) {
        out(['ok' => false, 'error' => 'Eerst een gedicht maken, dan pas zingen.']);
    }
    if (mb_strlen($poem) > 1800) {
        $poem = mb_substr($poem, 0, 1800);
    }

    $lyrics = poem_to_song_lyrics($poem, $brand);
    $title = 'Sinterklaaslied voor ' . ($brand !== '' ? $brand : 'jouw bedrijf');
    $plan = poem_to_composition_plan($lyrics, $brand);
    $model = (string) ($CFG['elevenlabs_music'] ?: 'music_v2_5');

    $body = [
        'composition_plan' => $plan,
        'model_id'         => $model,
    ];

    $headerFile = tempnam(sys_get_temp_dir(), 'yselh');
    $ch = curl_init('https://api.elevenlabs.io/v1/music?output_format=mp3_44100_128');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'xi-api-key: ' . $elKey,
            'Content-Type: application/json',
            'Accept: audio/mpeg',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use ($headerFile): int {
            if (stripos($header, 'song-id:') === 0) {
                file_put_contents($headerFile, trim(substr($header, 8)));
            }
            return strlen($header);
        },
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $songIdHdr = is_file($headerFile) ? trim((string) file_get_contents($headerFile)) : '';
    @unlink($headerFile);

    if ($res === false) {
        out(['ok' => false, 'error' => 'Kon Eleven Music niet bereiken.' . ($err ? " ($err)" : '')], 502);
    }

    $isAudio = $code < 400
        && is_string($res)
        && strlen($res) > 5000
        && (str_contains($ctype, 'audio') || str_starts_with($res, 'ID3') || str_starts_with($res, "\xff\xfb"));

    if (!$isAudio) {
        $json = json_decode((string) $res, true);
        $rawMsg = '';
        if (is_array($json)) {
            $detail = $json['detail'] ?? null;
            if (is_array($detail)) {
                $rawMsg = (string) ($detail['message'] ?? $detail['status'] ?? '');
                if ($rawMsg === '' && isset($detail['data']['prompt_suggestion'])) {
                    $rawMsg = 'Prompt geweigerd. Suggestie: ' . (string) $detail['data']['prompt_suggestion'];
                }
            } elseif (is_string($detail)) {
                $rawMsg = $detail;
            } else {
                $rawMsg = (string) ($json['message'] ?? $json['error'] ?? '');
            }
        }
        $rawLower = strtolower($rawMsg);
        if (str_contains($rawLower, 'quota') || str_contains($rawLower, 'credit') || $code === 402) {
            $msg = 'Geen ElevenLabs-credits meer voor muziek.';
        } elseif (str_contains($rawLower, 'permission') || str_contains($rawLower, 'music_generation')) {
            $msg = 'API-key mist Music Generation rechten.';
        } elseif ($rawMsg !== '') {
            $msg = $rawMsg;
        } else {
            $msg = 'Eleven Music-fout (HTTP ' . $code . ')';
        }
        out([
            'ok'    => false,
            'error' => 'Liedje starten mislukt: ' . $msg,
            'debug' => ['http' => $code, 'ctype' => $ctype],
        ], 502);
    }

    $predictionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $songIdHdr) ?: ('el' . bin2hex(random_bytes(12)));
    if (strlen($predictionId) > 64) {
        $predictionId = substr($predictionId, 0, 64);
    }

    $rel = "data/songs/{$predictionId}_1.mp3";
    $path = __DIR__ . '/' . $rel;
    if (file_put_contents($path, $res) === false) {
        out(['ok' => false, 'error' => 'Kon liedje niet opslaan.'], 500);
    }

    $tracks = [[
        'label' => 'Sinterklaaslied',
        'url'   => $rel,
    ]];
    $job = [
        'id'       => $predictionId,
        'provider' => 'elevenlabs',
        'status'   => 'completed',
        'brand'    => $brand,
        'share_id' => strlen($shareId) === 8 ? $shareId : null,
        'lyrics'   => $lyrics,
        'created'  => time(),
        'completed'=> time(),
        'tracks'   => $tracks,
        'thumbnail'=> null,
    ];
    file_put_contents("$DATA/songs/$predictionId.json", json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if (!empty($job['share_id']) && share_id_valid((string) $job['share_id'])) {
        $sharePath = "$DATA/shares/{$job['share_id']}.json";
        if (is_file($sharePath)) {
            $share = json_decode((string) file_get_contents($sharePath), true) ?: [];
            $share['song'] = [
                'prediction_id' => $predictionId,
                'tracks'        => $tracks,
                'thumbnail'     => null,
                'provider'      => 'elevenlabs',
            ];
            file_put_contents($sharePath, json_encode($share, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    out([
        'ok'            => true,
        'prediction_id' => $predictionId,
        'status'        => 'completed',
        'tracks'        => $tracks,
        'title'         => $title,
        'message'       => 'Het liedje is klaar!',
    ]);
}

case 'song_status': {
    $predictionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['id'] ?? $_POST['id'] ?? '')) ?? '';
    if ($predictionId === '' || strlen($predictionId) > 80) {
        out(['ok' => false, 'error' => 'Ongeldige job-id']);
    }

    $jobFile = "$DATA/songs/$predictionId.json";
    $job = is_file($jobFile) ? json_decode((string) file_get_contents($jobFile), true) : null;
    if (!is_array($job)) {
        out(['ok' => false, 'error' => 'Liedje-job niet gevonden'], 404);
    }

    if (($job['status'] ?? '') === 'completed' && !empty($job['tracks'])) {
        out([
            'ok'            => true,
            'prediction_id' => $predictionId,
            'status'        => 'completed',
            'tracks'        => $job['tracks'],
            'thumbnail'     => $job['thumbnail'] ?? null,
            'title'         => 'Sinterklaaslied voor ' . ($job['brand'] ?? 'jouw bedrijf'),
        ]);
    }
    if (in_array(($job['status'] ?? ''), ['failed', 'timeout', 'canceled'], true)) {
        out([
            'ok'            => false,
            'prediction_id' => $predictionId,
            'status'        => $job['status'],
            'error'         => $job['error'] ?? 'Liedje mislukt',
        ], 502);
    }

    // Oude async jobs (Atlas) worden niet meer gepolld — alleen lokale status.
    out([
        'ok'            => true,
        'prediction_id' => $predictionId,
        'status'        => $job['status'] ?? 'processing',
        'message'       => 'Nog even… Piet oefent de melodie.',
    ]);
}

default:
    out(['ok' => false, 'error' => 'Onbekende actie'], 400);
}

function poem_to_song_lyrics(string $poem, string $brand): string
{
    $lines = [];
    foreach (preg_split('/\R/u', trim($poem)) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    if ($lines === []) {
        return "[Verse]\nEen sinterklaasgedicht\n[Chorus]\nHoera voor $brand";
    }
    $mid = (int) ceil(count($lines) / 2);
    $verse = array_slice($lines, 0, $mid);
    $rest = array_slice($lines, $mid);
    $chorus = [
        "Hoera voor $brand, wat een feest!",
        'Sinterklaas zingt mee, van west tot oost.',
        'Pakjesavond komt eraan,',
        "Met $brand mag je vrolijk staan!",
    ];
    $out = "[Verse]\n" . implode("\n", $verse) . "\n\n[Chorus]\n" . implode("\n", $chorus);
    if ($rest) {
        $out .= "\n\n[Verse]\n" . implode("\n", $rest) . "\n\n[Chorus]\n" . implode("\n", $chorus);
    }
    return $out;
}

/**
 * Bouw een Eleven Music composition_plan met Nederlandse sinterklaas-stijl + lyrics.
 * @return array{chunks: list<array<string,mixed>>}
 */
function poem_to_composition_plan(string $lyrics, string $brand): array
{
    $styles = [
        'dutch folk',
        'festive',
        'warm',
        'male vocal',
        'accordion',
        'acoustic',
        'children choir vibe',
        'pakjesavond',
        'sinterklaas celebration',
    ];
    $neg = ['metal', 'trap', 'screamo', 'english rap', 'explicit', 'dark'];

    $sections = preg_split('/\n\s*\n/u', trim($lyrics)) ?: [];
    $chunks = [];
    foreach ($sections as $section) {
        $section = trim($section);
        if ($section === '') {
            continue;
        }
        $lineCount = max(1, count(array_filter(preg_split('/\R/u', $section) ?: [])));
        $duration = min(45000, max(10000, 4000 + ($lineCount * 2800)));
        $chunks[] = [
            'text'              => $section,
            'duration_ms'       => $duration,
            'positive_styles'   => $styles,
            'negative_styles'   => $neg,
            'context_adherence' => 'high',
        ];
    }
    if ($chunks === []) {
        $chunks[] = [
            'text'              => "[Verse]\nEen sinterklaasgedicht voor $brand\n[Chorus]\nHoera voor $brand",
            'duration_ms'       => 30000,
            'positive_styles'   => $styles,
            'negative_styles'   => $neg,
            'context_adherence' => 'high',
        ];
    }
    // Max ~90s totaal om credits/wachttijd te beperken
    $total = array_sum(array_column($chunks, 'duration_ms'));
    if ($total > 90000) {
        $scale = 90000 / $total;
        foreach ($chunks as &$c) {
            $c['duration_ms'] = max(8000, (int) round($c['duration_ms'] * $scale));
        }
        unset($c);
    }
    return ['chunks' => $chunks];
}

function cache_song_track(string $predictionId, int $n, string $url): ?string
{
    global $DATA;
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $predictionId) ?? '';
    if ($safeId === '') {
        return null;
    }
    $rel = "data/songs/{$safeId}_{$n}.mp3";
    $path = __DIR__ . '/' . $rel;
    if (is_file($path) && filesize($path) > 5000) {
        return $rel;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $bin = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($bin === false || $code >= 400 || strlen((string) $bin) < 5000) {
        return null;
    }
    if ($ct !== '' && !str_contains(strtolower($ct), 'audio') && !str_contains(strtolower($ct), 'octet')) {
        // Sommige CDN's sturen rare content-types; als het groot genoeg is, toch opslaan
        if (strlen((string) $bin) < 20000) {
            return null;
        }
    }
    if (file_put_contents($path, $bin) === false) {
        return null;
    }
    return $rel;
}
