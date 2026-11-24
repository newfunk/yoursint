# YourSint

Sinterklaasgedicht-generator voor Yourhosting. Bezoekers vullen een website-URL in; de app scrapet de homepage (plus over-pagina), maakt een screenshot en laat AI een persoonlijk sinterklaasgedicht schrijven. Geen login.

Repo: [newfunk/yoursint](https://github.com/newfunk/yoursint)

## Omgevingen

| Omgeving | URL |
|---|---|
| Dev | https://dev.yoursitehulp.nl/yourgame/yoursint/ |
| Productie / eigen domein | via Plesk Git (handmatig) |

## Stack

- PHP 8.x (scrape + OpenAI + ApiFlash + ElevenLabs Music)
- Front-end: HTML/CSS/JS
- Secrets: Doppler (project `yoursitehulp`, config `dev` / `prd`)

## Secrets (Doppler)

- `OPENAI_API_KEY` — gedichten (model: gpt-5.2)
- `APIFLASH_KEY` — screenshots
- `ELEVENLABS_API_KEY` — easter-egg sinterklaasliedjes (Music Generation)

Doppler-client: `shared/doppler.php` op de yoursitehulp-server (of sibling `shared/` op een eigen host).

## Flow

1. Gebruiker vult URL in
2. Veilige fetch van de homepage (SSRF-bescherming)
3. Over-pagina zoeken → eenmanszaak vs team
4. Screenshot via ApiFlash
5. GPT schrijft een sinterklaasgedicht (AABB + trocheïsch metrum, inhoud eerst)
6. Deellink `?g=…` / optioneel liedje via Eleven Music

## Bestanden

- `index.php` — UI
- `api.php` — `generate`, `poem`, `get`, `song`, `song_status`
- `config.php` — Doppler (geblokkeerd via `.htaccess`)
- `app.js` / `style.css` — front-end
- `data/` — cache, shares, shots, songs (runtime, niet in git)

## Deploy (Plesk Git)

1. Lege map op de server (bv. `yourgame/yoursint` of document root van het eigen domein)
2. Plesk → Git → `git@github.com:newfunk/yoursint.git`, modus **Handmatig**
3. Deploy-pad = die ene map (niet `/`, niet dubbele `yoursint/`)
4. Pull uitvoeren
