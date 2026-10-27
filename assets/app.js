(() => {
  'use strict';
  const $ = (selector, root = document) => root.querySelector(selector);
  const state = { jobs: [], restores: [], currentRestore: null, restoreBusy: false, initialized: false, filter: 'all', search: '', csrf: window.BARGIR.csrf || '', processing: new Set(), expanded: new Set(), deleteId: null, timer: null, directoryCurrent: null };
  const fa = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 });
  const maxMultipartConnections = Math.max(1, Number(window.BARGIR.maxMultipartConnections || 6));
  const labels = { queued: 'در صف', downloading: 'در حال دانلود', paused: 'متوقف‌شده', completed: 'تکمیل‌شده', failed: 'ناموفق' };
  const icons = id => `<svg><use href="#${id}"/></svg>`;

  function bytes(value) {
    if (value === null || value === undefined || value < 0) return 'نامشخص';
    const units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    let n = Number(value), i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${fa.format(n)} ${units[i]}`;
  }
  function escapeHtml(value) {
    const div = document.createElement('div'); div.textContent = String(value ?? ''); return div.innerHTML;
  }
  async function api(action, data = null) {
    const options = { headers: { 'Accept': 'application/json' } };
    if (data !== null) {
      options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.headers['X-CSRF-Token'] = state.csrf;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(`api.php?action=${encodeURIComponent(action)}`, options);
    let body; try { body = await response.json(); } catch { throw new Error('پاسخ سرور قابل خواندن نیست.'); }
    if (!response.ok || !body.ok) {
      const error = new Error(body.error || 'خطایی رخ داد.');
      error.details = body;
      error.status = response.status;
      throw error;
    }
    if (body.csrf) state.csrf = body.csrf;
    return body;
  }
  function toast(message) {
    const el = $('#toast'); el.textContent = message; el.classList.add('show');
    clearTimeout(el._timer); el._timer = setTimeout(() => el.classList.remove('show'), 2800);
  }
  function showLogin(message = 'نشست ورود معتبر نیست؛ لطفاً دوباره وارد شوید.') {
    clearInterval(state.timer); state.timer = null;
    $('#appView').hidden = true;
    $('#authView').hidden = false;
    $('#authError').textContent = message;
    const password = $('#password');
    password.value = '';
    password.focus();
  }
  function chooseFileConflict(filename) {
    const dialog = $('#fileConflictDialog');
    $('#conflictFilename').textContent = filename;
    dialog.returnValue = 'cancel';
    dialog.showModal();
    return new Promise(resolve => dialog.addEventListener('close', () => resolve(dialog.returnValue || 'cancel'), { once: true }));
  }
  function percent(job) {
    if (!job.size) return job.status === 'completed' ? 100 : 0;
    return Math.max(0, Math.min(100, Number(job.downloaded || 0) / Number(job.size) * 100));
  }
  function formatPercent(value) {
    const digits = value > 0 && value < 1 ? 2 : 1;
    return new Intl.NumberFormat('fa-IR', { maximumFractionDigits: digits }).format(value);
  }
  function formatRemaining(totalSeconds) {
    const seconds = Math.max(0, Math.round(totalSeconds));
    if (seconds >= 3600) {
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      return minutes ? `${fa.format(hours)} ساعت و ${fa.format(minutes)} دقیقه` : `${fa.format(hours)} ساعت`;
    }
    if (seconds >= 60) {
      const minutes = Math.floor(seconds / 60);
      const remainder = seconds % 60;
      return remainder ? `${fa.format(minutes)} دقیقه و ${fa.format(remainder)} ثانیه` : `${fa.format(minutes)} دقیقه`;
    }
    return `${fa.format(seconds)} ثانیه`;
  }
  function destinationPath(job) {
    const directory = String(job.destination_label || 'storage').replace(/\\/g, '/').replace(/^~\//, '').replace(/\/$/, '');
    return `${directory || 'storage'}/${job.filename}`;
  }
  function absoluteDestinationPath(job) {
    const directory = String(job.destination_dir || job.destination_label || 'storage').replace(/\\/g, '/').replace(/\/$/, '');
    return `${directory}/${job.filename}`;
  }
  function setDestinationSelection(value = '', label = '') {
    $('#destinationInput').value = value;
    const target = $('#destinationPickerLabel');
    target.textContent = label || 'انتخاب مسیر ذخیره…';
    target.classList.toggle('selected', Boolean(value));
  }
  async function loadDirectories(path = '') {
    const list = $('#directoryList');
    const error = $('#directoryError');
    list.innerHTML = '<div class="directory-loading">در حال خواندن پوشه‌ها…</div>';
    error.textContent = '';
    try {
      const body = await api('browse_dirs', { path });
      state.directoryCurrent = body.current;
      $('#directoryCurrent').textContent = body.current.label;
      $('#directoryBack').disabled = body.parent === null;
      $('#directoryBack').dataset.path = body.parent ?? '';
      list.innerHTML = body.directories.length ? body.directories.map(directory => `<button type="button" class="directory-item" data-directory-path="${escapeHtml(directory.path)}" role="treeitem">${icons('i-folder')}<span dir="ltr">${escapeHtml(directory.name)}</span><b>←</b></button>`).join('') : '<div class="directory-empty">این پوشه، زیرپوشه‌ای ندارد.</div>';
    } catch (loadError) {
      state.directoryCurrent = null;
      list.innerHTML = '';
      error.textContent = loadError.message;
    }
  }
  function actionButtons(job) {
    const list = [];
    if (job.status === 'completed') list.push(`<a class="action-btn link" href="download.php?id=${job.id}" title="دریافت فایل">${icons('i-download')}</a>`);
    if (['queued', 'downloading'].includes(job.status)) {
      const resumable = Boolean(job.range_supported);
      const pauseTitle = resumable ? 'توقف' : 'سرور مبدأ از ادامه دانلود پشتیبانی نمی‌کند';
      const pauseButton = `<button class="action-btn" data-action="pause" data-id="${job.id}" title="${pauseTitle}" aria-label="${pauseTitle}" ${resumable ? '' : 'disabled aria-disabled="true"'}>${icons('i-pause')}</button>`;
      list.push(resumable ? pauseButton : `<span class="disabled-action" title="${pauseTitle}" aria-label="${pauseTitle}" tabindex="0">${pauseButton}</span>`);
    }
    if (job.status === 'paused') list.push(`<button class="action-btn" data-action="resume" data-id="${job.id}" title="ادامه">${icons('i-play')}</button>`);
    if (job.status === 'failed') list.push(`<button class="action-btn" data-action="retry" data-id="${job.id}" title="تلاش دوباره">${icons('i-refresh')}</button>`);
    list.push(`<button class="action-btn delete" data-action="delete" data-id="${job.id}" title="حذف">${icons('i-trash')}</button>`);
    return list.join('');
  }
  function connectionsControl(job) {
    const supported = Boolean(job.range_supported);
    const completed = job.status === 'completed';
    const selected = supported ? Math.max(1, Math.min(maxMultipartConnections, Number(job.connections || 1))) : 1;
    const options = Array.from({ length: maxMultipartConnections }, (_, index) => index + 1).map(value => `<option value="${value}" ${value === selected ? 'selected' : ''}>${fa.format(value)}</option>`).join('');
    const title = !supported ? 'سرور مبدأ از دانلود چندبخشی پشتیبانی نمی‌کند' : completed ? 'این دانلود تکمیل شده است' : 'تعداد اتصال‌های هم‌زمان این دانلود';
    const select = `<select data-connections="${job.id}" aria-label="${title}" title="${title}" ${!supported || completed ? 'disabled' : ''}>${supported ? options : '<option value="1" selected>۱</option>'}</select>`;
    return !supported ? `<span class="connection-control disabled-action" title="${title}" tabindex="0">${select}</span>` : `<span class="connection-control" title="${title}">${select}</span>`;
  }
  function chunkDetails(job) {
    const chunks = Array.isArray(job.active_chunks) ? job.active_chunks : [];
    const heading = `<div class="chunk-detail-head"><strong>جزئیات بخش‌های دانلود</strong><span>اندازه پویای فعلی: ${bytes(job.adaptive_chunk_size || 0)} · ${fa.format(job.connections || 1)} اتصال تنظیم‌شده</span></div>`;
    if (!chunks.length) return `${heading}<div class="chunk-empty">هنوز بخشی برای این مرحله ثبت نشده است.</div>`;
    const rows = chunks.map(chunk => {
      const size = Math.max(1, Number(chunk.end) - Number(chunk.start) + 1);
      const received = Math.min(size, Number(chunk.downloaded || 0));
      const progress = Math.max(0, Math.min(100, received / size * 100));
      const status = chunk.status === 'completed' ? 'تکمیل‌شده' : 'در حال دریافت';
      return `<tr><td>${fa.format(chunk.index)}</td><td dir="ltr">${fa.format(chunk.start)} – ${fa.format(chunk.end)}</td><td>${bytes(size)}</td><td><div class="chunk-progress"><i style="width:${progress}%"></i></div><span>${formatPercent(progress)}٪</span></td><td>${bytes(received)}</td><td><span class="chunk-status ${chunk.status}">${status}</span></td></tr>`;
    }).join('');
    return `${heading}<div class="chunk-table-wrap"><table class="chunk-table"><thead><tr><th>#</th><th>بازه بایت</th><th>اندازه</th><th>پیشرفت</th><th>دریافت‌شده</th><th>وضعیت</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }
  function render() {
    const filtered = state.jobs.filter(job => {
      const searchable = `${job.filename} ${job.url || ''} ${destinationPath(job)}`.toLocaleLowerCase('fa');
      const matchesSearch = !state.search || searchable.includes(state.search);
      const matchesFilter = state.filter === 'all' || (state.filter === 'active' && ['queued', 'downloading', 'paused'].includes(job.status)) || job.status === state.filter;
      return matchesSearch && matchesFilter;
    });
    const tbody = $('#jobsBody');
    tbody.innerHTML = filtered.map(job => {
      const p = percent(job);
      const progressWidth = p > 0 ? `max(${p}%, 3px)` : '0';
      const eta = job.speed > 0 && job.size ? Math.max(0, Math.round((job.size - job.downloaded) / job.speed)) : null;
      const barClass = job.status === 'completed' ? 'complete' : job.status === 'paused' ? 'paused' : '';
      const speed = ['queued', 'downloading'].includes(job.status) && job.speed ? `${bytes(job.speed)}/ثانیه` : '—';
      const expanded = state.expanded.has(job.id);
      return `<tr>
        <td><div class="file-cell"><button class="row-toggle ${expanded ? 'open' : ''}" type="button" data-expand="${job.id}" aria-expanded="${expanded}" title="نمایش جزئیات بخش‌ها">⌄</button><span class="file-icon">${icons('i-file')}</span><div class="file-meta"><span class="file-name" title="${escapeHtml(job.filename)}">${escapeHtml(job.filename)}</span><span class="file-url" title="${escapeHtml(job.url)}">${escapeHtml(job.url)}</span></div></div></td>
        <td class="path-cell"><span class="path-value" title="${escapeHtml(absoluteDestinationPath(job))}">${escapeHtml(destinationPath(job))}</span></td>
        <td><span class="size-main">${bytes(job.size)}</span><span class="size-sub">${bytes(job.downloaded)} دریافت شده</span></td>
        <td class="progress-cell"><div class="progress-line"><span class="progress-number">${formatPercent(p)}٪</span><span class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${p.toFixed(2)}"><i class="progress-bar ${barClass}" style="width:${progressWidth}"></i></span></div></td>
        <td class="speed-cell"><span class="speed-main">${speed}</span><span class="speed-sub">${eta !== null ? `${formatRemaining(eta)} مانده` : (job.range_supported ? 'قابل ادامه' : 'بدون پشتیبانی ادامه')}</span></td>
        <td class="connections-cell">${connectionsControl(job)}</td>
        <td><span class="status ${job.status}">${labels[job.status] || job.status}</span></td>
        <td><div class="actions">${actionButtons(job)}</div></td>
      </tr>${expanded ? `<tr class="chunk-detail-row"><td colspan="8">${chunkDetails(job)}</td></tr>` : ''}${job.error ? `<tr class="error-row"><td colspan="8">${escapeHtml(job.error)}</td></tr>` : ''}`;
    }).join('');
    $('#emptyState').hidden = filtered.length !== 0;
    $('#resultCount').textContent = `${fa.format(filtered.length)} مورد`;
    $('#statActive').textContent = fa.format(state.jobs.filter(j => j.status === 'downloading').length);
    $('#statQueued').textContent = fa.format(state.jobs.filter(j => j.status === 'queued').length);
    $('#statCompleted').textContent = fa.format(state.jobs.filter(j => j.status === 'completed').length);
    $('#footerSummary').textContent = `${fa.format(state.jobs.length)} دانلود در لیست`;
  }
  async function refresh() {
    try {
      const body = await api('list'); state.jobs = body.jobs; render();
      if (body.disk.total) {
        $('#statDisk').textContent = bytes(body.disk.free);
        $('#diskRail i').style.width = `${Math.round((body.disk.free / body.disk.total) * 100)}%`;
      }
      pump();
    } catch (error) { if (error.status === 401) showLogin(); }
  }
  function pump() {
    const limit = Number($('#concurrencySelect').value || 3);
    const now = Math.floor(Date.now() / 1000);
    const queued = state.jobs.filter(j => (j.status === 'queued' || (j.status === 'downloading' && Number(j.lease_until || 0) <= now)) && !state.processing.has(j.id));
    while (state.processing.size < limit && queued.length) processJob(queued.shift());
  }
  async function processJob(job) {
    state.processing.add(job.id);
    try { await api('process', { id: job.id }); }
    catch (error) { if (error.status === 401) showLogin(); else console.warn(error.message); }
    finally { state.processing.delete(job.id); await refresh(); }
  }
  async function handleAction(action, id) {
    if (action === 'delete') { state.deleteId = id; $('#deleteDialog').showModal(); return; }
    try { await api(action, { id }); await refresh(); }
    catch (error) { toast(error.message); }
  }
  const restoreLabels = { waiting_download: 'در انتظار دانلود', ready_to_scan: 'آماده بررسی', inspected: 'بررسی‌شده', restoring: 'در حال بازیابی', completed: 'تکمیل‌شده', failed: 'ناموفق' };
  async function refreshRestores() {
    try {
      const body = await api('restore_list'); state.restores = body.tasks;
      if (state.currentRestore) state.currentRestore = state.restores.find(t => t.id === state.currentRestore.id) || state.currentRestore;
      renderRestoreTasks();
    } catch (error) { if (error.status === 401) showLogin(); else console.warn(error.message); }
  }
  function renderRestoreTasks() {
    $('#restoreCount').textContent = `${fa.format(state.restores.length)} مورد`;
    $('#restoreTasks').innerHTML = state.restores.length ? state.restores.map(task => `<div class="restore-task">
      <div><strong>${escapeHtml(task.source_label)}</strong><small>${new Date(task.created_at * 1000).toLocaleString('fa-IR')}</small></div>
      <span class="status ${task.status === 'completed' ? 'completed' : task.status === 'restoring' ? 'downloading' : 'queued'}">${restoreLabels[task.status] || task.status}</span>
      <button type="button" data-restore-select="${task.id}">${task.manifest ? 'مشاهده' : 'ادامه'}</button>
    </div>`).join('') : '<div class="empty-state"><h3>هنوز عملیاتی ثبت نشده است</h3><p>یک Full Backup از مسیر محلی یا URL معرفی کنید.</p></div>';
  }
  function selectRestore(task) {
    state.currentRestore = task; $('#restorePlan').hidden = false;
    $('#restorePlanStatus').textContent = restoreLabels[task.status] || task.status;
    $('#inspectRestoreBtn').hidden = Boolean(task.manifest);
    if (task.manifest) renderManifest(task);
    $('#restorePlan').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function renderManifest(task) {
    const manifest = task.manifest; $('#restoreManifest').hidden = false; $('#restoreCredentials').hidden = false;
    $('#manifestFiles').textContent = `${fa.format(manifest.public_html.files)} فایل، ${bytes(manifest.public_html.bytes)}`;
    $('#manifestDatabases').textContent = `${fa.format(Object.keys(manifest.databases).length)} دیتابیس`;
    $('#manifestAccounts').textContent = `${fa.format(manifest.email_accounts_count || 0)} حساب`;
    $('#manifestMail').textContent = `${fa.format(manifest.email_data.files)} فایل، ${bytes(manifest.email_data.bytes)}`;
    $('#restoreManifest [value="databases"]').disabled = Object.keys(manifest.databases).length === 0;
    $('#restoreManifest [value="email_accounts"]').disabled = !manifest.email_accounts_count;
    $('#restoreManifest [value="email_data"]').disabled = manifest.email_data.files === 0;
    if (task.selected?.length) document.querySelectorAll('#restoreManifest input').forEach(input => { input.checked = task.selected.includes(input.value); });
    $('#databaseMappings').innerHTML = Object.keys(manifest.databases).map(name => `<div class="mapping-row" data-database="${escapeHtml(name)}"><strong>${escapeHtml(name)}.sql</strong><div class="field-grid">
      <label>میزبان<input data-field="host" dir="ltr" value="localhost"></label><label>درگاه<input data-field="port" type="number" value="3306"></label><label>دیتابیس مقصد<input data-field="database" dir="ltr" value="${escapeHtml(name)}"></label><label>کاربر<input data-field="user" dir="ltr"></label><label>رمز<input data-field="password" type="password" dir="ltr"></label>
    </div></div>`).join('');
    updateCredentialVisibility();
    $('#startRestoreBtn').textContent = task.status === 'restoring' ? 'ادامه بازیابی' : task.status === 'completed' ? 'بازیابی تکمیل شده' : 'شروع بازیابی انتخاب‌شده';
    $('#startRestoreBtn').disabled = task.status === 'completed';
    if (task.status === 'restoring' || task.status === 'completed') renderRestoreProgress(task);
  }
  function updateCredentialVisibility() {
    const selected = [...document.querySelectorAll('#restoreManifest input:checked')].map(el => el.value);
    $('#databaseMappings').hidden = !selected.includes('databases');
    $('#cpanelCredentials').hidden = !selected.includes('email_accounts');
  }
  function collectRestoreCredentials() {
    const databases = {};
    document.querySelectorAll('.mapping-row').forEach(row => {
      databases[row.dataset.database] = Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input => [input.dataset.field, input.value]));
    });
    return { databases, cpanel: { host: $('#cpanelHost').value.trim(), username: $('#cpanelUser').value.trim(), token: $('#cpanelToken').value } };
  }
  function renderRestoreProgress(task) {
    $('#restoreProgress').hidden = false;
    const selected = task.selected || [], done = selected.filter(key => task.progress?.[key]?.done).length;
    const progress = task.status === 'completed' ? 100 : selected.length ? Math.round(done / selected.length * 100) : 0;
    $('#restoreProgressBar').style.width = `${progress}%`; $('#restoreProgressText').textContent = task.status === 'completed' ? 'بازیابی تکمیل شد' : `${fa.format(progress)}٪ انجام شده`;
    $('#restoreLog').innerHTML = (task.log || []).map(item => `<p class="${item.type}">[${new Date(item.time * 1000).toLocaleTimeString('fa-IR')}] ${escapeHtml(item.message)}</p>`).join('');
  }
  async function runRestoreStep() {
    if (state.restoreBusy || !state.currentRestore || state.currentRestore.status !== 'restoring') return;
    state.restoreBusy = true;
    try {
      const credentials = collectRestoreCredentials();
      const body = await api('restore_step', { restore_id: state.currentRestore.id, ...credentials });
      state.currentRestore = body.task; renderRestoreProgress(body.task); await refreshRestores();
      if (body.task.status === 'restoring') setTimeout(runRestoreStep, 200);
      else toast('بازیابی بخش‌های انتخاب‌شده تکمیل شد.');
    } catch (error) { toast(error.message); $('#restoreProgressText').textContent = error.message; $('#startRestoreBtn').disabled = false; }
    finally { state.restoreBusy = false; }
  }
  function initApp() {
    if (state.initialized) {
      refresh(); refreshRestores();
      if (!state.timer) state.timer = setInterval(() => { refresh(); refreshRestores(); }, 1500);
      return;
    }
    state.initialized = true;
    refresh(); refreshRestores(); state.timer = setInterval(() => { refresh(); refreshRestores(); }, 1500);
    $('.app-tabs').addEventListener('click', event => {
      const button = event.target.closest('[data-view]'); if (!button) return;
      document.querySelectorAll('.app-tabs button').forEach(el => el.classList.toggle('active', el === button));
      document.querySelectorAll('.app-view').forEach(el => { el.hidden = el.id !== button.dataset.view; el.classList.toggle('active', el.id === button.dataset.view); });
    });
    $('#addForm').addEventListener('submit', async event => {
      event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button[type=submit]'); const errorEl = $('#formError');
      button.disabled = true; errorEl.textContent = '';
      try {
        const payload = { url: $('#urlInput').value.trim(), filename: $('#filenameInput').value.trim() || null, destination: $('#destinationInput').value.trim() || null };
        try {
          await api('add', payload);
        } catch (error) {
          if (error.details?.code !== 'file_exists') throw error;
          const choice = await chooseFileConflict(error.details.conflict?.filename || payload.filename || 'فایل مقصد');
          if (choice === 'cancel') return;
          await api('add', { ...payload, conflict_action: choice });
        }
        if (form && typeof form.reset === 'function') form.reset();
        setDestinationSelection();
        toast('دانلود به صف افزوده شد.'); await refresh();
      } catch (error) { errorEl.textContent = error.message; }
      finally { button.disabled = false; }
    });
    $('#jobsBody').addEventListener('click', event => {
      const toggle = event.target.closest('[data-expand]');
      if (toggle) {
        const id = toggle.dataset.expand;
        if (state.expanded.has(id)) state.expanded.delete(id); else state.expanded.add(id);
        render();
        return;
      }
      const button = event.target.closest('[data-action]');
      if (button) handleAction(button.dataset.action, button.dataset.id);
    });
    $('#jobsBody').addEventListener('change', async event => {
      const select = event.target.closest('[data-connections]');
      if (!select) return;
      select.disabled = true;
      try {
        const body = await api('set_connections', { id: select.dataset.connections, connections: Number(select.value) });
        state.jobs = state.jobs.map(job => job.id === body.job.id ? body.job : job);
        render();
        toast(`تعداد اتصال‌های هم‌زمان روی ${fa.format(body.job.connections || 1)} تنظیم شد.`);
      } catch (error) {
        toast(error.message);
        await refresh();
      }
    });
    $('#openDirectoryPicker').addEventListener('click', () => {
      const dialog = $('#directoryDialog');
      dialog.showModal();
      loadDirectories('');
    });
    $('#directoryList').addEventListener('click', event => {
      const directory = event.target.closest('[data-directory-path]');
      if (directory) loadDirectories(directory.dataset.directoryPath);
    });
    $('#directoryBack').addEventListener('click', event => loadDirectories(event.currentTarget.dataset.path || ''));
    $('#selectDirectory').addEventListener('click', () => {
      if (!state.directoryCurrent) return;
      setDestinationSelection(state.directoryCurrent.value, state.directoryCurrent.label);
      $('#directoryDialog').close('select');
    });
    $('#filters').addEventListener('click', event => { const button = event.target.closest('button'); if (!button) return; state.filter = button.dataset.filter; [...$('#filters').children].forEach(el => el.classList.toggle('active', el === button)); render(); });
    $('#searchInput').addEventListener('input', event => { state.search = event.target.value.trim().toLocaleLowerCase('fa'); render(); });
    $('#concurrencySelect').addEventListener('change', event => { localStorage.setItem('bargir-concurrency', event.target.value); pump(); });
    const saved = localStorage.getItem('bargir-concurrency'); if (saved && [...$('#concurrencySelect').options].some(o => o.value === saved)) $('#concurrencySelect').value = saved;
    $('#deleteDialog').addEventListener('close', async () => { if ($('#deleteDialog').returnValue !== 'confirm' || !state.deleteId) return; try { await api('delete', { id: state.deleteId }); toast('دانلود حذف شد.'); await refresh(); } catch (error) { toast(error.message); } finally { state.deleteId = null; } });
    $('#logoutBtn').addEventListener('click', async () => { await fetch('logout.php', { method: 'POST', headers: { 'X-CSRF-Token': state.csrf } }); location.reload(); });
    $('#restoreSourceForm').addEventListener('change', event => {
      if (event.target.name !== 'source_type') return; const type = event.target.value;
      $('#sourceLocal').hidden = type !== 'local'; $('#sourceDownload').hidden = type !== 'download'; $('#sourceUrl').hidden = type !== 'url';
      if (type === 'download') $('#restoreDownloadSelect').innerHTML = state.jobs.filter(j => j.status === 'completed' && /\.(tar|tar\.gz|tgz)$/i.test(j.filename)).map(j => `<option value="${j.id}">${escapeHtml(j.filename)}</option>`).join('') || '<option value="">فایل بکاپ تکمیل‌شده‌ای وجود ندارد</option>';
    });
    $('#restoreSourceForm').addEventListener('submit', async event => {
      event.preventDefault(); const type = event.currentTarget.source_type.value; const source = type === 'local' ? $('#restoreLocalPath').value.trim() : type === 'url' ? $('#restoreUrl').value.trim() : $('#restoreDownloadSelect').value; const button = event.currentTarget.querySelector('button[type=submit]');
      button.disabled = true; $('#restoreSourceError').textContent = '';
      try { const body = await api('restore_create', { source_type: type, source }); selectRestore(body.task); await refresh(); await refreshRestores(); toast(type === 'url' ? 'بکاپ به صف دانلود افزوده شد.' : 'منبع بکاپ ثبت شد.'); }
      catch (error) { $('#restoreSourceError').textContent = error.message; }
      finally { button.disabled = false; }
    });
    $('#restoreTasks').addEventListener('click', event => { const button = event.target.closest('[data-restore-select]'); if (button) selectRestore(state.restores.find(t => t.id === button.dataset.restoreSelect)); });
    $('#inspectRestoreBtn').addEventListener('click', async event => {
      if (!state.currentRestore) return; event.currentTarget.disabled = true; $('#restorePlanStatus').textContent = 'در حال بررسی آرشیو…';
      try { const body = await api('restore_inspect', { restore_id: state.currentRestore.id }); state.currentRestore = body.task; renderManifest(body.task); $('#restorePlanStatus').textContent = 'ساختار بکاپ شناسایی شد'; event.currentTarget.hidden = true; await refreshRestores(); }
      catch (error) { $('#restorePlanStatus').textContent = error.message; }
      finally { event.currentTarget.disabled = false; }
    });
    $('#restoreManifest').addEventListener('change', updateCredentialVisibility);
    $('#startRestoreBtn').addEventListener('click', async () => {
      if (!state.currentRestore || !$('#restoreConfirm').checked) { toast('تأیید بازنویسی و داشتن بکاپ فعلی الزامی است.'); return; }
      if (state.currentRestore.status === 'restoring') { $('#startRestoreBtn').disabled = true; runRestoreStep(); return; }
      const sections = [...document.querySelectorAll('#restoreManifest input:checked:not(:disabled)')].map(el => el.value);
      try { const body = await api('restore_start', { restore_id: state.currentRestore.id, sections }); state.currentRestore = body.task; renderRestoreProgress(body.task); $('#startRestoreBtn').disabled = true; runRestoreStep(); }
      catch (error) { toast(error.message); }
    });
  }
  const authForm = $('#authForm');
  if (authForm) authForm.addEventListener('submit', async event => {
    event.preventDefault(); const button = authForm.querySelector('button'); button.disabled = true; $('#authError').textContent = '';
    try {
      const action = window.BARGIR.isSetup ? 'login' : 'setup'; const body = await api(action, { password: $('#password').value }); state.csrf = body.csrf;
      if (action === 'setup') window.BARGIR.isSetup = true;
      await api('session');
      $('#authView').hidden = true; $('#appView').hidden = false; initApp();
    } catch (error) { $('#authError').textContent = error.message; }
    finally { button.disabled = false; }
  });
  if (document.body.dataset.authenticated === '1') initApp();
})();
