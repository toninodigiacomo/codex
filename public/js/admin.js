(function () {
  const toast = document.getElementById('toast');
  function showToast(msg, isError) {
    toast.textContent = msg;
    toast.hidden = false;
    toast.className = 'toast' + (isError ? ' error' : '');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => { toast.hidden = true; }, 4000);
  }

  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  async function api(method, url, body) {
    const res = await fetch(url, {
      method,
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const message = data.error || `${t('common.error')} ${res.status}`;
      throw new Error(data.detail ? `${message} : ${data.detail}` : message);
    }
    return data;
  }

  const currentUserId = Number(document.querySelector('meta[name="current-user-id"]').content);

  // ---------- tabs ----------
  // Always refetch on click rather than caching a render per tab — a
  // stale "Aucune bibliothèque configurée" on the invite form (because
  // Libraries were added after Utilisateurs was first opened) is worse
  // than the cost of one extra fetch.
  const tabs = document.querySelectorAll('.admin-tab');
  const panels = {
    users: document.getElementById('panel-users'),
    libraries: document.getElementById('panel-libraries'),
    objects: document.getElementById('panel-objects'),
    settings: document.getElementById('panel-settings'),
    maintenance: document.getElementById('panel-maintenance'),
    system: document.getElementById('panel-system'),
  };
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => t.classList.remove('active'));
      tab.classList.add('active');
      Object.entries(panels).forEach(([key, panel]) => { panel.hidden = key !== tab.dataset.tab; });
      const key = tab.dataset.tab;
      if (key !== 'libraries') stopJobPolling();
      if (key === 'users') renderUsersTab();
      if (key === 'libraries') renderLibrariesTab();
      if (key === 'objects') renderObjectsTab();
      if (key === 'settings') renderSettingsTab();
      if (key === 'maintenance') renderMaintenanceTab();
      if (key === 'system') renderSystemTab();
    });
  });

  // ============================================================
  // Users
  // ============================================================
  async function renderUsersTab() {
    const panel = panels.users;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const [users, libraries] = await Promise.all([api('GET', '/api/users'), api('GET', '/api/libraries')]);
      panel.innerHTML = `
        <div class="admin-card">
          <h2>${esc(t('admin.users_heading', { count: users.length }))}</h2>
          <div class="admin-list" id="userList"></div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('admin.invite_user'))}</h2>
          <form class="admin-form" id="inviteForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="invUsername">${esc(t('login.username'))}</label>
                <input class="input" id="invUsername" required minlength="3" />
              </div>
              <div class="field">
                <label for="invEmail">${esc(t('account.email_address'))}</label>
                <input class="input" id="invEmail" type="email" required />
              </div>
              <div class="field">
                <label for="invRole">${esc(t('admin.role'))}</label>
                <select class="input" id="invRole">
                  <option value="reader_basic" selected>${esc(t('admin.role_user'))}</option>
                  <option value="reader">${esc(t('admin.role_advanced_user'))}</option>
                  <option value="admin">${esc(t('admin.role_admin'))}</option>
                </select>
              </div>
            </div>
            <div class="field">
              <label>${esc(t('admin.accessible_libraries'))}</label>
              <div class="lib-checks" id="invLibChecks">
                ${
                  libraries.length
                    ? libraries.map((l) => `<label class="lib-check"><input type="checkbox" value="${l.id}" /> ${esc(l.name)}</label>`).join('')
                    : `<span class="text-muted" style="font-size:13px;">${esc(t('admin.no_library_configured'))}</span>`
                }
              </div>
            </div>
            <label class="mfa-force-toggle">
              <input type="checkbox" id="invMfaRequired" />
              ${esc(t('admin.require_mfa'))}
            </label>
            <div>
              <button type="submit" class="btn btn-primary">${esc(t('admin.send_invite'))}</button>
            </div>
            <div id="inviteResult"></div>
          </form>
        </div>
      `;
      renderUserList(users, libraries);

      document.getElementById('inviteForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const libraryIds = Array.from(document.querySelectorAll('#invLibChecks input:checked')).map((el) => Number(el.value));
        const resultBox = document.getElementById('inviteResult');
        try {
          const res = await api('POST', '/api/users', {
            username: document.getElementById('invUsername').value.trim(),
            email: document.getElementById('invEmail').value.trim(),
            role: document.getElementById('invRole').value,
            library_ids: libraryIds,
            mfa_required: document.getElementById('invMfaRequired').checked,
          });
          resultBox.innerHTML = `
            <div class="invite-link-box">
              <span class="status ${res.emailSent ? 'status-ok' : 'status-fail'}">
                ${res.emailSent ? '✓ ' + t('admin.email_sent') : '⚠ ' + t('admin.email_not_sent', { detail: res.emailError ? ' (' + esc(res.emailError) + ')' : '' })}
              </span>
              ${esc(res.inviteUrl)}
            </div>`;
          showToast(t('admin.user_invited'));
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  function openLibraryAccessEditor(user, libraries) {
    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.innerHTML = `
      <div class="dialog">
        <div class="dialog-title">${esc(t('admin.accessible_libraries'))} — ${esc(user.username)}</div>
        <div class="dialog-body">${esc(t('admin.access_editor_hint'))}</div>
        <div class="lib-checks">
          ${
            libraries.length
              ? libraries
                  .map(
                    (l) =>
                      `<label class="lib-check"><input type="checkbox" value="${l.id}" ${user.library_ids.includes(l.id) ? 'checked' : ''} /> ${esc(l.name)}</label>`
                  )
                  .join('')
              : `<span class="text-muted" style="font-size:13px;">${esc(t('admin.no_library_configured_short'))}</span>`
          }
        </div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="laCancel">${esc(t('common.cancel'))}</button>
          <button type="button" class="btn btn-primary" id="laSave">${esc(t('common.save'))}</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.remove(); });
    document.getElementById('laCancel').addEventListener('click', () => backdrop.remove());
    document.getElementById('laSave').addEventListener('click', async () => {
      const libraryIds = Array.from(backdrop.querySelectorAll('.lib-checks input:checked')).map((el) => Number(el.value));
      try {
        await api('PUT', `/api/users/${user.id}`, { library_ids: libraryIds });
        showToast(t('admin.access_updated'));
        backdrop.remove();
        renderUsersTab();
      } catch (err) {
        showToast(err.message, true);
      }
    });
  }

  function renderUserList(users, libraries) {
    const list = document.getElementById('userList');
    if (!users.length) {
      list.innerHTML = `<p class="text-muted">${esc(t('admin.no_user'))}</p>`;
      return;
    }
    const ROLE_LABELS = { admin: t('admin.role_admin_short'), reader: t('admin.role_advanced_user'), reader_basic: t('admin.role_user') };

    list.innerHTML = users
      .map((u) => {
        const libNames = u.library_ids.map((id) => libraries.find((l) => l.id === id)?.name).filter(Boolean);
        const isReaderTier = u.role !== 'admin';
        return `
      <div class="admin-row">
        <div class="admin-row-main">
          <strong>${esc(u.username)}</strong>
          <span>${esc(u.email || '')}</span>
        </div>
        <div class="admin-row-badges">
          <span class="badge ${u.role === 'admin' ? 'badge-admin' : 'badge-reader'}">${esc(ROLE_LABELS[u.role] || u.role)}</span>
          <span class="badge ${u.status === 'active' ? 'badge-active' : 'badge-invited'}">${u.status === 'active' ? esc(t('admin.status_active')) : esc(t('admin.status_invited'))}</span>
          ${u.status === 'active' ? `<span class="badge ${u.mfa_enabled ? 'badge-mfa' : 'badge-nomfa'}">${u.mfa_enabled ? esc(t('admin.mfa_enabled')) : esc(t('admin.mfa_disabled'))}</span>` : ''}
          ${u.mfa_required ? `<span class="badge badge-mfa">${esc(t('admin.mfa_required_badge'))}</span>` : ''}
          ${
            isReaderTier
              ? libNames.length
                ? `<span class="badge badge-reader">${esc(libNames.join(', '))}</span>`
                : `<span class="badge badge-noaccess">${esc(t('admin.no_access'))}</span>`
              : ''
          }
        </div>
        <div class="admin-row-actions">
          ${
            u.id !== currentUserId
              ? `<select class="input user-row-action" data-change-role="${u.id}" style="font-size:12.5px;">
                  ${Object.entries(ROLE_LABELS).map(([val, label]) => `<option value="${val}" ${u.role === val ? 'selected' : ''}>${esc(label)}</option>`).join('')}
                </select>`
              : ''
          }
          ${isReaderTier ? `<button class="btn btn-secondary btn-sm user-row-action" data-edit-access="${u.id}">${esc(t('admin.libraries_ellipsis'))}</button>` : ''}
          <button class="btn btn-secondary btn-sm user-row-action" data-toggle-mfa="${u.id}" data-current="${u.mfa_required ? '1' : '0'}">${u.mfa_required ? esc(t('admin.lift_mfa_requirement')) : esc(t('admin.require_mfa_short'))}</button>
          ${u.status === 'invited' ? `<button class="btn btn-secondary btn-sm user-row-action" data-resend="${u.id}">${esc(t('admin.resend'))}</button>` : ''}
          ${u.id !== currentUserId ? `<button class="btn btn-danger btn-sm user-row-action" data-delete-user="${u.id}">${esc(t('common.delete'))}</button>` : ''}
        </div>
      </div>`;
      })
      .join('');

    list.querySelectorAll('[data-change-role]').forEach((sel) => {
      sel.addEventListener('change', async () => {
        try {
          await api('PUT', `/api/users/${sel.dataset.changeRole}`, { role: sel.value });
          showToast(t('admin.role_updated'));
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
          renderUsersTab();
        }
      });
    });

    list.querySelectorAll('[data-edit-access]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const user = users.find((u) => u.id === Number(btn.dataset.editAccess));
        openLibraryAccessEditor(user, libraries);
      });
    });

    list.querySelectorAll('[data-toggle-mfa]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const nextValue = btn.dataset.current !== '1';
        try {
          await api('PUT', `/api/users/${btn.dataset.toggleMfa}`, { mfa_required: nextValue });
          showToast(nextValue ? t('admin.mfa_now_required') : t('admin.mfa_requirement_lifted'));
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });

    list.querySelectorAll('[data-resend]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          const res = await api('POST', `/api/invites/${btn.dataset.resend}/resend`);
          alert((res.emailSent ? t('admin.email_resent') : t('admin.email_not_sent_short', { error: res.emailError || t('admin.unknown') })) + '\n' + res.inviteUrl);
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
    list.querySelectorAll('[data-delete-user]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm(t('admin.confirm_delete_user'))) return;
        try {
          await api('DELETE', `/api/users/${btn.dataset.deleteUser}`);
          showToast(t('admin.user_deleted'));
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
  }

  // ============================================================
  // Libraries
  // ============================================================
  function openFolderPicker(startPath, onChoose) {
    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.innerHTML = `
      <div class="dialog folder-picker">
        <div class="dialog-title">${esc(t('admin.choose_folder'))}</div>
        <div class="folder-picker-path" id="fpPath"></div>
        <div class="folder-picker-list" id="fpList"><p class="text-muted">${esc(t('common.loading'))}</p></div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="fpCancel">${esc(t('common.cancel'))}</button>
          <button type="button" class="btn btn-primary" id="fpChoose">${esc(t('admin.choose_this_folder'))}</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);

    let current = startPath || '';

    async function load(path) {
      const pathEl = document.getElementById('fpPath');
      const listEl = document.getElementById('fpList');
      listEl.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
      try {
        const res = await api('GET', `/api/browse-libraries?path=${encodeURIComponent(path)}`);
        current = res.path;
        pathEl.textContent = 'libraries/' + (res.path || '');
        const rows = [];
        if (res.parent !== null) {
          rows.push(`<button type="button" class="folder-picker-item" data-nav="${esc(res.parent)}">.. (${esc(t('admin.parent_folder'))})</button>`);
        }
        res.entries.forEach((entry) => {
          rows.push(`<button type="button" class="folder-picker-item" data-nav="${esc(entry.path)}">📁 ${esc(entry.name)}</button>`);
        });
        listEl.innerHTML = rows.length ? rows.join('') : `<p class="text-muted">${esc(t('admin.no_subfolder'))}</p>`;
        listEl.querySelectorAll('[data-nav]').forEach((btn) => {
          btn.addEventListener('click', () => load(btn.dataset.nav));
        });
      } catch (err) {
        listEl.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
      }
    }

    document.getElementById('fpCancel').addEventListener('click', () => backdrop.remove());
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.remove(); });
    document.getElementById('fpChoose').addEventListener('click', () => {
      onChoose(current);
      backdrop.remove();
    });

    load(startPath || '');
  }

  const TYPE_LABEL_KEYS = { comic: 'type.comic_short', ebook: 'type.ebook_short', magazine: 'type.magazine_short', other: 'type.other_short' };

  function typeOptionsHtml(selected) {
    return Object.entries(TYPE_LABEL_KEYS)
      .map(([value, labelKey]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${esc(t(labelKey))}</option>`)
      .join('');
  }

  function formatSyncDate(iso) {
    if (!iso) return t('admin.never_synced');
    const d = new Date(iso);
    const locale = document.documentElement.lang || 'fr';
    return t('admin.synced_on', { date: d.toLocaleDateString(locale) + ' ' + t('account.at_time') + ' ' + d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }) });
  }

  function formatCount(n) {
    const locale = document.documentElement.lang || 'fr';
    return new Intl.NumberFormat(locale).format(n || 0);
  }

  function formatBytes(bytes) {
    if (!bytes) return '0 ' + t('admin.unit_kb');
    const units = [t('admin.unit_b'), t('admin.unit_kb'), t('admin.unit_mb'), t('admin.unit_gb')];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  /** Patches a library row's header line in place (path/date/count) without re-rendering the whole list, so the sync-result box just below it isn't wiped out. */
  async function refreshLibraryMeta(libId) {
    try {
      const libs = await api('GET', '/api/libraries');
      const lib = libs.find((l) => String(l.id) === String(libId));
      const metaEl = document.getElementById(`lib-meta-${libId}`);
      if (lib && metaEl) {
        metaEl.textContent = `libraries/${lib.path} — ${formatSyncDate(lib.last_synced_at)} — ${t('admin.item_count', { count: formatCount(lib.item_count) })}`;
      }
    } catch (_) {
      // best-effort — the sync result box just below already shows what happened either way
    }
  }

  /**
   * Shared with the per-row button and the "Tout" button below it.
   *
   * limit=5, not 25: a magazine PDF's cover needs a full page render
   * (pdftoppm), 7-13s each — a batch of 25 of those can take 4+ minutes,
   * comfortably past PHP's own max_execution_time (300s, docker/uploads.ini)
   * but also, on a setup fronted by a reverse proxy (nginx/openresty and
   * their ilk default to a 60s upstream read timeout), the proxy gives up
   * and returns a 502 long before either PHP or the batch itself actually
   * fails — the request that logged as still legitimately "en cours" in
   * data/logs/access.log turned out to finish fine server-side, just too
   * late for the browser to ever see the response. Smaller batches, more
   * requests, but each one safely under any such external timeout.
   */
  /** $onProgress(done, total), when given, is also called on every batch — how the "Tout ..." buttons keep their own summary line live instead of a static library name that never changes until the whole library finishes. */
  async function extractMissingForLibrary(libId, box, onProgress = null) {
    let totalProcessed = 0;
    jobStarted();
    try {
      while (true) {
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.extracting', { count: totalProcessed }))}</p>`;
        if (onProgress) onProgress(totalProcessed, null);
        const res = await api('POST', `/api/libraries/${libId}/extract-missing?limit=5`);
        totalProcessed += res.processed;
        if (res.processed === 0 || res.remaining === 0) break;
      }
      box.innerHTML = `<div class="invite-link-box">${esc(t('admin.extraction_done', { count: totalProcessed }))}</div>`;
      return totalProcessed;
    } finally {
      jobEnded();
    }
  }

  /** Shared with the per-row button and the "Tout" button below it — see extractMissingForLibrary's note on the batch size and on $onProgress. $startOffset resumes a job the server still shows as "running" (schema.sql's library_jobs) instead of starting over at 0. */
  async function regenerateCoversForLibrary(libId, box, startOffset = 0, onProgress = null) {
    let offset = startOffset;
    let total = null;
    jobStarted();
    try {
      while (total === null || offset < total) {
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.regenerating', { done: offset, total: total !== null ? ' / ' + total : '' }))}</p>`;
        if (onProgress) onProgress(offset, total);
        const res = await api('POST', `/api/libraries/${libId}/regenerate-covers?limit=5&offset=${offset}`);
        total = res.total;
        offset = res.offset;
        if (res.processed === 0) break; // safety net against an infinite loop if total is somehow never reached
      }
      box.innerHTML = `<div class="invite-link-box">${esc(t('admin.regeneration_done', { count: offset }))}</div>`;
      return offset;
    } finally {
      jobEnded();
    }
  }

  /**
   * Shared with the per-row button and syncAllBtn. Batched (5 new files per
   * call — see extractMissingForLibrary's note on why not more, the same
   * per-file cover-extraction cost applies to a newly-found file during
   * sync too) for the same two reasons as the other two "Tout ..." helpers:
   * live progress, and staying safely under a reverse proxy's own upstream
   * read timeout regardless of how slow any one file is to process.
   * sync-all and the cron sync token still call LibraryScanner::sync()
   * unbatched server-side — fine for a cron job with no browser/proxy
   * round-trip waiting on it, but that's exactly what syncAllBtn used to do
   * too, and why it doesn't anymore. $libPath is passed in explicitly
   * (rather than looked up here) since both callers already have it and
   * this function has no library list of its own to search.
   */
  async function syncLibrary(libId, box, libPath, onProgress = null) {
    const metaEl = document.getElementById(`lib-meta-${libId}`);
    let totalAdded = 0;
    let totalUpdated = 0;
    let allConflicted = [];
    let lastRes = null;
    jobStarted();
    try {
      while (true) {
        const res = await api('POST', `/api/libraries/${libId}/sync?limit=5`);
        totalAdded += res.added;
        totalUpdated += res.updated || 0;
        allConflicted = allConflicted.concat(res.conflicted || []);
        lastRes = res;
        const done = res.added + (res.updated || 0) + res.unchanged;
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.syncing', { done, total: res.total }))}</p>`;
        if (onProgress) onProgress(done, res.total);
        if (metaEl && libPath) {
          metaEl.textContent = `libraries/${libPath} — ${t('admin.sync_in_progress_meta', { done, total: res.total })}`;
        }
        if (res.added === 0 && (res.updated || 0) === 0) break;
      }
      renderSyncResult(box, { ...lastRes, added: totalAdded, updated: totalUpdated, conflicted: allConflicted });
      refreshLibraryMeta(libId);
      return totalAdded + totalUpdated;
    } finally {
      jobEnded();
    }
  }

  /** Incremented/decremented around every batch loop below — drives the tab-switch guard and the beforeunload warning, so a stray click (or an actual page reload) doesn't quietly sever a loop that's mid-batch. */
  let activeJobCount = 0;

  function jobStarted() {
    activeJobCount++;
    updateJobGuards();
  }

  function jobEnded() {
    activeJobCount = Math.max(0, activeJobCount - 1);
    updateJobGuards();
  }

  function updateJobGuards() {
    const running = activeJobCount > 0;
    tabs.forEach((tb) => { if (!tb.classList.contains('active')) tb.disabled = running; });
    window.onbeforeunload = running ? () => t('admin.leave_page_warning') : null;
  }

  /**
   * Polls the persisted job status (schema.sql's library_jobs) every 2s
   * while the Bibliothèques tab is the one showing — not gated on
   * activeJobCount, since a job might be running from a different tab or
   * session too, and this is what actually surfaces the item currently
   * mid-processing (working() writes it well before a whole batch finishes
   * and the loop's own inline updates would otherwise show it).
   */
  let jobPollTimer = null;

  function startJobPolling() {
    if (jobPollTimer) return;
    jobPollTimer = setInterval(async () => {
      try {
        const jobs = await api('GET', '/api/library-jobs');
        Object.keys(jobs).forEach((libId) => renderJobStatus(libId, jobs[libId]));
      } catch (_) {
        // best-effort — the last known status just stays on screen until the next poll succeeds
      }
    }, 2000);
  }

  function stopJobPolling() {
    if (jobPollTimer) {
      clearInterval(jobPollTimer);
      jobPollTimer = null;
    }
  }

  /** Renders whatever the server last recorded for a library's job (schema.sql's library_jobs) into its result box — the state that survives a page reload or coming back later, since the actual loop only advances while a tab is here driving it. */
  function renderJobStatus(libId, job) {
    const box = document.getElementById(`sync-result-${libId}`);
    if (!box || !job) return;
    if (box.dataset.protected === '1') return; // an unresolved orphan-files list is showing — see renderSyncResult
    const typeLabelKeys = { sync: 'admin.job_sync', 'extract-missing': 'admin.job_extract', 'regenerate-covers': 'admin.job_regenerate' };
    const label = typeLabelKeys[job.job_type] ? t(typeLabelKeys[job.job_type]) : job.job_type;
    const progress = job.total ? `${job.done} / ${job.total}` : `${job.done}`;
    const ago = (Date.now() - new Date(job.updated_at).getTime()) / 1000;
    if (job.status === 'error') {
      box.innerHTML = `<div class="invite-link-box" style="border-color:var(--color-danger);">${esc(t('admin.job_failed_at', { label, progress, message: job.message || '' }))}</div>`;
    } else if (job.status === 'done') {
      box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.job_finished', { label, progress, date: formatSyncDate(job.updated_at) }))}</p>`;
    } else if (ago < 20) {
      // Recently touched enough that a tab somewhere is plausibly still driving
      // it — shown as read-only progress rather than a Reprendre button, since
      // clicking one while a live loop is also running would race it.
      const current = job.current_item ? ' — ' + t('admin.job_current_item', { item: esc(job.current_item) }) : '';
      box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.job_in_progress', { label, progress }))}${current}</p>`;
    } else {
      box.innerHTML = `
        <div class="invite-link-box">
          ${esc(t('admin.job_paused', { label, progress }))}
          <button type="button" class="btn btn-secondary btn-sm" data-resume-job="${libId}" data-resume-type="${esc(job.job_type)}" data-resume-done="${job.done}" style="margin-left:8px;">${esc(t('admin.resume'))}</button>
        </div>`;
      box.querySelector('[data-resume-job]').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
          if (job.job_type === 'regenerate-covers') {
            await regenerateCoversForLibrary(libId, box, job.done);
          } else if (job.job_type === 'extract-missing') {
            await extractMissingForLibrary(libId, box);
          } else {
            await syncLibrary(libId, box, undefined);
          }
        } catch (err) {
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });
    }
  }

  async function renderLibrariesTab() {
    const panel = panels.libraries;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const [libraries, jobs] = await Promise.all([api('GET', '/api/libraries'), api('GET', '/api/library-jobs')]);
      panel.innerHTML = `
        <div class="admin-card">
          <div class="admin-card-head">
            <h2>${esc(t('admin.libraries_heading', { count: libraries.length }))}</h2>
            <div class="admin-row-actions-group">
              <button class="btn btn-secondary btn-sm" id="syncAllBtn" ${libraries.length ? '' : 'disabled'}>${esc(t('admin.sync_all'))}</button>
              <button class="btn btn-secondary btn-sm" id="extractAllBtn" ${libraries.length ? '' : 'disabled'} title="${esc(t('admin.extract_all_title'))}">${esc(t('admin.extract_all'))}</button>
              <button class="btn btn-secondary btn-sm" id="regenerateAllBtn" ${libraries.length ? '' : 'disabled'} title="${esc(t('admin.regenerate_all_title'))}">${esc(t('admin.regenerate_all'))}</button>
            </div>
          </div>
          <div class="admin-list" id="libList"></div>
          <div id="syncAllResult"></div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('admin.orphaned_items'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('admin.orphaned_items_hint')}
          </p>
          <button type="button" class="btn btn-secondary btn-sm" id="previewOrphanedItemsBtn">${esc(t('admin.search_orphaned'))}</button>
          <div id="orphanedItemsResult" style="margin-top:10px;"></div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('admin.add_library'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">${t('admin.add_library_hint')}</p>
          <form class="admin-form" id="libForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="libName">${esc(t('admin.name'))}</label>
                <input class="input" id="libName" required placeholder="BD Franco-Belge" />
              </div>
              <div class="field">
                <label for="libPath">${esc(t('admin.relative_path'))}</label>
                <div class="path-field">
                  <input class="input" id="libPath" required placeholder="bd-franco-belge" />
                  <button type="button" class="btn btn-secondary btn-sm" id="libBrowseBtn">${esc(t('admin.browse'))}</button>
                </div>
              </div>
              <div class="field">
                <label for="libType">${esc(t('admin.content_type'))}</label>
                <select class="input" id="libType">${typeOptionsHtml('comic')}</select>
              </div>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">${esc(t('admin.add'))}</button>
            </div>
          </form>
        </div>
      `;
      renderLibList(libraries, jobs);
      startJobPolling();

      document.getElementById('libBrowseBtn').addEventListener('click', () => {
        openFolderPicker(document.getElementById('libPath').value.trim(), (chosen) => {
          document.getElementById('libPath').value = chosen;
        });
      });

      document.getElementById('syncAllBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('syncAllResult');
        btn.disabled = true;
        let grandTotal = 0;
        const failures = [];
        try {
          for (const lib of libraries) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.syncing_library', { name: lib.name }))}</p>`;
            const libBox = document.getElementById(`sync-result-${lib.id}`) || document.createElement('div');
            try {
              grandTotal += await syncLibrary(lib.id, libBox, lib.path, (done, total) => {
                box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.syncing_library_progress', { name: lib.name, done, total: total ? ' / ' + total : '' }))}</p>`;
              });
            } catch (err) {
              // Same as extractAllBtn/regenerateAllBtn — one library's failure
              // shouldn't stop the rest of the list from being attempted.
              failures.push(`${lib.name} (${err.message})`);
            }
          }
          box.innerHTML = `<div class="invite-link-box">${esc(t('admin.sync_all_total', { count: grandTotal }))}${failures.length ? ' ' + esc(t('admin.failures_on', { list: failures.join(', ') })) : ' ' + esc(t('admin.done'))}</div>`;
          showToast(failures.length ? t('admin.sync_done_with_failures') : t('admin.sync_done'));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });

      document.getElementById('extractAllBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('syncAllResult');
        btn.disabled = true;
        let grandTotal = 0;
        const failures = [];
        try {
          for (const lib of libraries) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.extracting_library', { name: lib.name }))}</p>`;
            const libBox = document.getElementById(`sync-result-${lib.id}`) || document.createElement('div');
            try {
              grandTotal += await extractMissingForLibrary(lib.id, libBox, (done) => {
                box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.extracting_library_progress', { name: lib.name, count: done }))}</p>`;
              });
            } catch (err) {
              // One library failing (a crashed batch, a transient error) must not
              // stop every library after it in the list from being processed too.
              failures.push(`${lib.name} (${err.message})`);
            }
          }
          box.innerHTML = `<div class="invite-link-box">${esc(t('admin.extract_all_total', { count: grandTotal }))}${failures.length ? ' ' + esc(t('admin.failures_on', { list: failures.join(', ') })) : ' ' + esc(t('admin.done'))}</div>`;
          showToast(failures.length ? t('admin.extract_done_with_failures') : t('admin.extract_done'));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });

      document.getElementById('regenerateAllBtn').addEventListener('click', async (e) => {
        if (!confirm(t('admin.confirm_regenerate_all'))) return;
        const btn = e.currentTarget;
        const box = document.getElementById('syncAllResult');
        btn.disabled = true;
        let grandTotal = 0;
        const failures = [];
        try {
          for (const lib of libraries) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.regenerating_library', { name: lib.name }))}</p>`;
            const libBox = document.getElementById(`sync-result-${lib.id}`) || document.createElement('div');
            try {
              grandTotal += await regenerateCoversForLibrary(lib.id, libBox, 0, (done, total) => {
                box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.regenerating_library_progress', { name: lib.name, done, total: total ? ' / ' + total : '' }))}</p>`;
              });
            } catch (err) {
              // Same as extractAllBtn above — one library's failure shouldn't stop
              // the rest of the list from being attempted.
              failures.push(`${lib.name} (${err.message})`);
            }
          }
          box.innerHTML = `<div class="invite-link-box">${esc(t('admin.regenerate_all_total', { count: grandTotal }))}${failures.length ? ' ' + esc(t('admin.failures_on', { list: failures.join(', ') })) : ' ' + esc(t('admin.done'))}</div>`;
          showToast(failures.length ? t('admin.regenerate_done_with_failures') : t('admin.regenerate_done'));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });

      document.getElementById('libForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('POST', '/api/libraries', {
            name: document.getElementById('libName').value.trim(),
            path: document.getElementById('libPath').value.trim(),
            type: document.getElementById('libType').value,
          });
          showToast(t('admin.library_added'));
          renderLibrariesTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('previewOrphanedItemsBtn').addEventListener('click', async () => {
        const box = document.getElementById('orphanedItemsResult');
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.searching'))}</p>`;
        try {
          const res = await api('GET', '/api/orphaned-items');
          if (!res.matches.length) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.no_orphaned_item'))}</p>`;
            return;
          }
          box.innerHTML = `
            <p style="font-size:13px;">${esc(t('admin.orphaned_items_found', { count: res.matches.length }))}</p>
            <ul style="font-size:12.5px;color:var(--color-text);opacity:0.8;max-height:160px;overflow-y:auto;margin:8px 0;padding-left:18px;">
              ${res.matches.map((m) => `<li>${esc(m.title)} <span class="text-muted">(${esc(m.path)})</span></li>`).join('')}
            </ul>
            <button type="button" class="btn btn-danger btn-sm" id="confirmOrphanedItemsBtn">${esc(t('admin.delete_n_items', { count: res.matches.length }))}</button>
          `;
          document.getElementById('confirmOrphanedItemsBtn').addEventListener('click', async () => {
            if (!confirm(t('admin.confirm_delete_orphaned', { count: res.matches.length }))) return;
            try {
              const delRes = await api('POST', '/api/orphaned-items');
              box.innerHTML = `<div class="invite-link-box">${esc(t('admin.items_deleted', { count: delRes.deleted }))}</div>`;
              showToast(t('admin.cleanup_done'));
            } catch (err) {
              showToast(err.message, true);
            }
          });
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  function renderLibList(libraries, jobs) {
    const list = document.getElementById('libList');
    if (!libraries.length) {
      list.innerHTML = `<p class="text-muted">${esc(t('admin.no_library'))}</p>`;
      return;
    }
    list.innerHTML = libraries
      .map(
        (l) => `
      <div class="admin-row" id="lib-row-${l.id}">
        <div class="admin-row-main">
          <strong>${esc(l.name)}</strong>
          <span id="lib-meta-${l.id}">libraries/${esc(l.path)} — ${formatSyncDate(l.last_synced_at)} — ${t('admin.item_count', { count: formatCount(l.item_count) })}</span>
        </div>
        <div class="admin-row-badges">
          <span class="badge badge-reader">${TYPE_LABEL_KEYS[l.type] ? esc(t(TYPE_LABEL_KEYS[l.type])) : esc(l.type)}</span>
        </div>
        <div class="admin-row-actions">
          <div class="admin-row-actions-group">
            <button class="btn btn-secondary btn-sm" data-sync-lib="${l.id}">${esc(t('admin.sync'))}</button>
            <button class="btn btn-secondary btn-sm" data-extract-missing="${l.id}" title="${esc(t('admin.extract_missing_title'))}">${esc(t('admin.missing_metadata'))}</button>
            <button class="btn btn-secondary btn-sm" data-regenerate-covers="${l.id}" title="${esc(t('admin.regenerate_covers_title'))}">${esc(t('admin.regenerate_covers'))}</button>
          </div>
          <div class="admin-row-actions-group">
            <button class="btn btn-secondary btn-sm" data-edit-lib="${l.id}">${esc(t('common.edit'))}</button>
            <button class="btn btn-danger btn-sm" data-delete-lib="${l.id}">${esc(t('common.delete'))}</button>
          </div>
        </div>
        <div class="sync-result" id="sync-result-${l.id}"></div>
      </div>`
      )
      .join('');

    libraries.forEach((l) => renderJobStatus(l.id, jobs[l.id]));

    list.querySelectorAll('[data-sync-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const libId = btn.dataset.syncLib;
        const box = document.getElementById(`sync-result-${libId}`);
        const lib = libraries.find((l) => String(l.id) === String(libId));
        btn.disabled = true;
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.sync_in_progress'))}</p>`;
        try {
          const totalAdded = await syncLibrary(libId, box, lib ? lib.path : undefined);
          showToast(t('admin.sync_finished', { count: totalAdded }));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });
    });

    list.querySelectorAll('[data-extract-missing]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const libId = btn.dataset.extractMissing;
        const box = document.getElementById(`sync-result-${libId}`);
        btn.disabled = true;
        try {
          const total = await extractMissingForLibrary(libId, box);
          showToast(t('admin.extraction_finished', { count: total }));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });
    });

    list.querySelectorAll('[data-regenerate-covers]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const libId = btn.dataset.regenerateCovers;
        const box = document.getElementById(`sync-result-${libId}`);
        if (!confirm(t('admin.confirm_regenerate_one'))) return;
        btn.disabled = true;
        try {
          const total = await regenerateCoversForLibrary(libId, box);
          showToast(t('admin.regeneration_finished', { count: total }));
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });
    });

    list.querySelectorAll('[data-edit-lib]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const lib = libraries.find((l) => l.id === Number(btn.dataset.editLib));
        const row = document.getElementById(`lib-row-${lib.id}`);
        row.outerHTML = `
          <div class="admin-row admin-row-edit" id="lib-row-${lib.id}">
            <form class="admin-form-row edit-lib-form" data-save-lib="${lib.id}" style="flex:1;align-items:flex-end;">
              <div class="field">
                <label>${esc(t('admin.name'))}</label>
                <input class="input" id="editLibName-${lib.id}" value="${esc(lib.name)}" required />
              </div>
              <div class="field">
                <label>${esc(t('admin.relative_path'))}</label>
                <div class="path-field">
                  <input class="input" id="editLibPath-${lib.id}" value="${esc(lib.path)}" required />
                  <button type="button" class="btn btn-secondary btn-sm" id="editLibBrowse-${lib.id}">${esc(t('admin.browse'))}</button>
                </div>
              </div>
              <div class="field">
                <label>${esc(t('admin.content_type'))}</label>
                <select class="input" id="editLibType-${lib.id}">${typeOptionsHtml(lib.type)}</select>
              </div>
              <div style="display:flex;gap:6px;">
                <button type="submit" class="btn btn-primary btn-sm">${esc(t('common.save'))}</button>
                <button type="button" class="btn btn-secondary btn-sm" data-cancel-edit="${lib.id}">${esc(t('common.cancel'))}</button>
              </div>
            </form>
          </div>`;

        document.getElementById(`editLibBrowse-${lib.id}`).addEventListener('click', () => {
          const pathInput = document.getElementById(`editLibPath-${lib.id}`);
          openFolderPicker(pathInput.value.trim(), (chosen) => { pathInput.value = chosen; });
        });
        document.querySelector(`[data-cancel-edit="${lib.id}"]`).addEventListener('click', () => {
          renderLibrariesTab();
        });
        document.querySelector(`[data-save-lib="${lib.id}"]`).addEventListener('submit', async (e) => {
          e.preventDefault();
          try {
            await api('PUT', `/api/libraries/${lib.id}`, {
              name: document.getElementById(`editLibName-${lib.id}`).value.trim(),
              path: document.getElementById(`editLibPath-${lib.id}`).value.trim(),
              type: document.getElementById(`editLibType-${lib.id}`).value,
            });
            showToast(t('admin.library_updated'));
            renderLibrariesTab();
          } catch (err) {
            showToast(err.message, true);
          }
        });
      });
    });
    list.querySelectorAll('[data-delete-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm(t('admin.confirm_delete_library'))) return;
        try {
          await api('DELETE', `/api/libraries/${btn.dataset.deleteLib}`);
          showToast(t('admin.library_deleted'));
          renderLibrariesTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
  }

  function renderSyncResult(box, res) {
    // Marks this box as holding an unresolved, actionable result (orphan
    // files with checkboxes to select for deletion) — startJobPolling()
    // below checks this before overwriting anything, since the sync job
    // itself is already "done" server-side by the time this renders, and
    // the very next poll tick (every 2s) would otherwise wipe this out
    // from under whoever's about to check a box.
    box.dataset.protected = res.orphaned.length ? '1' : '';
    const orphanRows = res.orphaned
      .map(
        (o) => `<label class="orphan-row">
          <input type="checkbox" class="orphan-check" value="${o.id}" />
          <span>${esc(o.title)} <span class="text-muted">(${esc(o.path)})</span></span>
        </label>`
      )
      .join('');
    const conflicted = res.conflicted || [];
    const conflictRows = conflicted.map((p) => `<div class="orphan-row"><span>${esc(p)}</span></div>`).join('');
    box.innerHTML = `
      <div class="invite-link-box">
        ${t('admin.sync_result_summary', { added: res.added, updated: res.updated || 0, unchanged: res.unchanged, orphaned: res.orphaned.length ? ', ' + t('admin.files_not_found', { count: res.orphaned.length }) : '.' })}
        ${
          res.orphaned.length
            ? `<label class="orphan-row orphan-row-header">
                <input type="checkbox" id="orphanSelectAll-${box.id}" />
                <span>${esc(t('admin.select_all'))}</span>
              </label>
              ${orphanRows}
              <button type="button" class="btn btn-danger btn-sm" id="orphanBulkDelete-${box.id}" disabled>${esc(t('common.delete'))}</button>`
            : ''
        }
      </div>
      ${
        conflicted.length
          ? `<div class="invite-link-box" style="border-color:var(--color-danger);">
              ${esc(t('admin.files_skipped_duplicate', { count: conflicted.length }))}
              ${conflictRows}
            </div>`
          : ''
      }`;

    if (!res.orphaned.length) return;

    const checks = () => Array.from(box.querySelectorAll('.orphan-check'));
    const selectAll = document.getElementById(`orphanSelectAll-${box.id}`);
    const bulkBtn = document.getElementById(`orphanBulkDelete-${box.id}`);

    function syncBulkBtn() {
      const selected = checks().filter((c) => c.checked);
      bulkBtn.disabled = selected.length === 0;
      bulkBtn.textContent = selected.length ? t('admin.delete_n_items', { count: selected.length }) : t('common.delete');
      selectAll.checked = selected.length > 0 && selected.length === checks().length;
      selectAll.indeterminate = selected.length > 0 && selected.length < checks().length;
    }
    selectAll.addEventListener('change', () => {
      checks().forEach((c) => { c.checked = selectAll.checked; });
      syncBulkBtn();
    });
    box.addEventListener('change', (e) => {
      if (e.target.classList.contains('orphan-check')) syncBulkBtn();
    });
    bulkBtn.addEventListener('click', async () => {
      const selected = checks().filter((c) => c.checked);
      if (!selected.length || !confirm(t('admin.confirm_delete_orphaned', { count: selected.length }))) return;
      bulkBtn.disabled = true;
      let deleted = 0;
      for (const c of selected) {
        try {
          await api('DELETE', `/api/items/${c.value}`);
          c.closest('.orphan-row').remove();
          deleted++;
        } catch (err) {
          showToast(err.message, true);
        }
      }
      showToast(t('admin.items_deleted', { count: deleted }));
      if (!box.querySelector('.orphan-check')) {
        box.dataset.protected = '';
      } else {
        syncBulkBtn();
      }
    });
  }

  // ============================================================
  // Objects — find one item and act on it directly (sync it, force a
  // metadata re-read, regenerate its cover), rather than waiting for a
  // whole-library pass to get to it. GET /api/admin-items is a separate,
  // admin-only route from the reader-facing GET /api/items list — see
  // that route's own comment in api/index.php for why.
  // ============================================================
  const objState = { library_id: null, q: '', page: 1, type: null, path: null };
  const OBJ_PAGE_SIZE = 40;

  async function renderObjectsTab() {
    const panel = panels.objects;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const libraries = await api('GET', '/api/libraries');
      panel.innerHTML = `
        <div class="admin-card">
          <h2>${esc(t('admin.tab_objects'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">${esc(t('admin.objects_hint'))}</p>
          <div class="admin-form-row">
            <div class="field">
              <label for="objLibraryFilter">${esc(t('admin.accessible_libraries'))}</label>
              <select class="input" id="objLibraryFilter">
                <option value="">${esc(t('admin.all_libraries'))}</option>
                ${libraries.map((l) => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}
              </select>
            </div>
            <div class="field" style="flex:2;">
              <label for="objSearchInput">${esc(t('nav.search_placeholder'))}</label>
              <input class="input" id="objSearchInput" placeholder="${esc(t('nav.search_placeholder'))}" />
            </div>
          </div>
          <div id="objectsTreeWrap">
            <div id="objectsTree"></div>
          </div>
          <div id="objectsFlatWrap" hidden>
            <button type="button" class="btn btn-ghost btn-sm" id="objBackToTreeBtn">${esc(t('admin.back_to_tree'))}</button>
            <p class="text-muted" id="objResultCount" style="font-size:13px;"></p>
            <div id="objectsList"></div>
            <div class="pagination" id="objectsPagination" hidden></div>
          </div>
        </div>
      `;

      document.getElementById('objLibraryFilter').addEventListener('change', (e) => {
        objState.library_id = e.target.value || null;
        renderObjectsTree(libraries);
      });
      let objSearchTimer = null;
      document.getElementById('objSearchInput').addEventListener('input', (e) => {
        clearTimeout(objSearchTimer);
        objSearchTimer = setTimeout(() => {
          objState.q = e.target.value.trim();
          objState.page = 1;
          objState.path = null;
          if (objState.q) {
            showObjectsFlatView();
            loadObjectsList();
          } else {
            showObjectsTreeView();
          }
        }, 300);
      });
      document.getElementById('objBackToTreeBtn').addEventListener('click', () => {
        document.getElementById('objSearchInput').value = '';
        objState.q = '';
        objState.path = null;
        showObjectsTreeView();
      });

      renderObjectsTree(libraries);
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  function showObjectsTreeView() {
    document.getElementById('objectsTreeWrap').hidden = false;
    document.getElementById('objectsFlatWrap').hidden = true;
  }
  function showObjectsFlatView() {
    document.getElementById('objectsTreeWrap').hidden = true;
    document.getElementById('objectsFlatWrap').hidden = false;
  }

  /** Root level: one expandable node per library — filtered down to just one if objLibraryFilter is set, matching how the flat view's own library filter works. */
  function renderObjectsTree(libraries) {
    const container = document.getElementById('objectsTree');
    const toShow = objState.library_id ? libraries.filter((l) => String(l.id) === String(objState.library_id)) : libraries;
    if (!toShow.length) {
      container.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.no_library'))}</p>`;
      return;
    }
    container.innerHTML = toShow.map((l) => treeNodeHtml(l.type, l.id, l.name, [], l.item_count)).join('');
    bindTreeContainer(container);
  }

  function treeNodeHtml(type, libraryId, label, path, count) {
    return `
      <div class="tree-node" data-tree-type="${esc(type)}" data-tree-lib="${libraryId}" data-tree-path='${esc(JSON.stringify(path))}'>
        <div class="tree-row">
          <button type="button" class="tree-toggle" data-tree-toggle aria-label="${esc(t('admin.expand'))}">+</button>
          <span class="tree-label">${esc(label)}${count != null ? ` <span class="text-muted">(${count})</span>` : ''}</span>
        </div>
        <div class="tree-children" hidden></div>
      </div>`;
  }

  function treeItemRowHtml(item) {
    const typeLabel = TYPE_LABEL_KEYS[item.type] ? t(TYPE_LABEL_KEYS[item.type]) : item.type;
    return `
      <div class="tree-item" data-tree-item="${item.id}">
        <div class="tree-row tree-row-item" data-tree-item-toggle>
          <span class="tree-toggle tree-toggle-leaf">›</span>
          <span class="tree-label">${esc(item.title)} <span class="text-muted">(${esc(typeLabel)})</span></span>
        </div>
        <div class="tree-item-panel" hidden></div>
      </div>`;
  }

  /** Binds only the direct children of $container — called both on the tree root (once) and on each node's own .tree-children as it's lazily populated, so a node's toggle is never bound more than once. */
  function bindTreeContainer(container) {
    container.querySelectorAll(':scope > .tree-node > .tree-row [data-tree-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => toggleTreeNode(btn.closest('.tree-node')));
    });
    container.querySelectorAll(':scope > .tree-item > .tree-row').forEach((row) => {
      row.addEventListener('click', () => toggleTreeItem(row.closest('.tree-item')));
    });
    container.querySelectorAll(':scope > [data-tree-see-all]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const ctx = JSON.parse(btn.dataset.treeSeeAll);
        objState.library_id = String(ctx.libraryId);
        objState.type = ctx.type;
        objState.path = ctx.path;
        objState.q = '';
        objState.page = 1;
        document.getElementById('objLibraryFilter').value = String(ctx.libraryId);
        document.getElementById('objSearchInput').value = '';
        showObjectsFlatView();
        loadObjectsList();
      });
    });
  }

  async function toggleTreeNode(nodeEl) {
    const childrenEl = nodeEl.querySelector(':scope > .tree-children');
    const toggleBtn = nodeEl.querySelector(':scope > .tree-row [data-tree-toggle]');
    if (!childrenEl.hidden) {
      childrenEl.hidden = true;
      toggleBtn.textContent = '+';
      return;
    }
    childrenEl.hidden = false;
    toggleBtn.textContent = '−';
    if (childrenEl.dataset.loaded === '1') return;

    const type = nodeEl.dataset.treeType;
    const libraryId = nodeEl.dataset.treeLib;
    const path = JSON.parse(nodeEl.dataset.treePath);
    const pathParam = encodeURIComponent(JSON.stringify(path));
    toggleBtn.disabled = true;
    childrenEl.innerHTML = `<p class="text-muted tree-loading">${esc(t('common.loading'))}</p>`;
    try {
      const [subfolders, standalone] = await Promise.all([
        api('GET', `/api/admin-subfolders?type=${encodeURIComponent(type)}&library_id=${libraryId}&path=${pathParam}`),
        api('GET', `/api/admin-items?type=${encodeURIComponent(type)}&library_id=${libraryId}&path=${pathParam}&exact=1&limit=31&sort=filename&dir=ASC`),
      ]);
      let html = subfolders.map((sf) => treeNodeHtml(type, libraryId, sf.name, [...path, sf.name], sf.count)).join('');
      if (subfolders.length) {
        // Folders first, then any tomes sitting loose right alongside them —
        // capped inline so a big éditeur's odd one-shots don't turn one
        // tree level into a wall of rows; "voir tout" hands off to the
        // full paginated view for anything beyond that.
        html += standalone.items.slice(0, 30).map(treeItemRowHtml).join('');
        if (standalone.total > 30) {
          html += `<button type="button" class="btn btn-ghost btn-sm" data-tree-see-all='${esc(JSON.stringify({ type, libraryId, path }))}'>${esc(t('admin.see_all_n', { count: standalone.total }))}</button>`;
        }
      } else if (standalone.total > 0) {
        // A true leaf — no subfolders at all. Never rendered inline here,
        // however many there are: this is exactly the flat paginated view's
        // job, not the tree's.
        html = `<button type="button" class="btn btn-ghost btn-sm" data-tree-see-all='${esc(JSON.stringify({ type, libraryId, path }))}'>${esc(t('admin.see_all_n', { count: standalone.total }))}</button>`;
      }
      childrenEl.innerHTML = html || `<p class="text-muted tree-loading">${esc(t('admin.no_object_found'))}</p>`;
      childrenEl.dataset.loaded = '1';
      bindTreeContainer(childrenEl);
    } catch (err) {
      childrenEl.innerHTML = `<p class="text-muted tree-loading">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    } finally {
      toggleBtn.disabled = false;
    }
  }

  async function toggleTreeItem(itemEl) {
    const panel = itemEl.querySelector(':scope > .tree-item-panel');
    if (!panel.hidden) {
      panel.hidden = true;
      return;
    }
    panel.hidden = false;
    if (panel.dataset.loaded === '1') return;
    const id = itemEl.dataset.treeItem;
    try {
      const item = await api('GET', `/api/items/${id}`);
      panel.innerHTML = objectRowHtml(item);
      bindObjectRowActions(panel);
      panel.dataset.loaded = '1';
    } catch (err) {
      panel.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
    }
  }

  async function loadObjectsList() {
    const listEl = document.getElementById('objectsList');
    const countEl = document.getElementById('objResultCount');
    const paginationEl = document.getElementById('objectsPagination');
    if (!listEl) return; // the admin switched tabs before this resolved
    listEl.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const params = new URLSearchParams({
        limit: String(OBJ_PAGE_SIZE),
        offset: String((objState.page - 1) * OBJ_PAGE_SIZE),
        sort: 'filename',
        dir: 'ASC',
      });
      if (objState.library_id) params.set('library_id', objState.library_id);
      if (objState.q) params.set('q', objState.q);
      // Set together by the tree's own "voir tout" links (see
      // bindTreeContainer above) — scopes the flat view to one exact
      // folder rather than the whole library/search results.
      if (objState.path) {
        params.set('type', objState.type);
        params.set('path', JSON.stringify(objState.path));
        params.set('exact', '1');
      }
      const res = await api('GET', `/api/admin-items?${params.toString()}`);
      countEl.textContent = t(res.total === 1 ? 'library.result_count_one' : 'library.result_count_other', { count: res.total });
      if (!res.items.length) {
        listEl.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.no_object_found'))}</p>`;
        paginationEl.hidden = true;
        return;
      }
      listEl.innerHTML = res.items.map(objectRowHtml).join('');
      bindObjectRowActions(listEl);
      const totalPages = Math.max(1, Math.ceil(res.total / OBJ_PAGE_SIZE));
      if (totalPages <= 1) {
        paginationEl.hidden = true;
      } else {
        paginationEl.hidden = false;
        paginationEl.innerHTML = `
          <button type="button" class="btn btn-secondary" data-obj-page="prev" ${objState.page <= 1 ? 'disabled' : ''}>‹</button>
          <span class="page-indicator">${objState.page} / ${totalPages}</span>
          <button type="button" class="btn btn-secondary" data-obj-page="next" ${objState.page >= totalPages ? 'disabled' : ''}>›</button>
        `;
        paginationEl.querySelector('[data-obj-page="prev"]').addEventListener('click', () => { objState.page--; loadObjectsList(); });
        paginationEl.querySelector('[data-obj-page="next"]').addEventListener('click', () => { objState.page++; loadObjectsList(); });
      }
    } catch (err) {
      listEl.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  function objectRowHtml(item) {
    const typeLabel = TYPE_LABEL_KEYS[item.type] ? t(TYPE_LABEL_KEYS[item.type]) : item.type;
    const isCbz = item.type === 'comic' && (item.format || '').toLowerCase() === 'cbz';
    return `
      <div class="admin-row" id="obj-row-${item.id}">
        <div class="admin-row-main">
          <strong>${esc(item.title)}</strong>
          <span>${esc(typeLabel)} · ${esc(item.library_name || '')} · ${esc(item.format || '')}</span>
        </div>
        <div class="admin-row-actions">
          <div class="admin-row-actions-group">
            <button class="btn btn-secondary btn-sm" data-obj-sync="${item.id}">${esc(t('admin.sync'))}</button>
            <button class="btn btn-secondary btn-sm" data-obj-metadata="${item.id}" ${item.type !== 'comic' ? 'disabled title="' + esc(t('admin.comics_only')) + '"' : ''}>${esc(t('admin.force_metadata'))}</button>
            <button class="btn btn-secondary btn-sm" data-obj-cover="${item.id}">${esc(t('admin.force_cover'))}</button>
            ${isCbz ? `<button class="btn btn-secondary btn-sm" data-obj-edit-cbz="${item.id}">${esc(t('admin.edit_cbz'))}</button>` : ''}
            <a class="btn btn-secondary btn-sm" href="item.php?id=${item.id}">${esc(t('common.edit'))}</a>
          </div>
        </div>
        <div class="sync-result" id="obj-result-${item.id}"></div>
      </div>`;
  }

  function bindObjectRowActions(container) {
    container.querySelectorAll('[data-obj-edit-cbz]').forEach((btn) => {
      btn.addEventListener('click', () => openCbzEditor(btn.dataset.objEditCbz));
    });
    container.querySelectorAll('[data-obj-sync]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.objSync;
        const box = document.getElementById(`obj-result-${id}`);
        btn.disabled = true;
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('common.loading'))}</p>`;
        try {
          const res = await api('POST', `/api/items/${id}/sync`);
          box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(res.changed ? t('admin.obj_sync_changed', { meta: res.metaFound ? '✓' : '✗', cover: res.coverFound ? '✓' : '✗' }) : t('admin.obj_sync_unchanged'))}</p>`;
        } catch (err) {
          box.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
        } finally {
          btn.disabled = false;
        }
      });
    });
    container.querySelectorAll('[data-obj-metadata]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.objMetadata;
        const box = document.getElementById(`obj-result-${id}`);
        btn.disabled = true;
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('common.loading'))}</p>`;
        try {
          const res = await api('POST', `/api/items/${id}/extract-metadata`);
          box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(res.metaFound ? t('admin.obj_metadata_found') : t('admin.obj_metadata_not_found'))}</p>`;
        } catch (err) {
          box.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
        } finally {
          btn.disabled = false;
        }
      });
    });
    container.querySelectorAll('[data-obj-cover]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.objCover;
        const box = document.getElementById(`obj-result-${id}`);
        btn.disabled = true;
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('common.loading'))}</p>`;
        try {
          const res = await api('POST', `/api/items/${id}/regenerate-cover`);
          box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(res.coverFound ? t('admin.obj_cover_found') : t('admin.obj_cover_not_found'))}</p>`;
        } catch (err) {
          box.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  // ------------------------------------------------------------
  // CBZ editor popup — reorder/delete pages, edit ComicRack-schema
  // metadata, rewrite the archive. See CbzEditor.php's own docblock for
  // how the actual rewrite is kept safe (temp file, verified, atomic
  // rename); this end only has to make the destructive nature of the
  // action impossible to miss before it's triggered.
  // ------------------------------------------------------------
  const CBZ_META_FIELDS = [
    ['title', 'item.field_title'],
    ['series_name', 'item.field_series'],
    ['issue_number', 'item.field_issue_number'],
    ['publisher', 'item.field_publisher'],
    ['synopsis', 'item.synopsis'],
    ['writer', 'item.field_writer'],
    ['penciller', 'item.field_penciller'],
    ['inker', 'item.field_inker'],
    ['colorist', 'item.field_colorist'],
    ['letterer', 'item.field_letterer'],
    ['cover_artist', 'item.field_cover_artist'],
    ['editor', 'item.field_editor'],
    ['genre', 'item.field_genre'],
    ['characters', 'item.field_characters'],
    ['age_rating', 'item.field_age_rating'],
  ];
  const CBZ_MULTILINE_FIELDS = new Set(['synopsis', 'genre', 'characters']);

  async function openCbzEditor(id) {
    let data;
    try {
      data = await api('GET', `/api/items/${id}/cbz-pages`);
    } catch (err) {
      showToast(err.message, true);
      return;
    }

    // cbzPages holds the *original* entry name for every page still kept,
    // in the order they'll be renumbered on save — this is the only
    // state the move/delete buttons below actually mutate; the DOM is
    // just a rendering of it, rebuilt on every change rather than
    // patched in place, so there's one source of truth for "what will
    // actually get saved."
    let cbzPages = data.pages.slice();

    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.innerHTML = `
      <div class="dialog cbz-editor-dialog">
        <div class="dialog-title">${esc(t('admin.edit_cbz'))} — ${esc(data.meta.title || '')}</div>
        <div class="dialog-body">
          <div class="cbz-backup-warning">
            <strong>${esc(t('admin.cbz_backup_warning_title'))}</strong>
            <p>${esc(t('admin.cbz_backup_warning_body'))}</p>
          </div>
          <div class="cbz-editor-grid">
            ${CBZ_META_FIELDS.map(
              ([field, labelKey]) => `
              <div class="field"${field === 'synopsis' ? ' style="grid-column:1/-1;"' : ''}>
                <label for="cbz-${field}">${esc(t(labelKey))}</label>
                ${
                  CBZ_MULTILINE_FIELDS.has(field)
                    ? `<textarea class="input" id="cbz-${field}" rows="${field === 'synopsis' ? 5 : 2}">${esc(data.meta[field] || '')}</textarea>`
                    : `<input class="input" id="cbz-${field}" value="${esc(data.meta[field] ?? '')}" />`
                }
              </div>`
            ).join('')}
          </div>
          <h3 style="margin-top:var(--space-4);">${esc(t('admin.cbz_pages_title', { count: cbzPages.length }))}</h3>
          <p class="text-muted" style="font-size:12.5px;margin-top:-6px;">${esc(t('admin.cbz_pages_hint'))}</p>
          <div class="cbz-page-list" id="cbzPageList"></div>
        </div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="cbzCancelBtn">${esc(t('common.cancel'))}</button>
          <button type="button" class="btn btn-danger" id="cbzSaveBtn">${esc(t('admin.cbz_save'))}</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);

    function renderPageList() {
      const listEl = document.getElementById('cbzPageList');
      listEl.innerHTML = cbzPages
        .map(
          (name, i) => `
          <div class="cbz-page-cell" data-cbz-page-index="${i}">
            <img class="cbz-page-thumb" src="/api/items/${id}/page?index=${data.pages.indexOf(name)}" loading="lazy" alt="" />
            <div class="cbz-page-controls">
              <span class="cbz-page-num">${i + 1}</span>
              <button type="button" class="btn btn-ghost btn-sm" data-cbz-move="up" ${i === 0 ? 'disabled' : ''} title="${esc(t('admin.cbz_move_up'))}">↑</button>
              <button type="button" class="btn btn-ghost btn-sm" data-cbz-move="down" ${i === cbzPages.length - 1 ? 'disabled' : ''} title="${esc(t('admin.cbz_move_down'))}">↓</button>
              <button type="button" class="btn btn-ghost btn-sm" data-cbz-delete title="${esc(t('common.delete'))}">✕</button>
            </div>
          </div>`
        )
        .join('');
      listEl.querySelectorAll('[data-cbz-move="up"]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const i = Number(btn.closest('[data-cbz-page-index]').dataset.cbzPageIndex);
          [cbzPages[i - 1], cbzPages[i]] = [cbzPages[i], cbzPages[i - 1]];
          renderPageList();
        });
      });
      listEl.querySelectorAll('[data-cbz-move="down"]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const i = Number(btn.closest('[data-cbz-page-index]').dataset.cbzPageIndex);
          [cbzPages[i], cbzPages[i + 1]] = [cbzPages[i + 1], cbzPages[i]];
          renderPageList();
        });
      });
      listEl.querySelectorAll('[data-cbz-delete]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const i = Number(btn.closest('[data-cbz-page-index]').dataset.cbzPageIndex);
          if (cbzPages.length <= 1) {
            showToast(t('admin.cbz_need_one_page'), true);
            return;
          }
          if (!confirm(t('admin.cbz_confirm_delete_page', { n: i + 1 }))) return;
          cbzPages.splice(i, 1);
          renderPageList();
        });
      });
    }
    renderPageList();

    function close() {
      backdrop.remove();
      document.removeEventListener('keydown', onEsc);
    }
    function onEsc(e) {
      if (e.key === 'Escape') close();
    }
    document.addEventListener('keydown', onEsc);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.getElementById('cbzCancelBtn').addEventListener('click', close);

    document.getElementById('cbzSaveBtn').addEventListener('click', async () => {
      if (!confirm(t('admin.cbz_confirm_save', { count: cbzPages.length }))) return;
      const saveBtn = document.getElementById('cbzSaveBtn');
      saveBtn.disabled = true;
      saveBtn.textContent = t('admin.cbz_saving');
      const meta = {};
      CBZ_META_FIELDS.forEach(([field]) => {
        meta[field] = document.getElementById(`cbz-${field}`).value.trim();
      });
      try {
        const res = await api('POST', `/api/items/${id}/cbz-save`, { pages: cbzPages, meta });
        showToast(t('admin.cbz_save_success', { count: res.pageCount }));
        close();
        loadObjectsList();
      } catch (err) {
        showToast(err.message, true);
        saveBtn.disabled = false;
        saveBtn.textContent = t('admin.cbz_save');
      }
    });
  }

  // ============================================================
  // Settings (display/behavior)
  // ============================================================
  async function renderSettingsTab() {
    const panel = panels.settings;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const s = await api('GET', '/api/settings');
      panel.innerHTML = `
        <div class="admin-card">
          <h2>${esc(t('settings.appearance'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">${esc(t('settings.appearance_hint'))}</p>
          <div class="field" style="max-width:260px;">
            <label for="themeSelect">${esc(t('settings.theme'))}</label>
            <select class="input" id="themeSelect">
              <option value="dark" ${s.theme !== 'light' ? 'selected' : ''}>${esc(t('settings.theme_dark'))}</option>
              <option value="light" ${s.theme === 'light' ? 'selected' : ''}>${esc(t('settings.theme_light'))}</option>
            </select>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.thumbnails'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.thumbnails_hint')} ${s.gd_available ? t('settings.thumbnails_gd_available') : t('settings.thumbnails_gd_unavailable')}
          </p>
          <div class="field">
            <label for="thumbnailWidthSlider">${esc(t('settings.thumbnail_width'))} — <span id="thumbnailSizeLabel">${s.thumbnail_width} × ${s.thumbnail_height} px</span></label>
            <input id="thumbnailWidthSlider" type="range" min="50" max="300" step="5" value="${esc(s.thumbnail_width)}" style="width:100%;max-width:320px;" />
          </div>
          <div>
            <button type="button" class="btn btn-primary" id="saveThumbnailSizeBtn" style="margin-top:var(--space-3);">${esc(t('common.save'))}</button>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.grid_density'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.grid_density_hint')}
          </p>
          <div class="admin-form-row">
            <div class="field" style="flex:0 0 auto;">
              <label for="gridColumns">${esc(t('settings.columns'))}</label>
              <input class="input" id="gridColumns" type="number" min="1" max="15" value="${esc(s.grid_columns)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="gridPageSize">${esc(t('settings.max_items_per_page'))}</label>
              <input class="input" id="gridPageSize" type="number" min="1" max="300" value="${esc(s.grid_page_size)}" style="width:100px;" />
            </div>
            <div style="margin-left:auto;">
              <button type="button" class="btn btn-primary" id="saveGridDensityBtn">${esc(t('common.save'))}</button>
            </div>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.home_shelves'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.home_shelves_hint')}
          </p>
          <div class="admin-form-row">
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfFetchLimit">${esc(t('settings.loaded_items'))}</label>
              <input class="input" id="homeShelfFetchLimit" type="number" min="40" max="120" value="${esc(s.home_shelf_fetch_limit)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfColumns">${esc(t('settings.visible_columns'))}</label>
              <input class="input" id="homeShelfColumns" type="number" min="1" max="15" value="${esc(s.home_shelf_columns)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfRows">${esc(t('settings.visible_rows'))}</label>
              <input class="input" id="homeShelfRows" type="number" min="1" max="5" value="${esc(s.home_shelf_rows)}" style="width:100px;" />
            </div>
            <div style="margin-left:auto;">
              <button type="button" class="btn btn-primary" id="saveHomeShelfBtn">${esc(t('common.save'))}</button>
            </div>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.publisher_nav'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.publisher_nav_hint')}
          </p>
          <label class="mfa-force-toggle">
            <input type="checkbox" id="showPublishersToggle" ${s.show_publishers ? 'checked' : ''} />
            ${esc(t('settings.show_publisher_nav'))}
          </label>
          <label class="mfa-force-toggle">
            <input type="checkbox" id="showEmptyLibrariesNavToggle" ${s.show_empty_libraries_nav ? 'checked' : ''} />
            ${esc(t('settings.show_empty_libraries'))}
          </label>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.sync_filter'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.sync_filter_hint')}
          </p>
          <form class="admin-form" id="excludeForm">
            <div class="field">
              <label for="excludePattern">${esc(t('settings.exclude_pattern'))}</label>
              <input class="input" id="excludePattern" style="font-family:monospace;" value="${esc(s.scan_exclude_pattern)}" />
            </div>
            <div>
              <button type="submit" class="btn btn-primary">${esc(t('common.save'))}</button>
            </div>
          </form>
          <div class="field" style="margin-top:16px;">
            <label for="excludeTest">${esc(t('settings.test_filename'))}</label>
            <input class="input" id="excludeTest" placeholder="folder.jpg" />
            <p class="text-muted" id="excludeTestResult" style="font-size:13px;margin:8px 0 0;"></p>
          </div>
          <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-divider);">
            <button type="button" class="btn btn-secondary btn-sm" id="previewCleanupBtn">${esc(t('settings.preview_wrongly_scanned'))}</button>
            <div id="cleanupResult" style="margin-top:10px;"></div>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.allowed_formats'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.allowed_formats_hint')}
          </p>
          <table class="admin-table" style="margin-bottom:14px;">
            <tbody>
              <tr><td>${esc(t('type.comic'))}</td><td>CBZ, CBR, PDF, EPUB</td></tr>
              <tr><td>${esc(t('type.ebook'))}</td><td>PDF, EPUB</td></tr>
              <tr><td>${esc(t('type.magazine'))}</td><td>PDF</td></tr>
              <tr><td>${esc(t('type.other'))}</td><td>JPG, PNG, BMP, HEIC, TIFF</td></tr>
            </tbody>
          </table>
          <button type="button" class="btn btn-secondary btn-sm" id="previewWrongFormatBtn">${esc(t('settings.preview_wrong_format'))}</button>
          <div id="wrongFormatResult" style="margin-top:10px;"></div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.orphaned_covers'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.orphaned_covers_hint')}
          </p>
          <button type="button" class="btn btn-secondary btn-sm" id="previewOrphanedCoversBtn">${esc(t('settings.preview_orphaned_covers'))}</button>
          <div id="orphanedCoversResult" style="margin-top:10px;"></div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('settings.scheduled_sync'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('settings.scheduled_sync_hint')}
          </p>
          <div class="field">
            <label>${esc(t('settings.sync_token'))}</label>
            <div class="path-field">
              <input class="input" id="syncTokenField" value="${esc(s.sync_token)}" readonly onclick="this.select()" style="font-family:monospace;" />
              <button type="button" class="btn btn-secondary btn-sm" id="regenTokenBtn">${esc(t('settings.regenerate_token'))}</button>
            </div>
          </div>
          <div class="field">
            <label>${esc(t('settings.crontab_example'))}</label>
            <textarea class="input" readonly rows="2" style="font-family:monospace;font-size:12px;" onclick="this.select()">0 3 * * * curl -s -X POST ${esc(s.site_url)}/api/sync-all -H "X-Sync-Token: ${esc(s.sync_token)}"</textarea>
          </div>
        </div>
      `;

      document.getElementById('regenTokenBtn').addEventListener('click', async () => {
        if (!confirm(t('settings.confirm_regen_token'))) return;
        try {
          const res = await api('POST', '/api/settings/regenerate-sync-token');
          document.getElementById('syncTokenField').value = res.sync_token;
          showToast(t('settings.token_regenerated'));
          renderSettingsTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });

      async function setTheme(theme) {
        try {
          await api('PUT', '/api/settings', { theme });
          location.reload(); // data-theme lives on <html>, set server-side at page load — only a reload actually shows the new theme
        } catch (err) {
          showToast(err.message, true);
        }
      }
      document.getElementById('themeSelect').addEventListener('change', (e) => setTheme(e.target.value));

      document.getElementById('thumbnailWidthSlider').addEventListener('input', (e) => {
        const w = Number(e.target.value);
        const h = Math.round((w * 36) / 25);
        document.getElementById('thumbnailSizeLabel').textContent = `${w} × ${h} px`;
      });

      document.getElementById('saveThumbnailSizeBtn').addEventListener('click', async () => {
        const width = Number(document.getElementById('thumbnailWidthSlider').value);
        try {
          await api('PUT', '/api/settings', { thumbnail_width: width });
          showToast(t('common.saved'));
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('saveGridDensityBtn').addEventListener('click', async () => {
        const gridColumns = Number(document.getElementById('gridColumns').value);
        const gridSize = Number(document.getElementById('gridPageSize').value);
        if (!gridColumns || gridColumns < 1 || gridColumns > 15) {
          showToast(t('settings.err_columns_range'), true);
          return;
        }
        if (!gridSize || gridSize < 1 || gridSize > 300) {
          showToast(t('settings.err_page_size_range'), true);
          return;
        }
        try {
          await api('PUT', '/api/settings', { grid_columns: gridColumns, grid_page_size: gridSize });
          showToast(t('common.saved'));
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('saveHomeShelfBtn').addEventListener('click', async () => {
        const fetchLimit = Number(document.getElementById('homeShelfFetchLimit').value);
        const shelfColumns = Number(document.getElementById('homeShelfColumns').value);
        const shelfRows = Number(document.getElementById('homeShelfRows').value);
        if (!fetchLimit || fetchLimit < 40 || fetchLimit > 120) {
          showToast(t('settings.err_loaded_items_range'), true);
          return;
        }
        if (!shelfColumns || shelfColumns < 1 || shelfColumns > 15) {
          showToast(t('settings.err_visible_columns_range'), true);
          return;
        }
        if (!shelfRows || shelfRows < 1 || shelfRows > 5) {
          showToast(t('settings.err_visible_rows_range'), true);
          return;
        }
        try {
          await api('PUT', '/api/settings', {
            home_shelf_fetch_limit: fetchLimit,
            home_shelf_columns: shelfColumns,
            home_shelf_rows: shelfRows,
          });
          showToast(t('common.saved'));
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('showPublishersToggle').addEventListener('change', async (e) => {
        try {
          await api('PUT', '/api/settings', { show_publishers: e.target.checked });
          showToast(t('common.saved'));
        } catch (err) {
          e.target.checked = !e.target.checked;
          showToast(err.message, true);
        }
      });
      document.getElementById('showEmptyLibrariesNavToggle').addEventListener('change', async (e) => {
        try {
          await api('PUT', '/api/settings', { show_empty_libraries_nav: e.target.checked });
          showToast(t('common.saved'));
        } catch (err) {
          e.target.checked = !e.target.checked;
          showToast(err.message, true);
        }
      });

      document.getElementById('excludeForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('PUT', '/api/settings', { scan_exclude_pattern: document.getElementById('excludePattern').value });
          showToast(t('settings.pattern_saved'));
        } catch (err) {
          showToast(err.message, true);
        }
      });

      let excludeTestTimer = null;
      document.getElementById('excludeTest').addEventListener('input', () => {
        clearTimeout(excludeTestTimer);
        const resultEl = document.getElementById('excludeTestResult');
        const filename = document.getElementById('excludeTest').value.trim();
        if (!filename) {
          resultEl.textContent = '';
          return;
        }
        excludeTestTimer = setTimeout(async () => {
          try {
            const res = await api('POST', '/api/settings/test-exclude-pattern', {
              pattern: document.getElementById('excludePattern').value,
              filename,
            });
            resultEl.textContent = res.matches ? '✓ ' + t('settings.pattern_matches') : '✗ ' + t('settings.pattern_no_match');
            resultEl.style.color = res.matches ? '#7be3ab' : '';
          } catch (err) {
            resultEl.textContent = t('common.error') + ' : ' + err.message;
          }
        }, 300);
      });

      document.getElementById('previewCleanupBtn').addEventListener('click', async () => {
        const box = document.getElementById('cleanupResult');
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.searching'))}</p>`;
        try {
          const res = await api('GET', '/api/cleanup-excluded');
          if (!res.matches.length) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('settings.no_match_current_pattern'))}</p>`;
            return;
          }
          box.innerHTML = `
            <p style="font-size:13px;">${esc(t('settings.items_match_pattern', { count: res.matches.length }))}</p>
            <ul style="font-size:12.5px;color:var(--color-text);opacity:0.8;max-height:160px;overflow-y:auto;margin:8px 0;padding-left:18px;">
              ${res.matches.map((m) => `<li>${esc(m.title)} <span class="text-muted">(${esc(m.path)})</span></li>`).join('')}
            </ul>
            <button type="button" class="btn btn-danger btn-sm" id="confirmCleanupBtn">${esc(t('admin.delete_n_items', { count: res.matches.length }))}</button>
          `;
          document.getElementById('confirmCleanupBtn').addEventListener('click', async () => {
            if (!confirm(t('settings.confirm_delete_matching', { count: res.matches.length }))) return;
            try {
              const delRes = await api('POST', '/api/cleanup-excluded');
              box.innerHTML = `<div class="invite-link-box">${esc(t('admin.items_deleted', { count: delRes.deleted }))}</div>`;
              showToast(t('admin.cleanup_done'));
            } catch (err) {
              showToast(err.message, true);
            }
          });
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
      });

      document.getElementById('previewWrongFormatBtn').addEventListener('click', async () => {
        const box = document.getElementById('wrongFormatResult');
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.searching'))}</p>`;
        try {
          const res = await api('GET', '/api/cleanup-wrong-format');
          if (!res.matches.length) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('settings.no_wrong_format_item'))}</p>`;
            return;
          }
          box.innerHTML = `
            <p style="font-size:13px;">${esc(t('settings.wrong_format_items_found', { count: res.matches.length }))}</p>
            <ul style="font-size:12.5px;color:var(--color-text);opacity:0.8;max-height:160px;overflow-y:auto;margin:8px 0;padding-left:18px;">
              ${res.matches.map((m) => `<li>${esc(m.title)} — <span class="text-muted">${esc(m.library_name)} (${esc(m.format)}) — ${esc(m.path)}</span></li>`).join('')}
            </ul>
            <button type="button" class="btn btn-danger btn-sm" id="confirmWrongFormatBtn">${esc(t('admin.delete_n_items', { count: res.matches.length }))}</button>
          `;
          document.getElementById('confirmWrongFormatBtn').addEventListener('click', async () => {
            if (!confirm(t('settings.confirm_delete_wrong_format', { count: res.matches.length }))) return;
            try {
              const delRes = await api('POST', '/api/cleanup-wrong-format');
              box.innerHTML = `<div class="invite-link-box">${esc(t('admin.items_deleted', { count: delRes.deleted }))}</div>`;
              showToast(t('admin.cleanup_done'));
            } catch (err) {
              showToast(err.message, true);
            }
          });
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
      });

      document.getElementById('previewOrphanedCoversBtn').addEventListener('click', async () => {
        const box = document.getElementById('orphanedCoversResult');
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('admin.searching'))}</p>`;
        try {
          const res = await api('GET', '/api/cleanup-orphaned-covers');
          if (!res.matches.length) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('settings.no_orphaned_cover'))}</p>`;
            return;
          }
          const totalSize = res.matches.reduce((sum, m) => sum + m.size, 0);
          box.innerHTML = `
            <p style="font-size:13px;">${esc(t('settings.orphaned_covers_found', { count: res.matches.length, size: formatBytes(totalSize) }))}</p>
            <ul style="font-size:12.5px;color:var(--color-text);opacity:0.8;max-height:160px;overflow-y:auto;margin:8px 0;padding-left:18px;">
              ${res.matches.map((m) => `<li>${esc(m.filename)} — <span class="text-muted">${formatBytes(m.size)}</span></li>`).join('')}
            </ul>
            <button type="button" class="btn btn-danger btn-sm" id="confirmOrphanedCoversBtn">${esc(t('admin.delete_n_items', { count: res.matches.length }))}</button>
          `;
          document.getElementById('confirmOrphanedCoversBtn').addEventListener('click', async () => {
            if (!confirm(t('settings.confirm_delete_orphaned_covers', { count: res.matches.length }))) return;
            try {
              const delRes = await api('POST', '/api/cleanup-orphaned-covers');
              box.innerHTML = `<div class="invite-link-box">${esc(t('admin.items_deleted', { count: delRes.deleted }))}</div>`;
              showToast(t('admin.cleanup_done'));
            } catch (err) {
              showToast(err.message, true);
            }
          });
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  // ============================================================
  // Maintenance (backup + email)
  // ============================================================
  async function renderMaintenanceTab() {
    const panel = panels.maintenance;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const [s, templates, backupsRes] = await Promise.all([
        api('GET', '/api/settings'),
        api('GET', '/api/email-templates'),
        api('GET', '/api/backups'),
      ]);
      const backups = backupsRes.backups;
      panel.innerHTML = `
        <div class="admin-card">
          <h2>${esc(t('maintenance.backup'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('maintenance.backup_hint')}
          </p>
          <a class="btn btn-primary" href="/api/backup" download>${esc(t('maintenance.download_backup'))}</a>
        </div>
        <div class="admin-card">
          <h2>${esc(t('maintenance.scheduled_backup'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">${esc(t('maintenance.scheduled_backup_hint'))}</p>
          <div class="field">
            <label>${esc(t('maintenance.backup_token'))}</label>
            <div class="path-field">
              <input class="input" id="backupTokenField" value="${esc(s.backup_token)}" readonly onclick="this.select()" style="font-family:monospace;" />
              <button type="button" class="btn btn-secondary btn-sm" id="regenBackupTokenBtn">${esc(t('settings.regenerate_token'))}</button>
            </div>
          </div>
          <div class="field">
            <label>${esc(t('maintenance.backup_crontab_example'))}</label>
            <textarea class="input" readonly rows="2" style="font-family:monospace;font-size:12px;" onclick="this.select()">0 4 * * * curl -s -X POST ${esc(s.site_url)}/api/backup -H "X-Backup-Token: ${esc(s.backup_token)}"</textarea>
          </div>
          <div class="field" style="margin-top:var(--space-3);">
            <label>${esc(t('maintenance.stored_backups', { count: backups.length }))}</label>
            <p class="text-muted" style="font-size:12.5px;margin-top:-4px;">${esc(t('maintenance.retention_hint'))}</p>
            <div id="backupList">
              ${
                backups.length
                  ? backups
                      .map(
                        (b) => `<div class="admin-row-compact">
                          <span>${esc(b.filename)} — <span class="text-muted">${formatSyncDate(b.created_at)}, ${formatBytes(b.size)}</span></span>
                          <span style="display:flex;gap:6px;">
                            <a class="btn btn-secondary btn-sm" href="/api/backups/${esc(b.filename)}" download>${esc(t('maintenance.download'))}</a>
                            <button type="button" class="btn btn-danger btn-sm" data-delete-backup="${esc(b.filename)}">${esc(t('common.delete'))}</button>
                          </span>
                        </div>`
                      )
                      .join('')
                  : `<p class="text-muted" style="font-size:13px;">${esc(t('maintenance.no_stored_backup'))}</p>`
              }
            </div>
          </div>
        </div>
        <div class="admin-card">
          <h2>${esc(t('maintenance.smtp'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">${esc(t('maintenance.smtp_hint'))}</p>
          <form class="admin-form" id="smtpForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpHost">${esc(t('maintenance.smtp_server'))}</label>
                <input class="input" id="smtpHost" value="${esc(s.smtp_host || '')}" placeholder="smtp.gmail.com" />
              </div>
              <div class="field">
                <label for="smtpPort">${esc(t('maintenance.port'))}</label>
                <input class="input" id="smtpPort" value="${esc(s.smtp_port || '587')}" />
              </div>
              <div class="field">
                <label for="smtpEncryption">${esc(t('maintenance.encryption'))}</label>
                <select class="input" id="smtpEncryption">
                  <option value="starttls" ${s.smtp_encryption === 'starttls' ? 'selected' : ''}>STARTTLS</option>
                  <option value="ssl" ${s.smtp_encryption === 'ssl' ? 'selected' : ''}>${esc(t('maintenance.implicit_ssl'))}</option>
                  <option value="none" ${s.smtp_encryption === 'none' ? 'selected' : ''}>${esc(t('maintenance.none'))}</option>
                </select>
              </div>
            </div>
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpUsername">${esc(t('maintenance.smtp_user'))}</label>
                <input class="input" id="smtpUsername" value="${esc(s.smtp_username || '')}" />
              </div>
              <div class="field">
                <label for="smtpPassword">${esc(t('maintenance.smtp_password'))}</label>
                <input class="input" id="smtpPassword" type="password" placeholder="${s.smtp_password_set ? esc(t('maintenance.password_set_placeholder')) : ''}" />
              </div>
            </div>
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpFromEmail">${esc(t('maintenance.from_address'))}</label>
                <input class="input" id="smtpFromEmail" type="email" value="${esc(s.smtp_from_email || '')}" placeholder="codex@example.com" />
              </div>
              <div class="field">
                <label for="smtpFromName">${esc(t('maintenance.from_name'))}</label>
                <input class="input" id="smtpFromName" value="${esc(s.smtp_from_name || 'Codex')}" />
              </div>
            </div>
            <div class="field">
              <label for="siteUrl">${esc(t('maintenance.site_url'))}</label>
              <input class="input" id="siteUrl" value="${esc(s.site_url || '')}" />
            </div>
            <div class="field">
              <label for="githubUrl">${esc(t('maintenance.github_url'))}</label>
              <input class="input" id="githubUrl" value="${esc(s.github_url || '')}" placeholder="https://github.com/..." />
              <p class="text-muted" style="font-size:12.5px;margin-top:6px;">${esc(t('maintenance.github_url_hint'))}</p>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">${esc(t('common.save'))}</button>
            </div>
          </form>
        </div>
        <div class="admin-card">
          <h2>${esc(t('maintenance.email_templates'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('maintenance.email_templates_hint')}
          </p>
          <div class="field">
            <label for="templateSelect">${esc(t('maintenance.template'))}</label>
            <select class="input" id="templateSelect">
              ${Object.entries(templates).map(([key, tpl]) => `<option value="${esc(key)}">${esc(tpl.label)}</option>`).join('')}
            </select>
          </div>
          <form class="admin-form" id="templateForm">
            <div class="field">
              <label for="templateSubject">${esc(t('maintenance.subject'))}</label>
              <input class="input" id="templateSubject" required />
            </div>
            <div class="field">
              <label for="templateBody">${esc(t('maintenance.message'))}</label>
              <textarea class="input" id="templateBody" rows="8" required></textarea>
              <p class="text-muted" id="templatePlaceholders" style="font-size:12.5px;margin-top:6px;"></p>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary">${esc(t('maintenance.save_template'))}</button>
              <button type="button" class="btn btn-ghost" id="templateResetBtn">${esc(t('maintenance.restore_default'))}</button>
            </div>
            <div id="templateResult"></div>
          </form>
        </div>
        <div class="admin-card">
          <h2>${esc(t('maintenance.test_sending'))}</h2>
          <form class="admin-form" id="testForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="testEmail">${esc(t('maintenance.send_test_to'))}</label>
                <input class="input" id="testEmail" type="email" required />
              </div>
            </div>
            <div>
              <button type="submit" class="btn btn-secondary">${esc(t('maintenance.send_test'))}</button>
            </div>
            <div id="testResult"></div>
          </form>
        </div>
      `;

      function loadTemplateIntoForm(key) {
        const tpl = templates[key];
        document.getElementById('templateSubject').value = tpl.subject;
        document.getElementById('templateBody').value = tpl.body;
        document.getElementById('templatePlaceholders').textContent = tpl.placeholders.length
          ? t('maintenance.available_placeholders', { list: tpl.placeholders.map((p) => `{${p}}`).join(', ') })
          : '';
        document.getElementById('templateResult').innerHTML = '';
      }

      const templateSelect = document.getElementById('templateSelect');
      loadTemplateIntoForm(templateSelect.value);
      templateSelect.addEventListener('change', () => loadTemplateIntoForm(templateSelect.value));

      document.getElementById('templateForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const box = document.getElementById('templateResult');
        const key = templateSelect.value;
        try {
          await api('PUT', '/api/email-templates', {
            key,
            subject: document.getElementById('templateSubject').value,
            body: document.getElementById('templateBody').value,
          });
          templates[key].subject = document.getElementById('templateSubject').value;
          templates[key].body = document.getElementById('templateBody').value;
          box.innerHTML = `<p class="account-success" style="font-size:13px;">${esc(t('maintenance.template_saved'))}</p>`;
        } catch (err) {
          box.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
        }
      });

      document.getElementById('templateResetBtn').addEventListener('click', async () => {
        const key = templateSelect.value;
        if (!confirm(t('maintenance.confirm_restore_default', { label: templates[key].label }))) return;
        const box = document.getElementById('templateResult');
        try {
          await api('POST', '/api/email-templates-reset', { key });
          const fresh = await api('GET', '/api/email-templates');
          templates[key] = fresh[key];
          loadTemplateIntoForm(key);
          box.innerHTML = `<p class="account-success" style="font-size:13px;">${esc(t('maintenance.default_restored'))}</p>`;
        } catch (err) {
          box.innerHTML = `<p class="account-error" style="font-size:13px;">${esc(err.message)}</p>`;
        }
      });

      document.getElementById('regenBackupTokenBtn').addEventListener('click', async () => {
        if (!confirm(t('settings.confirm_regen_token'))) return;
        try {
          const res = await api('POST', '/api/settings/regenerate-backup-token');
          document.getElementById('backupTokenField').value = res.backup_token;
          showToast(t('settings.token_regenerated'));
          renderMaintenanceTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.querySelectorAll('[data-delete-backup]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!confirm(t('maintenance.confirm_delete_backup', { filename: btn.dataset.deleteBackup }))) return;
          try {
            await api('DELETE', `/api/backups/${btn.dataset.deleteBackup}`);
            showToast(t('admin.item_deleted'));
            renderMaintenanceTab();
          } catch (err) {
            showToast(err.message, true);
          }
        });
      });

      document.getElementById('smtpForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('PUT', '/api/settings', {
            smtp_host: document.getElementById('smtpHost').value.trim(),
            smtp_port: document.getElementById('smtpPort').value.trim(),
            smtp_encryption: document.getElementById('smtpEncryption').value,
            smtp_username: document.getElementById('smtpUsername').value.trim(),
            smtp_password: document.getElementById('smtpPassword').value,
            smtp_from_email: document.getElementById('smtpFromEmail').value.trim(),
            smtp_from_name: document.getElementById('smtpFromName').value.trim(),
            site_url: document.getElementById('siteUrl').value.trim(),
            github_url: document.getElementById('githubUrl').value.trim(),
          });
          showToast(t('maintenance.settings_saved'));
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('testForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const box = document.getElementById('testResult');
        box.innerHTML = `<p class="text-muted" style="font-size:13px;">${esc(t('account.sending'))}</p>`;
        try {
          const res = await api('POST', '/api/settings/test-email', { to: document.getElementById('testEmail').value.trim() });
          box.innerHTML = `<div class="invite-link-box"><span class="status ${res.sent ? 'status-ok' : 'status-fail'}">${res.sent ? '✓ ' + esc(t('maintenance.sent')) : '⚠ ' + esc(res.error || t('maintenance.failed'))}</span></div>`;
        } catch (err) {
          box.innerHTML = `<div class="invite-link-box"><span class="status status-fail">⚠ ${esc(err.message)}</span></div>`;
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  // ============================================================
  // System (status + security + logs, in that order — logs last since
  // they're the "dig deeper" step, reached after the summary above
  // already points at what's wrong)
  // ============================================================
  async function renderSystemTab() {
    const panel = panels.system;
    panel.innerHTML = `<p class="text-muted">${esc(t('common.loading'))}</p>`;
    try {
      const [status, attempts] = await Promise.all([
        api('GET', '/api/system-status'),
        api('GET', '/api/login-attempts'),
      ]);
      panel.innerHTML = `
        <div class="admin-card">
          <h2>${esc(t('system.status'))}</h2>
          <table class="admin-table">
            <tbody>
              <tr><td>${esc(t('system.php_version'))}</td><td>${esc(status.php_version)}</td></tr>
              <tr><td>${esc(t('system.thumbnails_gd'))}</td><td>${status.gd_available ? esc(t('system.available')) : esc(t('system.gd_unavailable'))}</td></tr>
              <tr><td>${esc(t('system.pdf_rendering'))}</td><td>${status.poppler_available ? esc(t('system.available')) : esc(t('system.poppler_unavailable'))}</td></tr>
              <tr><td>${esc(t('system.email_sending'))}</td><td>${status.smtp_configured ? esc(t('system.configured')) : esc(t('system.not_configured'))}</td></tr>
              <tr><td>${esc(t('system.database'))}</td><td>${formatBytes(status.db_size_bytes)}</td></tr>
              <tr><td>${esc(t('admin.tab_libraries'))}</td><td>${formatCount(status.library_count)}</td></tr>
              <tr><td>${esc(t('admin.tab_users'))}</td><td>${formatCount(status.user_count)}</td></tr>
              <tr><td>${esc(t('system.items'))}</td><td>${t('system.items_detail', { total: formatCount(status.item_count), missing_meta: formatCount(status.items_missing_metadata), missing_cover: formatCount(status.items_missing_cover) })}</td></tr>
            </tbody>
          </table>
        </div>
        <div class="admin-card">
          <h2>${esc(t('system.login_attempts'))}</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            ${t('system.login_attempts_hint')}
          </p>
          <div id="loginAttemptsList">
            ${
              attempts.length
                ? attempts
                    .map(
                      (a) => `
              <div class="admin-row-compact">
                <span>${esc(a.ip)} — ${t('system.failure_count', { count: a.count })}${a.locked ? ' — <strong>' + esc(t('system.locked_for', { minutes: Math.ceil(a.seconds_remaining / 60) })) + '</strong>' : ''}</span>
                <button class="btn btn-secondary btn-sm" data-clear-attempt="${esc(a.ip)}">${esc(t('system.unlock'))}</button>
              </div>`
                    )
                    .join('')
                : `<p class="text-muted" style="font-size:13px;">${esc(t('system.no_failed_attempt'))}</p>`
            }
          </div>
        </div>
        <div class="admin-card">
          <div class="admin-card-head">
            <h2>${esc(t('system.logs'))}</h2>
            <div class="admin-row-actions-group">
              <select class="input" id="logSelect" style="width:auto;">
                <option value="error">${esc(t('system.log_errors'))}</option>
                <option value="access">${esc(t('system.log_access'))}</option>
              </select>
              <button class="btn btn-secondary btn-sm" id="logRefreshBtn">${esc(t('system.refresh'))}</button>
            </div>
          </div>
          <p class="text-muted" id="logPath" style="font-size:12.5px;margin-top:-8px;"></p>
          <pre id="logContent" style="background:var(--color-bg);border:1px solid var(--color-divider);border-radius:var(--radius-md);padding:12px;max-height:520px;overflow:auto;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;"></pre>
        </div>
      `;

      document.querySelectorAll('[data-clear-attempt]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          try {
            await api('DELETE', `/api/login-attempts?ip=${encodeURIComponent(btn.dataset.clearAttempt)}`);
            showToast(t('system.ip_unlocked'));
            renderSystemTab();
          } catch (err) {
            showToast(err.message, true);
          }
        });
      });

      const select = document.getElementById('logSelect');
      const pathEl = document.getElementById('logPath');
      const contentEl = document.getElementById('logContent');

      async function loadLogs() {
        contentEl.textContent = t('common.loading');
        try {
          const res = await api('GET', `/api/logs?log=${select.value}&lines=300`);
          pathEl.textContent = res.path;
          if (res.note) {
            contentEl.textContent = res.note;
          } else {
            contentEl.textContent = res.lines.length ? res.lines.join('\n') : t('system.log_empty');
            contentEl.scrollTop = contentEl.scrollHeight; // most recent entries are at the bottom, like a real tail
          }
        } catch (err) {
          contentEl.textContent = '';
          showToast(err.message, true);
        }
      }

      select.addEventListener('change', loadLogs);
      document.getElementById('logRefreshBtn').addEventListener('click', loadLogs);
      loadLogs();
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">${esc(t('library.generic_error', { message: err.message }))}</p>`;
    }
  }

  renderUsersTab();
})();
