(() => {
  const $ = (id) => document.getElementById(id);

  const scrStart = $('scrStart');
  const scrResult = $('scrResult');
  const form = $('startForm');
  const inUrl = $('inUrl');
  const btnStart = $('btnStart');
  const startStatus = $('startStatus');
  const loadingBox = $('loadingBox');
  const loadSteps = $('loadSteps');
  const poemText = $('poemText');
  const poemFor = $('poemFor');
  const resultBrand = $('resultBrand');
  const resultTitle = $('resultTitle');
  const resultUrl = $('resultUrl');
  const shotBox = $('shotBox');
  const resultStatus = $('resultStatus');
  const btnAgain = $('btnAgain');
  const btnCopy = $('btnCopy');
  const btnRestart = $('btnRestart');
  const btnShare = $('btnShare');
  const shareBox = $('shareBox');
  const shareUrlInput = $('shareUrl');
  const btnCopyLink = $('btnCopyLink');
  const btnWhatsApp = $('btnWhatsApp');
  const sealBtn = $('sealBtn');
  const eggBox = $('eggBox');
  const btnSong = $('btnSong');
  const songStatus = $('songStatus');
  const songPlayer = $('songPlayer');
  const modal = $('modal');
  const modalMsg = $('modalMsg');
  const modalTitle = $('modalTitle');
  const modalOk = $('modalOk');

  let state = {
    host: '',
    url: '',
    brand: '',
    title: '',
    screenshot: null,
    shareId: null,
    shareUrl: null,
    poem: '',
  };

  let sealClicks = 0;
  let sealTimer = null;
  let songPollTimer = null;

  function showModal(message, title = 'Melding') {
    modalTitle.textContent = title;
    modalMsg.textContent = message;
    modal.hidden = false;
    modalOk.focus();
  }

  modalOk.addEventListener('click', () => {
    modal.hidden = true;
  });
  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.hidden = true;
  });

  function setStatus(el, msg, isErr = false) {
    el.textContent = msg || '';
    el.classList.toggle('err', !!isErr);
  }

  function setStep(name) {
    loadingBox.hidden = false;
    [...loadSteps.querySelectorAll('li')].forEach((li) => {
      const step = li.dataset.step;
      li.classList.remove('on', 'done');
      if (step === name) li.classList.add('on');
      else if (
        (name === 'shot' && step === 'fetch') ||
        (name === 'poem' && (step === 'fetch' || step === 'shot'))
      ) {
        li.classList.add('done');
      }
    });
  }

  function normalizeInput(raw) {
    let u = (raw || '').trim();
    if (!u) return '';
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
    return u;
  }

  function renderScreenshot(path) {
    if (path) {
      const sep = path.includes('?') ? '&' : '?';
      shotBox.innerHTML = `<img src="${path}${sep}t=${Date.now()}" alt="Screenshot van de website">`;
    } else {
      shotBox.innerHTML = `<div class="shot-placeholder">Geen screenshot beschikbaar — het gedicht wel!</div>`;
    }
  }

  function updateShareUi() {
    if (!state.shareUrl) {
      shareBox.hidden = true;
      shareUrlInput.value = '';
      btnWhatsApp.removeAttribute('href');
      return;
    }
    shareUrlInput.value = state.shareUrl;
    const text = `Sinterklaas schreef een gedicht over ${state.brand || state.host || 'dit bedrijf'}! Bekijk het hier: ${state.shareUrl}`;
    btnWhatsApp.href = 'https://wa.me/?text=' + encodeURIComponent(text);
    shareBox.hidden = false;
  }

  function setShareUrl(shareId, shareUrl) {
    state.shareId = shareId || null;
    state.shareUrl = shareUrl || (shareId ? `${location.origin}${location.pathname}?g=${shareId}` : null);
    updateShareUi();
    if (state.shareId) {
      const next = `${location.pathname}?g=${encodeURIComponent(state.shareId)}`;
      if (location.pathname + location.search !== next) {
        history.replaceState({ g: state.shareId }, '', next);
      }
      document.title = `Sinterklaasgedicht voor ${state.brand || state.host || 'jouw bedrijf'} · YourSint`;
    }
  }

  function resetSongUi() {
    if (songPollTimer) {
      clearInterval(songPollTimer);
      songPollTimer = null;
    }
    songPlayer.hidden = true;
    songPlayer.innerHTML = '';
    eggBox.hidden = true;
    unlockSongButton();
    setStatus(songStatus, '');
  }

  function showResult(data, { fromShare = false } = {}) {
    const p = data.profile || {};
    state.host = p.host || state.host;
    state.url = p.url || state.url;
    state.brand = p.brand || state.brand;
    state.title = p.title || state.title;
    state.poem = data.poem || '';
    if (data.screenshot !== undefined) state.screenshot = data.screenshot;
    // Nieuw gedicht = nieuw liedje mogelijk (tenzij share al een song meeneemt)
    if (!fromShare) {
      resetSongUi();
    }

    resultBrand.textContent = state.brand || state.host || 'Bedrijf';
    resultTitle.textContent = state.title || '';
    resultUrl.textContent = state.url || (state.host ? 'https://' + state.host : 'https://…');
    poemFor.textContent = 'Voor ' + (state.brand || state.host || 'jouw bedrijf');
    poemText.textContent = state.poem;
    poemText.style.animation = 'none';
    void poemText.offsetWidth;
    poemText.style.animation = '';
    renderScreenshot(state.screenshot);

    if (data.share_id || data.share_url) {
      setShareUrl(data.share_id, data.share_url);
    } else if (!fromShare) {
      updateShareUi();
    }

    scrStart.hidden = true;
    scrResult.hidden = false;
    loadingBox.hidden = true;
    if (!fromShare) setStatus(resultStatus, '');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  async function generate(url, { fresh = false } = {}) {
    setStatus(startStatus, '');
    btnStart.disabled = true;
    form.hidden = true;
    setStep('fetch');

    const stepTimers = [
      setTimeout(() => setStep('shot'), 900),
      setTimeout(() => setStep('poem'), 2200),
    ];

    try {
      const res = await fetch('api.php?action=generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'generate',
          url,
          fresh: fresh ? 1 : 0,
          seed: Math.random().toString(36).slice(2, 10),
        }),
      });
      const data = await res.json().catch(() => ({}));
      stepTimers.forEach(clearTimeout);
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Er ging iets mis. Probeer het opnieuw.');
      }
      setStep('poem');
      showResult(data);
      console.log('[YourSint] generate ok', {
        host: data.profile?.host,
        share_id: data.share_id,
        poem_len: (data.poem || '').length,
      });
    } catch (err) {
      stepTimers.forEach(clearTimeout);
      loadingBox.hidden = true;
      form.hidden = false;
      setStatus(startStatus, err.message || 'Fout', true);
      console.log('[YourSint] generate error', { message: err.message });
    } finally {
      btnStart.disabled = false;
    }
  }

  async function regenerate() {
    if (!state.host) {
      showModal('Begin opnieuw met een website-URL.', 'Geen website');
      return;
    }
    btnAgain.disabled = true;
    setStatus(resultStatus, 'Sinterklaas schrijft een nieuw gedicht…');
    try {
      const res = await fetch('api.php?action=poem', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'poem',
          host: state.host,
          seed: Math.random().toString(36).slice(2, 10),
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Nieuw gedicht mislukt.');
      }
      showResult(data);
      setStatus(resultStatus, 'Nieuw gedicht klaar — deellink is bijgewerkt.');
      console.log('[YourSint] poem ok', {
        host: state.host,
        share_id: data.share_id,
        poem_len: (data.poem || '').length,
      });
    } catch (err) {
      setStatus(resultStatus, err.message || 'Fout', true);
      showModal(err.message || 'Kon geen nieuw gedicht maken.');
      console.log('[YourSint] poem error', { message: err.message });
    } finally {
      btnAgain.disabled = false;
    }
  }

  async function copyText(text, okMsg) {
    try {
      await navigator.clipboard.writeText(text);
      setStatus(resultStatus, okMsg);
      return true;
    } catch {
      return false;
    }
  }

  async function sharePoem() {
    if (!state.shareUrl) {
      showModal('Er is nog geen deellink. Maak eerst een gedicht.', 'Delen');
      return;
    }
    updateShareUi();
    const title = `Sinterklaasgedicht voor ${state.brand || state.host || 'jouw bedrijf'}`;
    const text = `Sinterklaas schreef een gedicht over ${state.brand || state.host || 'dit bedrijf'}!`;

    if (navigator.share) {
      try {
        await navigator.share({ title, text, url: state.shareUrl });
        setStatus(resultStatus, 'Gedicht gedeeld!');
        console.log('[YourSint] native share ok');
        return;
      } catch (err) {
        if (err && err.name === 'AbortError') return;
        console.log('[YourSint] native share fallback', { name: err && err.name });
      }
    }

    const ok = await copyText(state.shareUrl, 'Deellink gekopieerd — plak hem in een app of mail.');
    if (!ok) {
      shareUrlInput.focus();
      shareUrlInput.select();
      showModal('Kopieer de deellink hieronder en stuur die door.', 'Deellink');
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = normalizeInput(inUrl.value);
    if (!url) {
      setStatus(startStatus, 'Vul een website-URL in.', true);
      return;
    }
    generate(url);
  });

  btnAgain.addEventListener('click', regenerate);
  btnShare.addEventListener('click', sharePoem);

  btnCopy.addEventListener('click', async () => {
    const text = poemText.textContent || '';
    if (!text) return;
    const ok = await copyText(text, 'Gedicht gekopieerd!');
    if (!ok) showModal('Kopiëren lukte niet. Selecteer de tekst handmatig.');
  });

  btnCopyLink.addEventListener('click', async () => {
    if (!state.shareUrl) return;
    const ok = await copyText(state.shareUrl, 'Deellink gekopieerd!');
    if (!ok) {
      shareUrlInput.focus();
      shareUrlInput.select();
      showModal('Selecteer de link en kopieer handmatig.');
    }
  });

  btnRestart.addEventListener('click', () => {
    state = {
      host: '',
      url: '',
      brand: '',
      title: '',
      screenshot: null,
      shareId: null,
      shareUrl: null,
      poem: '',
    };
    scrResult.hidden = true;
    scrStart.hidden = false;
    form.hidden = false;
    loadingBox.hidden = true;
    shareBox.hidden = true;
    eggBox.hidden = true;
    songPlayer.hidden = true;
    songPlayer.innerHTML = '';
    unlockSongButton();
    sealClicks = 0;
    if (songPollTimer) clearInterval(songPollTimer);
    setStatus(startStatus, '');
    history.replaceState({}, '', location.pathname);
    document.title = 'YourSint — Sinterklaasgedicht voor jouw bedrijf | Yourhosting';
    inUrl.focus();
  });

  // Easter egg: 5× op het zegel tikken → Eleven Music-liedje
  sealBtn.addEventListener('click', () => {
    sealBtn.classList.remove('egg-hot');
    void sealBtn.offsetWidth;
    sealBtn.classList.add('egg-hot');
    sealClicks += 1;
    clearTimeout(sealTimer);
    sealTimer = setTimeout(() => { sealClicks = 0; }, 2500);
    if (sealClicks < 5) return;
    sealClicks = 0;
    if (!state.poem) {
      showModal('Eerst een gedicht, dan pas een liedje.', 'Psst…');
      return;
    }
    eggBox.hidden = false;
    // Al een liedje? Knop blijft weg, alleen speler tonen
    if (songPlayer && !songPlayer.hidden && songPlayer.querySelector('audio')) {
      lockSongButton();
      setStatus(songStatus, 'Dit gedicht heeft al een liedje.');
    } else {
      setStatus(songStatus, '');
    }
    eggBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    console.log('[YourSint] easter egg unlocked');
  });

  function lockSongButton() {
    btnSong.hidden = true;
    btnSong.disabled = true;
  }

  function unlockSongButton() {
    btnSong.hidden = false;
    btnSong.disabled = false;
  }

  function renderTracks(tracks, title) {
    if (!tracks || !tracks.length) {
      songPlayer.hidden = true;
      songPlayer.innerHTML = '';
      return;
    }
    const track = tracks[0];
    songPlayer.innerHTML = `
      <div class="song-track">
        <strong>${title || track.label || 'Sinterklaaslied'}</strong>
        <audio controls preload="none" src="${track.url}"></audio>
      </div>
    `;
    songPlayer.hidden = false;
    lockSongButton();
  }

  async function pollSong(predictionId) {
    if (songPollTimer) clearInterval(songPollTimer);
    let tries = 0;
    const tick = async () => {
      tries += 1;
      try {
        const res = await fetch('api.php?action=song_status&id=' + encodeURIComponent(predictionId), {
          headers: { Accept: 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        console.log('[YourSint] song_status', { status: data.status, tries });
        if (data.status === 'completed' && data.tracks) {
          clearInterval(songPollTimer);
          songPollTimer = null;
          setStatus(songStatus, 'Het liedje is klaar!');
          renderTracks(data.tracks, data.title);
          return;
        }
        if (!data.ok || data.status === 'failed' || data.status === 'timeout') {
          clearInterval(songPollTimer);
          songPollTimer = null;
          btnSong.disabled = false;
          setStatus(songStatus, data.error || 'Liedje mislukt', true);
          return;
        }
        const msgs = [
          'Sinterklaas zoekt zijn stembanden…',
          'Piet oefent de melodie…',
          'Accordeon wordt gestemd…',
          'Bijna klaar — nog even volhouden…',
        ];
        setStatus(songStatus, msgs[Math.min(tries - 1, msgs.length - 1)]);
      } catch (err) {
        console.log('[YourSint] song poll error', { message: err.message });
      }
      if (tries >= 40) {
        clearInterval(songPollTimer);
        songPollTimer = null;
        btnSong.disabled = false;
        setStatus(songStatus, 'Dit duurt te lang. Probeer het later opnieuw.', true);
      }
    };
    await tick();
    songPollTimer = setInterval(tick, 4000);
  }

  btnSong.addEventListener('click', async () => {
    if (btnSong.hidden || btnSong.disabled) return;
    if (!state.poem) {
      showModal('Geen gedicht om te zingen.', 'Liedje');
      return;
    }
    if (songPlayer && !songPlayer.hidden && songPlayer.querySelector('audio')) {
      lockSongButton();
      setStatus(songStatus, 'Dit gedicht heeft al een liedje.');
      return;
    }
    btnSong.disabled = true;
    songPlayer.hidden = true;
    songPlayer.innerHTML = '';
    setStatus(songStatus, 'Sinterklaas stapt de studio in… dit kan even duren.');
    try {
      const res = await fetch('api.php?action=song', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          action: 'song',
          poem: state.poem,
          brand: state.brand || state.host || 'jouw bedrijf',
          share_id: state.shareId || '',
        }),
      });
      const data = await res.json().catch(() => ({}));
      console.log('[YourSint] song start', data);
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Kon geen liedje starten.');
      }
      if (data.status === 'completed' && data.tracks) {
        setStatus(songStatus, data.message || 'Het liedje is klaar!');
        renderTracks(data.tracks, data.title);
        return;
      }
      setStatus(songStatus, data.message || 'Bezig…');
      if (data.prediction_id) {
        pollSong(data.prediction_id);
      } else {
        throw new Error('Geen liedje-id ontvangen.');
      }
    } catch (err) {
      btnSong.disabled = false;
      setStatus(songStatus, err.message || 'Fout', true);
      showModal(err.message || 'Liedje starten mislukt.');
    }
  });

  // Boot: gedeelde pagina of ?g= in URL
  async function bootFromShare() {
    const boot = window.YOURSINT_BOOT || {};
    if (boot.share && boot.share.poem) {
      showResult(boot.share, { fromShare: true });
      setStatus(resultStatus, 'Iemand deelde dit gedicht met je. Maak zelf ook eentje!');
      if (boot.share.song && boot.share.song.tracks) {
        eggBox.hidden = false;
        renderTracks(boot.share.song.tracks, 'Sinterklaaslied voor ' + (state.brand || state.host || 'jouw bedrijf'));
        setStatus(songStatus, 'Bonus: er hoort een liedje bij dit gedicht!');
      }
      console.log('[YourSint] boot from SSR share', { id: boot.shareId, hasSong: !!(boot.share.song && boot.share.song.tracks) });
      return;
    }
    const params = new URLSearchParams(location.search);
    const g = (params.get('g') || '').trim();
    if (!/^[a-zA-Z0-9]{8}$/.test(g)) return;

    form.hidden = true;
    setStep('poem');
    try {
      const res = await fetch('api.php?action=get&id=' + encodeURIComponent(g), {
        headers: { Accept: 'application/json' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Deellink niet gevonden.');
      }
      showResult(data, { fromShare: true });
      setStatus(resultStatus, 'Iemand deelde dit gedicht met je. Maak zelf ook eentje!');
      console.log('[YourSint] boot from API share', { id: g });
    } catch (err) {
      loadingBox.hidden = true;
      form.hidden = false;
      scrStart.hidden = false;
      setStatus(startStatus, err.message || 'Deellink ongeldig', true);
      console.log('[YourSint] share load error', { message: err.message });
    }
  }

  bootFromShare();
  console.log('[YourSint] ready');
})();
