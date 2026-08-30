(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };

  const COMIC_CONTRIBUTOR_FIELDS = [
    ['writer', 'Scénariste'],
    ['penciller', 'Dessinateur'],
    ['inker', 'Encreur'],
    ['colorist', 'Coloriste'],
    ['letterer', 'Lettreur'],
    ['cover_artist', 'Couverture'],
    ['editor', 'Éditeur (rédaction)'],
  ];
  const COMIC_CLASSIFICATION_FIELDS = [
    ['genre', 'Genre'],
    ['characters', 'Personnages'],
    ['age_rating', 'Classification d\'âge'],
  ];
  const EBOOK_FIELDS = [
    ['author', 'Auteur'],
    ['isbn', 'ISBN'],
    ['language', 'Langue'],
  ];
  const MAGAZINE_FIELDS = [
    ['issue_date', 'Date de parution'],
    ['frequency', 'Périodicité'],
  ];

  const page = document.getElementById('itemPage');
  const params = new URLSearchParams(window.location.search);
  const itemId = params.get('id');

  async function fetchJson(url, options) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  function fieldInput(name, label, value, full) {
    return `
      <div class="field${full ? ' full' : ''}">
        <label for="f-${name}">${esc(label)}</label>
        <input class="input" id="f-${name}" data-field="${esc(name)}" value="${esc(value)}" />
      </div>`;
  }

  function render(item) {
    const typeLabel = TYPE_LABELS[item.type] || item.type;
    const kickerParts = [typeLabel];
    if (item.series_name) kickerParts.push(item.issue_number ? `${item.series_name} #${item.issue_number}` : item.series_name);
    if (item.publisher) kickerParts.push(item.publisher);

    const details = item.details || {};
    let typeFields = '';
    if (item.type === 'comic') {
      typeFields = `
        <div class="form-section">
          <h3>Contributeurs</h3>
          <div class="form-grid">
            ${COMIC_CONTRIBUTOR_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>
        <div class="form-section">
          <h3>Classification</h3>
          <div class="form-grid">
            ${COMIC_CLASSIFICATION_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    } else if (item.type === 'ebook') {
      typeFields = `
        <div class="form-section">
          <h3>Détails ebook</h3>
          <div class="form-grid">
            ${EBOOK_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    } else if (item.type === 'magazine') {
      typeFields = `
        <div class="form-section">
          <h3>Détails magazine</h3>
          <div class="form-grid">
            ${MAGAZINE_FIELDS.map(([k, l]) => fieldInput(k, l, details[k])).join('')}
          </div>
        </div>`;
    }

    page.innerHTML = `
      <div class="item-head">
        <div class="item-cover-lg blueprint">
          <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
          ${item.cover_path ? `<img src="${esc(item.cover_path)}" alt="" />` : `<div class="fallback">${esc(item.title)}</div>`}
        </div>
        <div class="item-head-info">
          <p class="kicker">${esc(kickerParts.join(' · '))}</p>
          <h1>${esc(item.title)}</h1>
          <p class="text-muted" id="synopsisDisplay">${esc(item.synopsis || '')}</p>
          <div class="item-tags-display" id="tagsDisplay">
            ${(item.tags || []).map((t) => `<span class="tag tag-accent">${esc(t.name)}</span>`).join('')}
          </div>
        </div>
      </div>

      ${
        item.type === 'comic'
          ? `<div class="extract-row">
              <button type="button" class="btn btn-secondary" id="extractBtn">Extraire les métadonnées du fichier .cbz</button>
              <span class="extract-status" id="extractStatus"></span>
            </div>`
          : ''
      }

      <form id="itemForm">
        <div class="form-section">
          <h3>Général</h3>
          <div class="form-grid">
            ${fieldInput('title', 'Titre', item.title, true)}
            <div class="field">
              <label for="f-series_name">Série</label>
              <input class="input" id="f-series_name" data-field="series_name" value="${esc(item.series_name)}" placeholder="Laisser vide si aucune" />
            </div>
            <div class="field">
              <label for="f-issue_number">N° dans la série</label>
              <input class="input" id="f-issue_number" data-field="issue_number" value="${esc(item.issue_number)}" />
            </div>
            ${fieldInput('publisher', 'Éditeur', item.publisher)}
            <div class="field full">
              <label for="f-synopsis">Résumé</label>
              <textarea class="input" id="f-synopsis" data-field="synopsis" rows="4">${esc(item.synopsis)}</textarea>
            </div>
            <div class="field full">
              <label for="f-tags">Tags (séparés par des virgules)</label>
              <input class="input" id="f-tags" value="${esc((item.tags || []).map((t) => t.name).join(', '))}" />
            </div>
          </div>
        </div>

        ${typeFields}

        <div class="item-actions">
          <button type="submit" class="btn btn-primary">Enregistrer</button>
          <span class="extract-status" id="saveStatus"></span>
        </div>
      </form>
    `;

    const extractBtn = document.getElementById('extractBtn');
    if (extractBtn) {
      extractBtn.addEventListener('click', async () => {
        const status = document.getElementById('extractStatus');
        extractBtn.disabled = true;
        status.textContent = 'Lecture du fichier...';
        try {
          const res = await fetchJson(`/api/items/${itemId}/extract-metadata`, { method: 'POST' });
          if (res.extracted) {
            status.textContent = 'Métadonnées trouvées et appliquées.';
            render(res.item);
          } else {
            status.textContent = 'Aucun ComicInfo.xml trouvé dans ce fichier — saisie manuelle.';
            extractBtn.disabled = false;
          }
        } catch (err) {
          status.textContent = `Erreur : ${err.message}`;
          extractBtn.disabled = false;
        }
      });
    }

    document.getElementById('itemForm').addEventListener('submit', async (e) => {
      e.preventDefault();
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
      payload.tags = tagsRaw.split(',').map((t) => t.trim()).filter(Boolean);

      try {
        saveStatus.textContent = 'Enregistrement...';
        const updated = await fetchJson(`/api/items/${itemId}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        saveStatus.textContent = 'Enregistré.';
        render(updated);
      } catch (err) {
        saveStatus.textContent = `Erreur : ${err.message}`;
      }
    });
  }

  if (!itemId) {
    page.innerHTML = '<p class="text-muted">Aucun identifiant fourni.</p>';
    return;
  }

  fetchJson(`/api/items/${itemId}`)
    .then(render)
    .catch((err) => {
      page.innerHTML = `<p class="text-muted">Impossible de charger cette fiche (${esc(err.message)}).</p>`;
    });
})();
