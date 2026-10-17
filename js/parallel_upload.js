/*
 * Parallel Upload for Piwigo - browser side.
 *
 * Every file is cut into fixed-size chunks (≤ 95 MB, so each HTTP request
 * stays under Cloudflare Tunnel's 100 MB body limit). A single pool of N
 * workers pulls chunks across all files - oldest file first - and posts them
 * to pu.upload.chunk, which writes each one straight to its byte offset on
 * the server. Order doesn't matter; a failed chunk is simply re-sent.
 */
(function () {
  'use strict';

  const app = document.getElementById('pu-app');
  if (!app) return;

  const $ = (id) => document.getElementById(id);
  const ds = app.dataset;
  const CFG = {
    token: ds.token,
    chunk: parseInt(ds.chunk, 10),
    concurrency: Math.max(1, parseInt(ds.concurrency, 10) || 4),
    retries: Math.max(0, parseInt(ds.retries, 10) || 0),
    sha: ds.sha === '1' && !!(window.isSecureContext && window.crypto && crypto.subtle),
    exts: ds.exts.split(',').filter(Boolean),
    maxFile: (parseInt(ds.maxfile, 10) || 0) * 1024 * 1024,
    batchUrl: ds.batchUrl,
    strLeave: ds.strLeave,
    stallMs: 90 * 1000,        // no upload progress for this long => abort & retry chunk
    lookahead: 2,              // files opened beyond "concurrency" ahead of time
  };

  // ------------------------------------------------------------------ formatting
  const fmtBytes = (b) => {
    if (!isFinite(b)) return '—';
    const u = ['B', 'kB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
    return (i === 0 ? b.toFixed(0) : b < 10 ? b.toFixed(2) : b < 100 ? b.toFixed(1) : b.toFixed(0)) + ' ' + u[i];
  };
  const fmtTime = (s) => {
    if (!isFinite(s) || s < 0) return '—';
    s = Math.round(s);
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return h ? `${h}:${String(m).padStart(2, '0')}:${String(x).padStart(2, '0')}` : `${m}:${String(x).padStart(2, '0')}`;
  };
  const fmtEta = (s) => {
    if (!isFinite(s) || s < 0) return '—';
    if (s < 60) return Math.max(1, Math.round(s)) + ' s';
    if (s < 3600) return Math.round(s / 60) + ' min';
    const h = Math.floor(s / 3600), m = Math.round((s % 3600) / 60);
    return `${h} h ${m} min`;
  };
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const extOf = (n) => (n.lastIndexOf('.') > 0 ? n.slice(n.lastIndexOf('.') + 1).toLowerCase() : '');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // ------------------------------------------------------------------ web service
  class ApiError extends Error {
    constructor(msg, code, http) { super(msg); this.code = code; this.http = http; }
    get retryable() {
      // network failures, proxy errors, server errors, checksum mismatch
      if (!this.http) return true;
      if ([408, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524, 525, 526, 530].includes(this.http)) return true;
      return this.code === 422 || this.code === 500;
    }
  }

  function parseResponse(http, text) {
    let j = null;
    try { j = JSON.parse(text); } catch (e) { /* not JSON */ }
    if (j && j.stat === 'ok') return j.result;
    if (j && j.stat === 'fail') throw new ApiError(j.message, j.err, http);
    if (http === 413) throw new ApiError('Request too large for the proxy/web server (HTTP 413). Lower the chunk size.', 413, 413);
    if (http === 524) throw new ApiError('Proxy timeout (HTTP 524)', 524, 524);
    const snippet = (text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
    throw new ApiError(`HTTP ${http || 'network error'}${snippet ? ': ' + snippet : ''}`, http, http);
  }

  async function ws(method, params) {
    const body = new URLSearchParams(Object.assign({}, params, { pwg_token: CFG.token }));
    let r;
    try {
      r = await fetch('ws.php?format=json&method=' + method, { method: 'POST', body, credentials: 'same-origin' });
    } catch (e) {
      throw new ApiError('Network error: ' + e.message, 0, 0);
    }
    return parseResponse(r.status, await r.text());
  }

  // ------------------------------------------------------------------ model
  let seq = 0;
  const files = [];
  const S = {
    running: false, paused: false, cancelled: false,
    inflight: 0,                     // chunk/init requests occupying a slot
    t0: 0, elapsedBefore: 0,         // elapsed excludes paused time
    rate: 0, lastSent: 0, lastT: 0, rateSamples: 0,
    uploaded: [], added: 0, updated: 0, category: null,
  };

  class Chunk {
    constructor(file, i) {
      this.file = file; this.i = i;
      this.start = i * file.chunkSize;
      this.end = Math.min(file.size, this.start + file.chunkSize);
      this.len = this.end - this.start;
      this.loaded = 0; this.state = 'queued'; this.attempts = 0;
      this.xhr = null; this.lastProgress = 0; this.err = '';
      this.gen = 0;   // bumped on every dispatch / pause so stale async work can tell it was superseded
    }
  }

  class Job {
    constructor(f) {
      this.id = 'puf' + (++seq);
      this.file = f; this.name = f.name; this.size = f.size;
      this.chunkSize = CFG.chunk;
      this.n = Math.max(1, Math.ceil(f.size / this.chunkSize));
      this.chunks = Array.from({ length: this.n }, (_, i) => new Chunk(this, i));
      this.state = 'queued';   // queued | init | uploading | finishing | processing | done | error | cancelled
      this.uploadId = null; this.err = ''; this.result = null; this.initAttempts = 0;
      this.rate = 0; this.lastSent = 0; this.lastT = 0;
      this.el = null;
    }
    get sent() {
      if (this.state === 'done') return this.size;
      let s = 0;
      for (const c of this.chunks) s += c.state === 'done' ? c.len : c.loaded;
      return s;
    }
    get doneChunks() { return this.chunks.filter((c) => c.state === 'done').length; }
    get open() { return ['init', 'retry-init', 'uploading', 'finishing', 'processing'].includes(this.state); }
    get splitText() {
      if (this.n === 1) return 'single request';
      const last = this.chunks[this.n - 1].len;
      return last === this.chunkSize
        ? `${this.n} × ${fmtBytes(this.chunkSize)}`
        : `${this.n - 1} × ${fmtBytes(this.chunkSize)} + 1 × ${fmtBytes(last)}`;
    }
  }

  // ------------------------------------------------------------------ scheduler
  function nextTask() {
    let openFiles = 0;
    for (const f of files) {
      if (f.open) openFiles++;

      if (f.state === 'queued') {
        if (openFiles >= CFG.concurrency + CFG.lookahead) return null;
        f.state = 'init'; openFiles++;
        return () => initJob(f);
      }
      if (f.state === 'uploading') {
        const c = f.chunks.find((x) => x.state === 'queued');
        if (c) { c.state = CFG.sha ? 'hashing' : 'sending'; c.gen++; return () => sendChunk(c); }
      }
    }
    return null;
  }

  function pump() {
    if (!S.running || S.paused) return;
    let t;
    while (S.inflight < CFG.concurrency && (t = nextTask())) {
      S.inflight++;
      Promise.resolve().then(t).catch((e) => console.error(e)).finally(() => { S.inflight--; pump(); maybeComplete(); });
    }
    maybeComplete();
  }

  async function initJob(f) {
    f.initAttempts++;
    try {
      const r = await ws('pu.upload.init', {
        name: f.name, size: f.size, chunk_size: f.chunkSize,
        category: albumId(), level: $('pu-level').value,
        update_mode: $('pu-update').checked ? 'true' : 'false',
      });
      if (f.state !== 'init') { ws('pu.upload.abort', { upload_id: r.upload_id }).catch(() => {}); return; }
      f.uploadId = r.upload_id;
      f.state = 'uploading';
      f.lastT = performance.now();
    } catch (e) {
      if (e.retryable && f.initAttempts <= CFG.retries + 1) {
        f.state = 'retry-init';
        f.err = e.message + ' — retrying';
        setTimeout(() => { if (f.state === 'retry-init') { f.state = 'queued'; f.err = ''; pump(); } }, backoff(f.initAttempts));
      } else {
        failJob(f, e.message);
      }
    }
  }

  const backoff = (n) => Math.min(30000, 1000 * Math.pow(2, n - 1)) * (0.8 + Math.random() * 0.4);

  async function sendChunk(c) {
    const f = c.file;
    const g = c.gen;
    const stale = () => c.gen !== g || f.state !== 'uploading';
    c.attempts++; c.err = '';
    let blob = f.file.slice(c.start, c.end);
    let sha = '';
    try {
      if (CFG.sha) {
        c.state = 'hashing';
        const buf = await blob.arrayBuffer();
        if (stale()) return;
        const d = await crypto.subtle.digest('SHA-256', buf);
        if (stale()) return;
        sha = Array.from(new Uint8Array(d), (b) => b.toString(16).padStart(2, '0')).join('');
        blob = new Blob([buf]);
      }
      c.state = 'sending';
      await xhrChunk(c, blob, sha);
      if (stale()) return;
      c.state = 'done'; c.loaded = c.len; c.xhr = null;
      if (f.chunks.every((x) => x.state === 'done')) finishJob(f);
    } catch (e) {
      if (e.aborted || stale()) return;          // pause/cancel/fail already reset the chunk
      c.xhr = null; c.loaded = 0;
      if (e.http === 413 || e.code === 413) return failJob(f, e.message);
      if (e.code === 403 || e.code === 404) return failJob(f, e.message);
      if (e.retryable && c.attempts <= CFG.retries) {
        c.state = 'retry'; c.err = e.message;
        // wait outside the worker slot so other chunks keep flowing
        setTimeout(() => { if (c.gen === g && c.state === 'retry') { c.state = 'queued'; pump(); } }, backoff(c.attempts));
      } else {
        c.state = 'error'; c.err = e.message;
        failJob(f, `Chunk ${c.i + 1}/${f.n}: ${e.message}`);
      }
    }
  }

  function xhrChunk(c, blob, sha) {
    return new Promise((resolve, reject) => {
      const fd = new FormData();
      fd.append('pwg_token', CFG.token);
      fd.append('upload_id', c.file.uploadId);
      fd.append('index', c.i);
      fd.append('sha256', sha);
      fd.append('chunk', blob, 'chunk.bin');

      const x = new XMLHttpRequest();
      c.xhr = x; c.lastProgress = performance.now();
      x.open('POST', 'ws.php?format=json&method=pu.upload.chunk');
      x.upload.onprogress = (e) => {
        if (e.lengthComputable && e.total > 0) {
          // e.total includes multipart framing; scale to payload bytes
          c.loaded = Math.min(c.len, Math.round(e.loaded * c.len / e.total));
          c.lastProgress = performance.now();
        }
      };
      x.onload = () => {
        try { resolve(parseResponse(x.status, x.responseText)); } catch (e) { reject(e); }
      };
      x.onerror = () => reject(new ApiError('Network error', 0, 0));
      x.ontimeout = () => reject(new ApiError('Timed out', 0, 0));
      x.onabort = () => { const e = new ApiError(c.stalled ? 'Stalled — no progress' : 'aborted', 0, 0); e.aborted = !c.stalled; c.stalled = false; reject(e); };
      x.send(fd);
    });
  }

  async function finishJob(f) {
    if (f.state !== 'uploading') return;
    f.state = 'finishing';
    try {
      const r = await ws('pu.upload.finish', { upload_id: f.uploadId });
      completeJob(f, r);
    } catch (e) {
      const m = /^Missing chunks: ([\d,]+)/.exec(e.message || '');
      if (m) {                                   // server lost some chunks: resend them
        m[1].split(',').forEach((i) => { const c = f.chunks[+i]; if (c) { c.state = 'queued'; c.loaded = 0; } });
        f.state = 'uploading'; pump(); return;
      }
      if (e.code === 409 || e.retryable) {      // proxy timeout (524) while the server keeps working
        f.state = 'processing';
        pollJob(f);
        return;
      }
      failJob(f, e.message);
    }
  }

  async function pollJob(f) {
    for (let i = 0; i < 2400 && f.state === 'processing'; i++) {    // up to ~2 h
      await sleep(i < 10 ? 2000 : 5000);
      try {
        const s = await ws('pu.upload.status', { upload_id: f.uploadId });
        if (s.state === 'done') return completeJob(f, s.result);
        if (s.state === 'error') return failJob(f, s.message);
        if (s.state === 'uploading') {
          // finish never got the lock (request dropped before reaching PHP): try again
          f.state = 'uploading';
          (s.missing || []).forEach((i) => { const c = f.chunks[i]; if (c) { c.state = 'queued'; c.loaded = 0; } });
          if (f.chunks.every((c) => c.state === 'done')) finishJob(f); else pump();
          return;
        }
      } catch (e) {
        if (!e.retryable) return failJob(f, e.message);
      }
    }
    if (f.state === 'processing') failJob(f, 'Server did not report completion');
  }

  function completeJob(f, r) {
    f.state = 'done'; f.result = r;
    S.uploaded.push(parseInt(r.image_id, 10));
    if (r.add_status === 'update') S.updated++; else S.added++;
    if (r.category) S.category = r.category;
    addThumb(f, r);
    maybeComplete();
    pump();
  }

  function failJob(f, msg) {
    if (['done', 'error', 'cancelled'].includes(f.state)) return;
    f.state = 'error'; f.err = msg;
    for (const c of f.chunks) {
      c.gen++;
      if (c.xhr) { const x = c.xhr; c.xhr = null; x.abort(); }
      if (c.state !== 'done') { c.state = c.state === 'error' ? 'error' : 'queued'; c.loaded = 0; }
    }
    if (f.uploadId) ws('pu.upload.abort', { upload_id: f.uploadId }).catch(() => {});
    maybeComplete();
  }

  function maybeComplete() {
    if (!S.running) return;
    if (files.some((f) => f.state !== 'done' && f.state !== 'error' && f.state !== 'cancelled')) return;
    if (S.inflight > 0) return;
    if (!S.paused || S.draining) S.elapsedBefore += performance.now() - S.t0;
    S.running = false; S.paused = false; S.draining = false;
    window.removeEventListener('beforeunload', onLeave);
    if (window.Piecon) try { Piecon.reset(); } catch (e) {}

    if (S.uploaded.length && S.category) {
      ws('pwg.images.uploadCompleted', { image_id: S.uploaded.join(','), category_id: S.category.id }).catch(() => {});
    }
    showResults();
    render(true);
  }

  // ------------------------------------------------------------------ controls
  function albumId() { return $('pu-album').value; }

  function onLeave(e) { e.preventDefault(); e.returnValue = CFG.strLeave; return CFG.strLeave; }

  function start() {
    if (!albumId()) { $('pu-album').focus(); flash($('pu-album')); return; }
    if (!files.some((f) => f.state === 'queued')) return;
    if (!S.running) {
      S.running = true; S.paused = false; S.cancelled = false;
      S.t0 = performance.now();
      S.lastT = S.t0; S.lastSent = totals().sent; S.rateSamples = 0; S.rate = 0;
      window.addEventListener('beforeunload', onLeave);
    }
    $('pu-album').disabled = $('pu-level').disabled = $('pu-update').disabled = true;
    $('pu-results').hidden = true;
    pump();
    render(true);
  }

  // Soft pause: stop dispatching new chunks, let in-flight ones land so no bytes are wasted.
  // (Cancel is the hard stop.)
  function pause() {
    if (!S.running) return;
    if (!S.paused) {
      S.paused = true;
      S.draining = true;            // clock keeps running until in-flight chunks have landed
      for (const f of files) f.rate = 0;
    } else {
      S.paused = false;
      if (!S.draining) S.t0 = performance.now();
      S.draining = false;
      S.lastT = S.t0; S.lastSent = totals().sent; S.rateSamples = 0;
      for (const f of files) { f.lastSent = f.sent; f.lastT = S.t0; }
      pump();
    }
    render(true);
  }

  function cancel() {
    if (!confirm('Cancel all remaining uploads? Files already stored stay in the gallery.')) return;
    S.cancelled = true;
    for (const f of files) {
      if (f.state === 'done' || f.state === 'error') continue;
      if (f.state === 'processing' || f.state === 'finishing') continue;   // let server-side registration finish
      for (const c of f.chunks) if (c.xhr) { const x = c.xhr; c.xhr = null; x.abort(); }
      if (f.uploadId) ws('pu.upload.abort', { upload_id: f.uploadId }).catch(() => {});
      f.state = 'cancelled';
    }
    S.paused = false;
    maybeComplete();
    render(true);
  }

  function removeJob(f) {
    if (f.open) {
      if (f.state === 'processing' || f.state === 'finishing') return;
      for (const c of f.chunks) if (c.xhr) { const x = c.xhr; c.xhr = null; x.abort(); }
      if (f.uploadId) ws('pu.upload.abort', { upload_id: f.uploadId }).catch(() => {});
      f.state = 'cancelled';
    }
    const i = files.indexOf(f);
    if (i >= 0) files.splice(i, 1);
    if (f.el) f.el.remove();
    pump();
    render(true);
  }

  function clearList() {
    for (const f of files.slice()) if (!f.open) removeJob(f);
    if (!files.length) { S.elapsedBefore = 0; S.uploaded = []; S.added = S.updated = 0; S.category = null; }
    render(true);
  }

  // ------------------------------------------------------------------ adding files
  function addFiles(list) {
    const rejected = [];
    for (const f of list) {
      const ext = extOf(f.name);
      if (!CFG.exts.includes(ext)) { rejected.push(`${f.name}: .${ext || '?'} not allowed`); continue; }
      if (CFG.maxFile && f.size > CFG.maxFile) { rejected.push(`${f.name}: larger than ${fmtBytes(CFG.maxFile)}`); continue; }
      if (f.size === 0) { rejected.push(`${f.name}: empty file`); continue; }
      if (files.some((j) => j.name === f.name && j.size === f.size && j.file.lastModified === f.lastModified && j.state !== 'done')) continue;
      const j = new Job(f);
      files.push(j);
      $('pu-files').appendChild(buildRow(j));
    }
    if (rejected.length) alert('Skipped:\n' + rejected.slice(0, 20).join('\n') + (rejected.length > 20 ? `\n… and ${rejected.length - 20} more` : ''));
    $('pu-dash').hidden = files.length === 0;
    if (S.running && !S.paused) pump();
    render(true);
  }

  // ------------------------------------------------------------------ rendering
  const STATE_LABEL = {
    queued: 'Queued', init: 'Opening', 'retry-init': 'Retrying', uploading: 'Uploading', finishing: 'Registering',
    processing: 'Processing on server', done: 'Stored', error: 'Failed', cancelled: 'Cancelled',
  };

  function buildRow(f) {
    const li = document.createElement('li');
    li.className = 'pu-file';
    li.id = f.id;
    const dense = f.n > 60 ? ' pu-dense' : '';
    li.innerHTML = `
      <div class="pu-f-top">
        <span class="pu-f-ext">${esc(extOf(f.name) || '?')}</span>
        <span class="pu-f-name" title="${esc(f.name)}">${esc(f.name)}</span>
        <span class="pu-f-size">${fmtBytes(f.size)}</span>
        <span class="pu-f-state"></span>
        <span class="pu-f-pct"></span>
        <button type="button" class="pu-f-x" title="Remove">×</button>
      </div>
      <div class="pu-f-meta">
        <span class="pu-f-split">Split: <b>${f.splitText}</b></span>
        <span class="pu-f-rate"></span>
        <span class="pu-f-eta"></span>
      </div>
      <div class="pu-segs${dense}">${f.chunks.map((c) =>
        `<div class="pu-seg s-queued" style="flex-grow:${(c.len / f.chunkSize).toFixed(4)}" data-i="${c.i}"><div class="pu-seg-fill"></div></div>`).join('')}
      </div>
      <div class="pu-f-live"></div>`;
    li.querySelector('.pu-f-x').addEventListener('click', () => removeJob(f));
    f.el = li;
    f.segEls = Array.from(li.querySelectorAll('.pu-seg'));
    f.ref = {
      state: li.querySelector('.pu-f-state'), pct: li.querySelector('.pu-f-pct'),
      rate: li.querySelector('.pu-f-rate'), eta: li.querySelector('.pu-f-eta'),
      live: li.querySelector('.pu-f-live'), x: li.querySelector('.pu-f-x'),
    };
    f.lastRender = '';
    return li;
  }

  function totals() {
    let size = 0, sent = 0, chunks = 0, chunksDone = 0, done = 0, failed = 0, live = 0;
    for (const f of files) {
      if (f.state === 'cancelled') continue;
      size += f.size; sent += f.sent;
      chunks += f.n; chunksDone += f.state === 'done' ? f.n : f.doneChunks;
      if (f.state === 'done') done++;
      if (f.state === 'error') failed++;
      for (const c of f.chunks) if (c.state === 'sending' || c.state === 'hashing') live++;
    }
    return { size, sent, chunks, chunksDone, done, failed, live, count: files.filter((f) => f.state !== 'cancelled').length };
  }

  function sampleRates(now, t) {
    const dt = (now - S.lastT) / 1000;
    if (dt < 1) return;
    const inst = Math.max(0, (t.sent - S.lastSent) / dt);
    // EMA over ~5 s of samples; first samples bias toward instantaneous
    const a = S.rateSamples < 3 ? 0.6 : 0.2;
    S.rate = S.rateSamples === 0 ? inst : S.rate * (1 - a) + inst * a;
    S.rateSamples++;
    S.lastSent = t.sent; S.lastT = now;
    for (const f of files) {
      if (f.state !== 'uploading') { f.rate = 0; f.lastSent = f.sent; f.lastT = now; continue; }
      const fdt = (now - (f.lastT || now)) / 1000;
      if (fdt <= 0) { f.lastT = now; f.lastSent = f.sent; continue; }
      const fi = Math.max(0, (f.sent - f.lastSent) / fdt);
      f.rate = f.rate ? f.rate * 0.7 + fi * 0.3 : fi;
      f.lastSent = f.sent; f.lastT = now;
    }
  }

  function watchdog(now) {
    for (const f of files) {
      if (f.state !== 'uploading') continue;
      for (const c of f.chunks) {
        if (c.state === 'sending' && c.xhr && now - c.lastProgress > CFG.stallMs) {
          c.stalled = true;
          c.xhr.abort();
        }
      }
    }
  }

  function render(force) {
    const now = performance.now();
    const t = totals();
    const active = S.running && !S.paused;
    if (active) sampleRates(now, t);
    if (S.running) watchdog(now);
    if (S.paused && S.draining && t.live === 0) { S.elapsedBefore += now - S.t0; S.draining = false; }
    const clock = active || (S.paused && S.draining);

    const elapsed = (S.elapsedBefore + (clock ? now - S.t0 : 0)) / 1000;
    const pct = t.size ? (t.sent / t.size) * 100 : 0;
    const remaining = t.size - t.sent;
    const warm = S.rateSamples >= 3 && S.rate > 0;

    // header
    let label, eta, sub;
    if (S.running && S.paused && t.live > 0) { label = 'Pausing'; eta = fmtBytes(remaining); sub = `letting ${t.live} in-flight chunk${t.live > 1 ? 's' : ''} finish`; }
    else if (S.running && S.paused) { label = 'Paused'; eta = fmtBytes(remaining); sub = 'left to send'; }
    else if (active) {
      label = 'Uploading';
      if (remaining <= 0) { eta = 'finishing'; sub = 'server is registering photos'; }
      else if (warm) { eta = fmtEta(remaining / S.rate); sub = 'time remaining'; }
      else { eta = 'estimating…'; sub = 'measuring throughput'; }
    } else if (t.count && t.done + t.failed === t.count && elapsed > 0) {
      label = t.failed ? 'Finished with errors' : 'Complete'; eta = fmtTime(elapsed); sub = 'total time';
    } else { label = 'Ready'; eta = t.count ? fmtBytes(t.size) : '—'; sub = t.count ? `${t.count} file${t.count > 1 ? 's' : ''} queued` : ''; }

    setText('pu-state-label', label);
    setText('pu-eta', eta);
    setText('pu-eta-sub', sub);
    $('pu-total-fill').style.width = pct.toFixed(2) + '%';
    setText('pu-total-pct', pct.toFixed(pct < 10 && pct > 0 ? 1 : 0) + '%');
    setText('pu-s-bytes', `${fmtBytes(t.sent)} / ${fmtBytes(t.size)}`);
    setText('pu-s-speed', active && warm ? fmtBytes(S.rate) + '/s' : (elapsed > 0 && !active && t.sent ? 'avg ' + fmtBytes(t.sent / elapsed) + '/s' : '—'));
    setText('pu-s-elapsed', elapsed > 0 ? fmtTime(elapsed) : '—');
    setText('pu-s-conn', `${t.live} / ${CFG.concurrency}`);
    setText('pu-s-chunks', `${t.chunksDone} / ${t.chunks}`);
    setText('pu-s-files', `${t.done} / ${t.count}` + (t.failed ? ` · ${t.failed} failed` : ''));
    app.classList.toggle('is-running', S.running);
    app.classList.toggle('is-paused', S.paused);

    if (window.Piecon && S.running) try { Piecon.setProgress(Math.floor(pct)); } catch (e) {}

    // buttons
    const queued = files.some((f) => f.state === 'queued');
    $('pu-start').disabled = !queued || !albumId() || (S.running && !S.paused && !queued);
    $('pu-start').hidden = S.running;
    $('pu-pause').hidden = !S.running;
    $('pu-pause').innerHTML = S.paused ? '<span class="icon-play"></span> Resume' : '<span class="icon-pause"></span> Pause';
    $('pu-cancel').hidden = !S.running;
    $('pu-clear').hidden = S.running;
    if (!S.running) $('pu-album').disabled = $('pu-level').disabled = $('pu-update').disabled = false;

    // rows
    for (const f of files) renderRow(f, force, warm);
  }

  function renderRow(f, force, warm) {
    if (!f.el) return;
    const sent = f.sent;
    const key = f.state + '|' + sent + '|' + f.err + '|' + f.chunks.map((c) => c.state[0]).join('') + '|' + Math.round(f.rate / 1e4);
    if (!force && key === f.lastRender) return;
    f.lastRender = key;

    const liveN = f.chunks.filter((c) => c.state === 'sending' || c.state === 'hashing').length;
    const waiting = f.state === 'uploading' && liveN === 0 && !f.chunks.some((c) => c.state === 'retry');
    f.el.className = 'pu-file s-' + f.state + (waiting ? ' is-waiting' : '');
    const r = f.ref;
    r.state.textContent = waiting ? (S.paused ? 'Paused' : 'Waiting for a slot') : (STATE_LABEL[f.state] || f.state);
    r.pct.textContent = Math.floor((sent / f.size) * 100) + '%';
    r.x.hidden = f.state === 'finishing' || f.state === 'processing';

    if (f.state === 'uploading') {
      const remain = f.size - sent;
      const live = f.chunks.filter((c) => c.state === 'sending' || c.state === 'hashing').length;
      r.rate.textContent = `${live} connection${live === 1 ? '' : 's'}` + (f.rate > 0 && !S.paused ? ` · ${fmtBytes(f.rate)}/s` : '');
      r.eta.textContent = f.rate > 0 && warm && !S.paused ? `≈ ${fmtEta(remain / f.rate)} left` : '';
    } else if (f.state === 'done' && f.result) {
      r.rate.innerHTML = `<a href="admin.php?page=photo-${f.result.image_id}" target="_blank">#${f.result.image_id}</a> ${f.result.add_status === 'update' ? '(updated)' : ''}`;
      r.eta.textContent = '';
    } else {
      r.rate.textContent = f.state === 'queued' ? `${f.doneChunks}/${f.n} chunks` : '';
      r.eta.textContent = '';
    }

    // segments
    for (let i = 0; i < f.n; i++) {
      const c = f.chunks[i], el = f.segEls[i];
      const st = f.state === 'done' ? 'done' : c.state;
      const cls = 'pu-seg s-' + st;
      if (el.className !== cls) el.className = cls;
      const p = st === 'done' ? 100 : st === 'sending' ? (c.loaded / c.len) * 100 : 0;
      el.firstChild.style.width = p.toFixed(1) + '%';
      el.title = `Chunk ${i + 1}/${f.n} · bytes ${fmtBytes(c.start)}–${fmtBytes(c.end)} (${fmtBytes(c.len)})\n${st}` +
        (st === 'sending' ? ` ${p.toFixed(0)}%` : '') + (c.attempts > 1 ? ` · attempt ${c.attempts}` : '') + (c.err ? `\n${c.err}` : '');
    }

    // live chunk readout
    const lines = [];
    for (const c of f.chunks) {
      if (c.state === 'sending') lines.push(`<span class="pu-chip s-sending">#${c.i + 1} ${Math.floor((c.loaded / c.len) * 100)}%${c.attempts > 1 ? ' ↻' + (c.attempts - 1) : ''}</span>`);
      else if (c.state === 'hashing') lines.push(`<span class="pu-chip s-hashing">#${c.i + 1} checksum</span>`);
      else if (c.state === 'retry') lines.push(`<span class="pu-chip s-retry" title="${esc(c.err)}">#${c.i + 1} retry ${c.attempts}/${CFG.retries}</span>`);
    }
    if (f.state === 'processing') lines.push('<span class="pu-chip s-hashing">Upload complete — waiting for Piwigo to register the file (proxy timed out, polling)</span>');
    if (f.state === 'finishing') lines.push('<span class="pu-chip s-hashing">All chunks stored — registering photo…</span>');
    if (f.err) lines.push(`<span class="pu-err">${esc(f.err)}</span>`);
    r.live.innerHTML = lines.join('');
  }

  function setText(id, v) { const e = $(id); if (e.textContent !== v) e.textContent = v; }
  function flash(el) { el.classList.add('pu-flash'); setTimeout(() => el.classList.remove('pu-flash'), 900); }

  function addThumb(f, r) {
    const a = document.createElement('a');
    a.href = 'admin.php?page=photo-' + r.image_id;
    a.target = '_blank';
    a.innerHTML = `<img src="${esc(r.square_src)}" alt="" title="${esc(r.name || f.name)}">`;
    $('pu-thumbs').prepend(a);
  }

  function showResults() {
    if (!S.uploaded.length) return;
    const ids = [...new Set(S.uploaded)];
    const parts = [];
    const dupes = S.uploaded.length - ids.length;
    const added = S.added - dupes;
    if (added > 0) parts.push(`${added} photo${added > 1 ? 's' : ''} added`);
    if (S.updated) parts.push(`${S.updated} updated`);
    if (dupes > 0) parts.push(`${dupes} duplicate${dupes > 1 ? 's' : ''} already in the gallery`);
    let title = parts.join(', ');
    if (S.category) title += ` · album “${S.category.label.replace(/<[^>]+>/g, '')}” now has ${S.category.nb_photos} photos`;
    $('pu-results-title').textContent = title;
    $('pu-batch').href = CFG.batchUrl + ids.join(',');
    $('pu-batch').textContent = `Manage this set of ${ids.length} photo${ids.length > 1 ? 's' : ''}`;
    $('pu-results').hidden = false;
  }

  // ------------------------------------------------------------------ wiring
  const input = $('pu-input');
  input.accept = CFG.exts.map((e) => '.' + e).join(',');
  $('pu-exts').textContent = CFG.exts.join(', ');
  $('pu-plan-chunk').textContent = fmtBytes(CFG.chunk);
  $('pu-browse').addEventListener('click', () => input.click());
  input.addEventListener('change', () => { addFiles(Array.from(input.files)); input.value = ''; });

  const drop = $('pu-drop');
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', (e) => addFiles(Array.from(e.dataTransfer.files || [])));
  drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
  // allow dropping anywhere on the page without the browser opening the file
  window.addEventListener('dragover', (e) => e.preventDefault());
  window.addEventListener('drop', (e) => { if (!drop.contains(e.target)) { e.preventDefault(); addFiles(Array.from(e.dataTransfer.files || [])); } });

  $('pu-start').addEventListener('click', start);
  $('pu-pause').addEventListener('click', pause);
  $('pu-cancel').addEventListener('click', cancel);
  $('pu-clear').addEventListener('click', clearList);

  const album = $('pu-album');
  try {
    if (!album.value) {
      const last = localStorage.getItem('pu.album');
      if (last && album.querySelector(`option[value="${CSS.escape(last)}"]`)) album.value = last;
    }
  } catch (e) {}
  album.addEventListener('change', () => { try { localStorage.setItem('pu.album', album.value); } catch (e) {} render(true); });

  document.querySelectorAll('.pu-toggle-settings').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); $('pu-settings').open = true; $('pu-settings').scrollIntoView({ behavior: 'smooth' });
  }));

  setInterval(render, 250);
  render(true);

  // debug / automation hook
  window.ParallelUpload = { files, state: S, config: CFG, addFiles, start, pause, cancel };
})();
