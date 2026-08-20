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

  let timerId = 0;
  let startedAt = 0;
  let activeId = '';

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

  fetch('/?models=1').then(function (r) { return r.json(); }).then(function (data) {
    const models = data.models || [];
    const preferred = modelSelect.dataset.default || '';
    modelSelect.innerHTML = '';
    if (!models.length) {
      const o = document.createElement('option');
      o.value = '';
      o.textContent = data.message || 'Brak modeli w Ollama';
      modelSelect.appendChild(o);
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
    syncLang();
  }).catch(function () {
    modelSelect.innerHTML = '';
    const o = document.createElement('option');
    o.value = '';
    o.textContent = 'Nie można wczytać modeli z Ollama';
    modelSelect.appendChild(o);
  });
  syncLang();

  function when(ts) {
    const d = new Date((ts || 0) * 1000);
    if (Number.isNaN(d.getTime())) return '';
    return d.toISOString().slice(0, 16).replace('T', ' ');
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
      histList.appendChild(li);
    });
  }

  function closeDoc() {
    activeId = '';
    frame.hidden = true;
    frame.removeAttribute('src');
    mdView.hidden = true;
    mdView.textContent = '';
    idle.hidden = false;
    stageName.textContent = 'VIEWPORT';
    stageLink.hidden = true;
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
    idle.hidden = true;
    stageName.textContent = (item.title || item.filename || 'DOCUMENT').toUpperCase();
    stageLink.href = '/?file=' + encodeURIComponent(item.id) + '&download=1';
    stageLink.hidden = false;
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

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    progress.classList.add('on');
    button.disabled = true;
    fill.style.width = '2%';
    status.textContent = 'Start…';
    startTimer();
    const data = new FormData(form);
    data.set('progress', '1');
    try {
      const res = await fetch('/', { method: 'POST', body: data });
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
      const msg = err && err.message ? err.message : String(err);
      status.textContent = msg === 'Error in input stream'
        ? 'Połączenie przerwane. Wybierz mniejszy model albo wyłącz tłumaczenie.'
        : msg;
    } finally {
      stopTimer();
      button.disabled = false;
    }
  });

  function handle(ev) {
    if (ev.stage === 'error') {
      status.textContent = ev.message || 'Błąd';
      return;
    }
    if (typeof ev.percent === 'number') {
      fill.style.width = Math.max(0, Math.min(100, ev.percent)) + '%';
    }
    if (ev.label) status.textContent = ev.label;
    if (ev.stage === 'done' && ev.item) {
      fill.style.width = '100%';
      status.textContent = 'Gotowe';
      loadHistory().then(function () { openDoc(ev.item); });
    }
  }

  loadHistory();
})();
