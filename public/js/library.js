(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };

  const state = {
    type: '',
    library_id: null,
    series_id: null,
    tag_id: null,
    q: '',
    sort: 'title',
    dir: 'ASC',
  };

  let libraries = [];
  let series = [];
  let tags = [];

  const grid = document.getElementById('itemGrid');
  const emptyState = document.getElementById('emptyState');
  const resultCount = document.getElementById('resultCount');
  const activeFilters = document.getElementById('activeFilters');
  const libraryList = document.getElementById('libraryList');
  const seriesList = document.getElementById('seriesList');
  const tagList = document.getElementById('tagList');
  const searchInput = document.getElementById('searchInput');
  const sortSelect = document.getElementById('sortSelect');

  async function fetchJson(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`${url} → HTTP ${res.status}`);
    return res.json();
  }

  function renderSideList(el, entries, key, activeId, emptyLabel) {
    if (!entries.length) {
      el.innerHTML = `<li class="side-empty">${esc(emptyLabel)}</li>`;
      return;
    }
    el.innerHTML = entries
      .map(
        (e) => `
      <li>
        <a href="#" data-filter-key="${esc(key)}" data-filter-value="${esc(e.id)}" class="${e.id === activeId ? 'active' : ''}">
          <span>${esc(e.name)}</span>
        </a>
      </li>`
      )
      .join('');
  }

  function renderActiveFilters() {
    const chips = [];
    if (state.library_id) {
      const l = libraries.find((x) => x.id === state.library_id);
      if (l) chips.push({ key: 'library_id', label: `Bibliothèque : ${l.name}` });
    }
    if (state.series_id) {
      const s = series.find((x) => x.id === state.series_id);
      if (s) chips.push({ key: 'series_id', label: `Série : ${s.name}` });
    }
    if (state.tag_id) {
      const t = tags.find((x) => x.id === state.tag_id);
      if (t) chips.push({ key: 'tag_id', label: `Tag : ${t.name}` });
    }
    activeFilters.innerHTML = chips
      .map(
        (c) => `<span class="tag tag-accent" data-clear="${esc(c.key)}">${esc(c.label)} <span class="x">✕</span></span>`
      )
      .join('');
    activeFilters.querySelectorAll('[data-clear]').forEach((el) => {
      el.addEventListener('click', () => {
        state[el.dataset.clear] = null;
        loadItems();
        syncSidebarActiveStates();
      });
    });
  }

  function syncSidebarActiveStates() {
    renderSideList(libraryList, libraries, 'library_id', state.library_id, 'Aucune bibliothèque indexée');
    renderSideList(seriesList, series, 'series_id', state.series_id, 'Aucune série');
    renderSideList(tagList, tags, 'tag_id', state.tag_id, 'Aucun tag');
    bindSidebarClicks();
    renderActiveFilters();
  }

  function bindSidebarClicks() {
    document.querySelectorAll('[data-filter-key]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        const key = el.dataset.filterKey;
        const value = Number(el.dataset.filterValue);
        state[key] = state[key] === value ? null : value;
        loadItems();
        syncSidebarActiveStates();
      });
    });
  }

  function coverMarkup(item) {
    if (item.cover_path) {
      return `<img src="${esc(item.cover_path)}" alt="" loading="lazy" />`;
    }
    return `<div class="fallback">${esc(item.title)}</div>`;
  }

  function metaLine(item) {
    if (item.type === 'comic') {
      const parts = [];
      if (item.series_name) parts.push(item.issue_number ? `${item.series_name} #${item.issue_number}` : item.series_name);
      return esc(parts.join(' · ') || item.publisher || '');
    }
    if (item.type === 'ebook') {
      return esc(item.publisher || '');
    }
    if (item.type === 'magazine') {
      return esc(item.publisher || '');
    }
    return '';
  }

  function renderGrid(items) {
    grid.innerHTML = items
      .map(
        (item) => `
      <a class="item-card" href="item.php?id=${esc(item.id)}">
        <div class="item-cover">
          <span class="type-chip">${esc(TYPE_LABELS[item.type] || item.type)}</span>
          ${coverMarkup(item)}
        </div>
        <div class="item-title">${esc(item.title)}</div>
        <div class="item-meta">${metaLine(item)}</div>
      </a>`
      )
      .join('');
  }

  async function loadItems() {
    const params = new URLSearchParams();
    if (state.type) params.set('type', state.type);
    if (state.library_id) params.set('library_id', state.library_id);
    if (state.series_id) params.set('series_id', state.series_id);
    if (state.tag_id) params.set('tag_id', state.tag_id);
    if (state.q) params.set('q', state.q);
    params.set('sort', state.sort);
    params.set('dir', state.dir);
    params.set('limit', '120');

    try {
      const data = await fetchJson(`/api/items?${params.toString()}`);
      renderGrid(data.items);
      resultCount.textContent = `${data.total} résultat${data.total === 1 ? '' : 's'}`;
      emptyState.hidden = data.items.length !== 0;
      grid.hidden = data.items.length === 0;
    } catch (err) {
      grid.innerHTML = '';
      resultCount.textContent = '';
      emptyState.hidden = false;
      emptyState.textContent = `Impossible de charger la bibliothèque (${err.message}).`;
    }
  }

  document.querySelectorAll('#typeTabs input[name="type"]').forEach((input) => {
    input.addEventListener('change', () => {
      state.type = input.value;
      loadItems();
    });
  });

  let searchTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.q = searchInput.value.trim();
      loadItems();
    }, 250);
  });

  sortSelect.addEventListener('change', () => {
    const [sort, dir] = sortSelect.value.split(':');
    state.sort = sort;
    state.dir = dir;
    loadItems();
  });

  Promise.all([
    fetchJson('/api/libraries').catch(() => []),
    fetchJson('/api/series').catch(() => []),
    fetchJson('/api/tags').catch(() => []),
  ]).then(([libs, ser, tg]) => {
    libraries = libs;
    series = ser;
    tags = tg;
    syncSidebarActiveStates();
  });

  loadItems();
})();
