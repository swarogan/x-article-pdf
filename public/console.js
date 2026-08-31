(function () {
  const form = document.getElementById('job');
  const progress = document.getElementById('progress');
  const fill = document.getElementById('fill');
  const status = document.getElementById('status');
  const timerEl = document.getElementById('timer');
  const button = form.querySelector('button[type=submit]');
  const modelSelect = document.getElementById('model');
  const langSelect = document.getElementById('lang');
  const langCustom = document.getElementById('lang_custom');
  const histList = document.getElementById('hist-list');
  const idle = document.getElementById('idle');
  const frame = document.getElementById('frame');
  const mdView = document.getElementById('md-view');
  const stageName = document.getElementById('stage-name');
  const stageLink = document.getElementById('stage-link');
  const stageTranslate = document.getElementById('stage-translate');

  let timerId = 0;
  let startedAt = 0;
  let activeId = '';
  let activeItem = null;

  function formatElapsed(ms) {
    const s = Math.floor(ms / 1000);
    const m = Math.floor(s / 60);
    return m + ':' + String(s % 60).padStart(2, '0');
  }
  function startTimer() {
    stopTimer();
    startedAt = Date.now();
    timerEl.textContent = '0:00';
    timerId = setInterval(function () {
      timerEl.textContent = formatElapsed(Date.now() - startedAt);
    }, 250);
  }
  function stopTimer() {
    if (timerId) clearInterval(timerId);
    timerId = 0;
    if (startedAt) timerEl.textContent = formatElapsed(Date.now() - startedAt);
  }

  function syncLang() {
    const on = langSelect.value !== '';
    const custom = langSelect.value === '__custom__';
    modelSelect.disabled = !on;
    langCustom.hidden = !custom;
    langCustom.required = custom;
  }
  langSelect.addEventListener('change', syncLang);

  const hostInput = document.getElementById('host');
  const hostProbe = document.getElementById('host-probe');
  const llmStatus = document.getElementById('llm-status');
  const HOST_KEY = 'xcapture.llmHost';
  try {
    hostInput.value = localStorage.getItem(HOST_KEY) || '';
  } catch (e) { /* prywatne okno */ }

  function loadModels() {
    const host = hostInput.value.trim();
    llmStatus.textContent = 'szukam serwera modeli…';
    modelSelect.innerHTML = '<option value="">ładowanie modeli…</option>';
    return fetch('/?models=1' + (host ? '&host=' + encodeURIComponent(host) : ''))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        const models = data.models || [];
        const preferred = modelSelect.dataset.default || '';
        modelSelect.innerHTML = '';
        if (!models.length) {
          const o = document.createElement('option');
          o.value = '';
          o.textContent = 'brak modeli';
          modelSelect.appendChild(o);
          llmStatus.textContent = data.message || 'Nie znaleziono serwera modeli.';
          return;
        }
        let picked = false;
        models.forEach(function (name) {
          const o = document.createElement('option');
          o.value = name;
          o.textContent = name;
          if (name === preferred) { o.selected = true; picked = true; }
          modelSelect.appendChild(o);
        });
        if (!picked) {
          const hint = models.find(function (n) { return n === 'gemma4:e2b'; })
            || models.find(function (n) { return /gemma4:e2b|hy-mt|bielik/i.test(n); });
          modelSelect.value = hint || models[0];
        }
        llmStatus.textContent = (data.backend === 'openai' ? 'llama.cpp' : 'ollama') + ' — ' + (data.host || '');
        if (data.host && !hostInput.value.trim()) hostInput.placeholder = data.host;
        syncLang();
      })
      .catch(function () {
        modelSelect.innerHTML = '';
        const o = document.createElement('option');
        o.value = '';
        o.textContent = 'brak modeli';
        modelSelect.appendChild(o);
        llmStatus.textContent = 'Nie można połączyć się z serwerem modeli.';
      });
  }

  hostProbe.addEventListener('click', function () {
    try {
      localStorage.setItem(HOST_KEY, hostInput.value.trim());
    } catch (e) { /* prywatne okno */ }
    loadModels();
  });
  hostInput.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); hostProbe.click(); }
  });

  loadModels();
  syncLang();

  function when(ts) {
    const d = new Date((ts || 0) * 1000);
    if (Number.isNaN(d.getTime())) return '';
    return d.toISOString().slice(0, 16).replace('T', ' ');
  }

  function shortUrl(url) {
    return String(url).replace(/^https?:\/\//, '').replace(/^www\./, '');
  }

  function renderHistory(items) {
    histList.innerHTML = '';
    if (!items.length) {
      const p = document.createElement('p');
      p.className = 'empty-hist';
      p.textContent = 'brak przechwyconych plików';
      histList.appendChild(p);
      return;
    }
    items.forEach(function (item) {
      const li = document.createElement('li');
      if (item.id === activeId) li.className = 'active';
      const a = document.createElement('a');
      a.href = '/?file=' + encodeURIComponent(item.id);
      a.dataset.id = item.id;
      a.dataset.format = item.format || '';
      a.innerHTML = '<span class="tag">' + (item.format || '?').toUpperCase() + '</span>'
        + '<span><span class="hist-title"></span><div class="hist-meta"></div></span>';
      a.querySelector('.hist-title').textContent = item.title || item.filename;
      a.querySelector('.hist-meta').textContent = when(item.created) + ' · ' + (item.filename || '');
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        openDoc(item);
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'hist-del';
      del.title = 'delete';
      del.setAttribute('aria-label', 'delete');
      del.textContent = '×';
      del.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        deleteDoc(item);
      });
      li.appendChild(a);
      li.appendChild(del);
      const extra = document.createElement('div');
      extra.className = 'hist-extra';
      if (item.source) {
        const src = document.createElement('a');
        src.className = 'hist-src';
        src.href = item.source;
        src.target = '_blank';
        src.rel = 'noopener noreferrer';
        src.title = item.source;
        src.textContent = shortUrl(item.source);
        src.addEventListener('click', function (ev) { ev.stopPropagation(); });
        extra.appendChild(src);
      }
      const translation = [item.translatedTo, item.translationModel].filter(Boolean).join(' · ');
      if (translation) {
        const t = document.createElement('span');
        t.className = 'hist-model';
        t.textContent = translation;
        extra.appendChild(t);
      }
      if (extra.childNodes.length) li.appendChild(extra);
      histList.appendChild(li);
    });
  }

  function closeDoc() {
    activeId = '';
    activeItem = null;
    frame.hidden = true;
    frame.removeAttribute('src');
    mdView.hidden = true;
    mdView.textContent = '';
    idle.hidden = false;
    stageName.textContent = 'VIEWPORT';
    stageLink.hidden = true;
    stageTranslate.hidden = true;
  }

  function deleteDoc(item) {
    fetch('/?delete=' + encodeURIComponent(item.id), { method: 'POST' }).then(function (r) {
      return r.json();
    }).then(function (data) {
      if (!data || !data.ok) return;
      if (activeId === item.id) closeDoc();
      loadHistory();
    }).catch(function () {});
  }

  function loadHistory() {
    return fetch('/?history=1').then(function (r) { return r.json(); }).then(function (data) {
      renderHistory(data.items || []);
    }).catch(function () {
      renderHistory([]);
    });
  }

  function openDoc(item) {
    activeId = item.id;
    activeItem = item;
    idle.hidden = true;
    stageName.textContent = (item.title || item.filename || 'DOCUMENT').toUpperCase();
    stageLink.href = '/?file=' + encodeURIComponent(item.id) + '&download=1';
    stageLink.hidden = false;
    stageTranslate.hidden = !item.hasSource;
    document.querySelectorAll('#hist-list li').forEach(function (li) {
      li.classList.toggle('active', li.querySelector('a') && li.querySelector('a').dataset.id === item.id);
    });
    if ((item.format || item.mime || '') === 'md' || (item.mime || '').indexOf('markdown') !== -1) {
      frame.hidden = true;
      mdView.hidden = false;
      mdView.textContent = 'ładowanie…';
      fetch('/?file=' + encodeURIComponent(item.id) + '&inline=1').then(function (r) { return r.text(); }).then(function (t) {
        mdView.textContent = t;
      });
      return;
    }
    mdView.hidden = true;
    frame.hidden = false;
    frame.src = '/?file=' + encodeURIComponent(item.id) + '&inline=1';
  }

  const stopButton = document.getElementById('stop');
  let currentJob = null;
  let aborter = null;
  let stopping = false;
  let warmingUp = false;

  function randomJobId() {
    const bytes = new Uint8Array(8);
    crypto.getRandomValues(bytes);
    return Array.from(bytes).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }

  stopButton.addEventListener('click', function () {
    if (!currentJob || stopping) return;
    stopping = true;
    stopButton.disabled = true;
    status.textContent = 'Przerywanie…';
    const job = currentJob;
    fetch('/?cancel=' + encodeURIComponent(job), { method: 'POST' })
      .catch(function () { /* i tak przerywamy po stronie przeglądarki */ })
      .then(function () {
        if (aborter) aborter.abort();
      });
  });

  async function runJob(url) {
    progress.classList.add('on');
    button.disabled = true;
    stageTranslate.disabled = true;
    fill.style.width = '2%';
    status.textContent = 'Start…';
    startTimer();
    currentJob = randomJobId();
    stopping = false;
    warmingUp = false;
    aborter = new AbortController();
    stopButton.disabled = false;
    stopButton.hidden = false;
    const data = new FormData(form);
    data.set('progress', '1');
    data.set('job', currentJob);
    try {
      const res = await fetch(url, { method: 'POST', body: data, signal: aborter.signal });
      if (!res.body) throw new Error('Brak odpowiedzi');
      const reader = res.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      while (true) {
        const step = await reader.read();
        if (step.done) break;
        buf += dec.decode(step.value, { stream: true });
        let nl;
        while ((nl = buf.indexOf('\n')) >= 0) {
          const line = buf.slice(0, nl).trim();
          buf = buf.slice(nl + 1);
          if (line) {
            try { handle(JSON.parse(line)); } catch (ignore) {}
          }
        }
      }
    } catch (err) {
      if (stopping) {
        status.textContent = 'Przerwano';
        fill.style.width = '0%';
      } else {
        const msg = err && err.message ? err.message : String(err);
        if (msg === 'Error in input stream') {
          status.textContent = warmingUp
            ? 'Połączenie przerwane w trakcie ładowania modelu. Serwer modeli może go jeszcze wczytywać — spróbuj ponownie za chwilę.'
            : 'Połączenie przerwane. Wybierz mniejszy model albo wyłącz tłumaczenie.';
        } else {
          status.textContent = msg;
        }
      }
    } finally {
      stopTimer();
      button.disabled = false;
      stageTranslate.disabled = false;
      stopButton.hidden = true;
      stopButton.disabled = false;
      currentJob = null;
      aborter = null;
      stopping = false;
    }
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    runJob('/');
  });

  stageTranslate.addEventListener('click', function () {
    if (!activeItem || !activeItem.hasSource) {
      status.textContent = 'Ten plik nie ma zapisanej treści. Capture jeszcze raz.';
      progress.classList.add('on');
      return;
    }
    if (!langSelect.value) {
      status.textContent = 'Wybierz język tłumaczenia.';
      progress.classList.add('on');
      return;
    }
    runJob('/?replay=' + encodeURIComponent(activeItem.id));
  });

  function handle(ev) {
    if (ev.stage === 'cancelled') {
      status.textContent = ev.message || 'Przerwano';
      fill.style.width = '0%';
      return;
    }
    if (ev.stage === 'error') {
      status.textContent = ev.message || 'Błąd';
      return;
    }
    if (typeof ev.percent === 'number') {
      fill.style.width = Math.max(0, Math.min(100, ev.percent)) + '%';
    }
    if (ev.label) status.textContent = ev.label;
    if (typeof ev.label === 'string') {
      warmingUp = ev.label.indexOf('Ładowanie modelu') === 0;
    }
    if (ev.stage === 'done' && ev.item) {
      fill.style.width = '100%';
      status.textContent = 'Gotowe';
      loadHistory().then(function () { openDoc(ev.item); });
    }
  }

  loadHistory();
})();
