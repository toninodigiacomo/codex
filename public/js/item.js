(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABEL_KEYS = { comic: 'type.comic_short', ebook: 'type.ebook_short', magazine: 'type.magazine_short', other: 'type.other_short' };

  const COMIC_CONTRIBUTOR_FIELDS = [
    ['writer', 'item.field_writer'],
    ['penciller', 'item.field_penciller'],
    ['inker', 'item.field_inker'],
    ['colorist', 'item.field_colorist'],
    ['letterer', 'item.field_letterer'],
    ['cover_artist', 'item.field_cover_artist'],
    ['editor', 'item.field_editor'],
  ];
  const COMIC_CLASSIFICATION_FIELDS = [
    ['genre', 'item.field_genre'],
    ['characters', 'item.field_characters'],
    ['age_rating', 'item.field_age_rating'],
  ];
  const EBOOK_FIELDS = [
    ['author', 'item.field_author'],
    ['isbn', 'item.field_isbn'],
    ['language', 'item.field_language'],
  ];
  const MAGAZINE_FIELDS = [
    ['issue_date', 'item.field_issue_date'],
    ['frequency', 'item.field_frequency'],
  ];

  const page = document.getElementById('itemPage');
  const isReadOnly = page.dataset.userRole === 'reader_basic';
  const params = new URLSearchParams(window.location.search);
  const itemId = params.get('id');

  async function fetchJson(url, options) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  function fieldInput(name, labelKey, value, full) {
    return `
      <div class="field${full ? ' full' : ''}">
        <label for="f-${name}">${esc(t(labelKey))}</label>
        <input class="input" id="f-${name}" data-field="${esc(name)}" value="${esc(value)}" ${isReadOnly ? 'readonly' : ''} />
      </div>`;
  }

  function render(item) {
    const typeLabel = TYPE_LABEL_KEYS[item.type] ? t(TYPE_LABEL_KEYS[item.type]) : item.type;
    const kickerParts = [typeLabel];
    if (item.series_name) kickerParts.push(item.issue_number ? `${item.series_name} #${item.issue_number}` : item.series_name);
    if (item.publisher) kickerParts.push(item.publisher);

    const details = item.details || {};
    let typeFields = '';
    if (item.type === 'comic') {
      typeFields = `
        <div class="form-section">
          <h3>${esc(t('item.section_contributors'))}</h3>
          <div class="form-grid">
            ${COMIC_CONTRIBUTOR_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>
        <div class="form-section">
          <h3>${esc(t('item.section_classification'))}</h3>
          <div class="form-grid">
            ${COMIC_CLASSIFICATION_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    } else if (item.type === 'ebook') {
      typeFields = `
        <div class="form-section">
          <h3>${esc(t('item.section_ebook_details'))}</h3>
          <div class="form-grid">
            ${EBOOK_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    } else if (item.type === 'magazine') {
      typeFields = `
        <div class="form-section">
          <h3>${esc(t('item.section_magazine_details'))}</h3>
          <div class="form-grid">
            ${MAGAZINE_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    }

    page.innerHTML = `
      <div class="item-head">
        <div class="item-cover-lg">
          ${item.cover_path ? `<img src="${esc(item.cover_path)}" alt="" />` : `<div class="fallback">${esc(item.title)}</div>`}
        </div>
        <div class="item-head-info">
          <p class="kicker">${esc(kickerParts.join(' · '))}</p>
          <h1>${esc(item.title)}</h1>
          <div class="reader-toolbar" id="readerToolbar" data-item-id="${item.id}">
            <button type="button" class="reader-btn favorite-btn ${item.is_favorite ? 'is-favorite' : ''}" id="favoriteBtn" title="${esc(item.is_favorite ? t('item.remove_favorite') : t('item.add_favorite'))}" aria-label="${esc(t('library.chip_favorites'))}">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="${item.is_favorite ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </button>
            <a class="reader-btn" href="/api/items/${item.id}/download" title="${esc(t('item.download'))}" aria-label="${esc(t('item.download'))}">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
            </a>
            ${
              item.format === 'epub'
                ? ''
                : `<a class="reader-btn" id="readBtn" href="/reader.php?id=${item.id}" title="${esc(t('item.read'))}" aria-label="${esc(t('item.read'))}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5c2-1 5-1 7 0v14c-2-1-5-1-7 0Z"/><path d="M22 5c-2-1-5-1-7 0v14c2-1 5-1 7 0Z"/></svg>
                  </a>
                  <button type="button" class="reader-btn" id="resetProgressBtn" title="${esc(t('item.reset_progress'))}" aria-label="${esc(t('item.reset_progress'))}" hidden>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 1 2.64 6.36"/><path d="M3 21v-6h6"/></svg>
                  </button>
                  <button type="button" class="reader-btn" id="markReadBtn" title="${esc(t('item.mark_read'))}" aria-label="${esc(t('item.mark_read_short'))}">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
                  </button>`
            }
          </div>
          ${
            item.format === 'epub'
              ? ''
              : `<div class="reader-progress" id="readerProgress">
                  <div class="reader-progress-bar"><div class="reader-progress-fill" id="readerProgressFill"></div></div>
                  <span class="reader-progress-label" id="readerProgressLabel"></span>
                </div>`
          }
          <div class="field item-synopsis-field">
            <div class="item-synopsis-label-row">
              <label for="f-synopsis">${esc(t('item.synopsis'))}</label>
              <button type="button" class="item-synopsis-expand" id="synopsisExpandBtn" title="${esc(t('item.view_full_synopsis'))}" aria-label="${esc(t('item.view_full_synopsis'))}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
              </button>
            </div>
            <textarea class="input" id="f-synopsis" form="itemForm" data-field="synopsis" rows="8" ${isReadOnly ? 'readonly' : ''}>${esc(item.synopsis)}</textarea>
          </div>
        </div>
      </div>
      <div class="item-tags-display" id="tagsDisplay">
        ${(item.tags || []).map((tag) => `<span class="tag tag-accent">${esc(tag.name)}</span>`).join('')}
      </div>

      <form id="itemForm">
        <div class="form-section">
          <h3>${esc(t('item.section_general'))}</h3>
          <div class="form-grid">
            ${fieldInput('title', 'item.field_title', item.title, true)}
            <div class="field">
              <label for="f-series_name">${esc(t('item.field_series'))}</label>
              <input class="input" id="f-series_name" data-field="series_name" value="${esc(item.series_name)}" placeholder="${esc(t('item.series_placeholder'))}" ${isReadOnly ? 'readonly' : ''} />
            </div>
            <div class="field">
              <label for="f-issue_number">${esc(t('item.field_issue_number'))}</label>
              <input class="input" id="f-issue_number" data-field="issue_number" value="${esc(item.issue_number)}" ${isReadOnly ? 'readonly' : ''} />
            </div>
            ${fieldInput('publisher', 'item.field_publisher', item.publisher)}
            <div class="field full">
              <label>${esc(t('item.file_location'))}</label>
              <input class="input" value="${esc(item.absolute_path)}" readonly onclick="this.select()" style="font-family:monospace;font-size:12.5px;" />
            </div>
            <div class="field full">
              <label for="f-tags">${esc(t('item.tags_comma_separated'))}</label>
              <input class="input" id="f-tags" value="${esc((item.tags || []).map((tag) => tag.name).join(', '))}" ${isReadOnly ? 'readonly' : ''} />
            </div>
          </div>
        </div>

        ${typeFields}

        ${
          isReadOnly
            ? ''
            : `<div class="item-actions">
                <button type="submit" class="btn btn-primary">${esc(t('common.save'))}</button>
                <span class="extract-status" id="saveStatus"></span>
              </div>`
        }
      </form>
    `;

    document.getElementById('synopsisExpandBtn').addEventListener('click', () => {
      openSynopsisDialog(item.title, document.getElementById('f-synopsis').value);
    });

    document.getElementById('itemForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      if (isReadOnly) {
        return; // no save button to click here, but a stray Enter keypress in a field shouldn't attempt one either
      }
      const saveStatus = document.getElementById('saveStatus');
      const payload = {};
      document.querySelectorAll('[data-field]').forEach((el) => {
        const key = el.dataset.field;
        const val = el.value.trim();
        if (key === 'series_name') return; // handled below
        if (key === 'issue_number') {
          payload.issue_number = val === '' ? null : Number(val);
          return;
        }
        payload[key] = val === '' ? null : val;
      });
      const seriesName = document.getElementById('f-series_name').value.trim();
      payload.series_name = seriesName; // resolved server-side isn't wired for update; resolve here instead
      const tagsRaw = document.getElementById('f-tags').value;
      payload.tags = tagsRaw.split(',').map((tag) => tag.trim()).filter(Boolean);

      try {
        saveStatus.textContent = t('item.saving');
        const updated = await fetchJson(`/api/items/${itemId}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        saveStatus.textContent = t('item.saved');
        render(updated);
      } catch (err) {
        saveStatus.textContent = t('library.generic_error', { message: err.message });
      }
    });

    setupReaderToolbar(item.id);
    setupFavoriteButton(item.id);
  }

  function setupFavoriteButton(id) {
    const btn = document.getElementById('favoriteBtn');
    if (!btn) return;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const res = await fetchJson(`/api/items/${id}/favorite`, { method: 'POST' });
        btn.classList.toggle('is-favorite', res.is_favorite);
        btn.title = res.is_favorite ? t('item.remove_favorite') : t('item.add_favorite');
        btn.querySelector('svg').setAttribute('fill', res.is_favorite ? 'currentColor' : 'none');
      } catch (err) {
        // a failed toggle just leaves the star as it was — nothing else on
        // the page depends on this succeeding
      } finally {
        btn.disabled = false;
      }
    });
  }

  function openSynopsisDialog(title, synopsis) {
    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.innerHTML = `
      <div class="dialog synopsis-dialog">
        <div class="dialog-title">${esc(title)}</div>
        <div class="dialog-body synopsis-dialog-body">${esc(synopsis || t('item.no_synopsis'))}</div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="synopsisDialogClose">${esc(t('common.close'))}</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.remove(); });
    document.getElementById('synopsisDialogClose').addEventListener('click', () => backdrop.remove());
    document.addEventListener('keydown', function onEsc(e) {
      if (e.key === 'Escape') { backdrop.remove(); document.removeEventListener('keydown', onEsc); }
    });
  }

  async function setupReaderToolbar(id) {
    const readBtn = document.getElementById('readBtn');
    if (!readBtn) {
      return; // EPUB (or any format without reader buttons in the template) — download only, nothing to wire up
    }
    const resetBtn = document.getElementById('resetProgressBtn');
    const markReadBtn = document.getElementById('markReadBtn');
    const progressFill = document.getElementById('readerProgressFill');
    const progressLabel = document.getElementById('readerProgressLabel');

    try {
      const { count } = await fetchJson(`/api/items/${id}/pages`);
      if (count === 0) {
        readBtn.setAttribute('aria-disabled', 'true');
        readBtn.classList.add('reader-btn-disabled');
        readBtn.removeAttribute('href');
        readBtn.title = t('item.read_unavailable');
      }
    } catch {
      // if we can't even tell whether it's readable, leave the button as a normal link — better an attempt that fails than a falsely-disabled button
    }

    async function refreshProgress() {
      let progress;
      try {
        progress = await fetchJson(`/api/items/${id}/progress`);
      } catch {
        return;
      }
      const total = progress.total_pages;
      const current = progress.position !== null ? Number(progress.position) : null;
      const isComplete = !!progress.completed_at;
      const hasProgress = current !== null || isComplete;

      resetBtn.hidden = !hasProgress;
      markReadBtn.classList.toggle('reader-btn-active', isComplete);
      markReadBtn.title = isComplete ? t('item.marked_read_undo') : t('item.mark_read');

      // The bar itself always stays in the layout — even at 0%, reserving
      // its space — so a reset doesn't make the page jump as if a whole
      // row had disappeared.
      if (hasProgress && total) {
        const pct = Math.round(((isComplete ? total - 1 : current) + 1) / total * 100);
        progressFill.style.width = pct + '%';
        progressLabel.textContent = isComplete ? t('item.read_label') : t('item.page_progress', { current: current + 1, total, pct });
      } else {
        progressFill.style.width = '0%';
        progressLabel.textContent = t('item.not_started');
      }
    }

    resetBtn.addEventListener('click', async () => {
      try {
        await fetchJson(`/api/items/${id}/progress`, { method: 'DELETE' });
        await refreshProgress();
      } catch (err) {
        showReaderToast(err.message);
      }
    });

    markReadBtn.addEventListener('click', async () => {
      let currentlyComplete = false;
      try {
        const progress = await fetchJson(`/api/items/${id}/progress`);
        currentlyComplete = !!progress.completed_at;
        await fetchJson(`/api/items/${id}/progress`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ completed: !currentlyComplete }),
        });
        await refreshProgress();
      } catch (err) {
        showReaderToast(err.message);
      }
    });

    refreshProgress();
  }

  function showReaderToast(message) {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
  }

  if (!itemId) {
    page.innerHTML = `<p class="text-muted">${esc(t('item.no_id'))}</p>`;
    return;
  }

  fetchJson(`/api/items/${itemId}`)
    .then(render)
    .catch((err) => {
      page.innerHTML = `<p class="text-muted">${esc(t('item.load_error', { message: err.message }))}</p>`;
    });
})();
