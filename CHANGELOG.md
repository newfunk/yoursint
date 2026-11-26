# Changelog

## v1.0.3 — 2026-11-26

- URL input: autofocus on load for mouse/trackpad devices only (no keyboard pop-up on touch)
- Faster first input: start panel no longer fades/slides in, Google Fonts load non-blocking, 336px `assets/joeri-sint-336.jpg` (14.5 KB instead of 207 KB)
- Impeccable design pass: WCAG AA contrast for primary button, input border and placeholder; consistent focus ring, hover/active/disabled states and input error state (`aria-invalid`)
- Removed eyebrow labels and gradient text; "Sint" in IM Fell English italic with Yourhosting gradient swash; poem signed "Sinterklaas"
- Mitre icon in the poem seal, step indicators in the loading state, 2×2 poem actions, neutral status messages, themed selection/caret/scrollbar, `prefers-reduced-motion` support
- Removed the "5 december · YourSint" pill from the top bar
- Removed the sentence "Je kunt zo vaak een nieuw gedicht maken als je wilt." from the start screen hint

## v1.0.2 — 2026-11-26

- Own YourSint icon (gold mitre on Sint red): `favicon.ico`, SVG/PNG icons, `apple-touch-icon.png`
- `site.webmanifest` with 192/512 and maskable icons
- Social preview: 1200×630 `assets/og-image.jpg` with og:image width/height/alt and twitter:image
- Share links keep the site screenshot as og:image, now with its real dimensions
- `.htaccess`: correct content-type for `.ico`, `.svg` and `.webmanifest`

## v1.0.1 — 2026-11-25

- Mobile poem layout: keep each verse on one line, shrink font to fit
- Center poem text; tighter parchment padding on small screens

## v1.0.0 — 2026-11-24

- Initial YourSint release as standalone repository
- URL scrape + screenshot (ApiFlash) → Sinterklaas poem (GPT-5.2)
- Company-size detection via about page (solo vs team)
- Meter/rhyme checks (trochaic cadence, no identical/holrijm fillers)
- Shareable links (`?g=`), Suno easter egg replaced by Eleven Music
- Joeri mascot with Sint mitre in the UI
