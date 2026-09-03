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
      const message = data.error || `Erreur ${res.status}`;
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
    settings: document.getElementById('panel-settings'),
  };
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => t.classList.remove('active'));
      tab.classList.add('active');
      Object.entries(panels).forEach(([key, panel]) => { panel.hidden = key !== tab.dataset.tab; });
      const key = tab.dataset.tab;
      if (key === 'users') renderUsersTab();
      if (key === 'libraries') renderLibrariesTab();
      if (key === 'settings') renderSettingsTab();
    });
  });

  // ============================================================
  // Users
  // ============================================================
  async function renderUsersTab() {
    const panel = panels.users;
    panel.innerHTML = '<p class="text-muted">Chargement...</p>';
    try {
      const [users, libraries] = await Promise.all([api('GET', '/api/users'), api('GET', '/api/libraries')]);
      panel.innerHTML = `
        <div class="admin-card">
          <h2>Utilisateurs (${users.length})</h2>
          <div class="admin-list" id="userList"></div>
        </div>
        <div class="admin-card">
          <h2>Inviter un utilisateur</h2>
          <form class="admin-form" id="inviteForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="invUsername">Nom d'utilisateur</label>
                <input class="input" id="invUsername" required minlength="3" />
              </div>
              <div class="field">
                <label for="invEmail">Adresse e-mail</label>
                <input class="input" id="invEmail" type="email" required />
              </div>
              <div class="field">
                <label for="invRole">Rôle</label>
                <select class="input" id="invRole">
                  <option value="reader_basic" selected>Utilisateur</option>
                  <option value="reader">Utilisateur avancé</option>
                  <option value="admin">Administrateur</option>
                </select>
              </div>
            </div>
            <div class="field">
              <label>Bibliothèques accessibles</label>
              <div class="lib-checks" id="invLibChecks">
                ${
                  libraries.length
                    ? libraries.map((l) => `<label class="lib-check"><input type="checkbox" value="${l.id}" /> ${esc(l.name)}</label>`).join('')
                    : '<span class="text-muted" style="font-size:13px;">Aucune bibliothèque configurée — onglet "Bibliothèques".</span>'
                }
              </div>
            </div>
            <label class="mfa-force-toggle">
              <input type="checkbox" id="invMfaRequired" />
              Exiger l'authentification à deux facteurs (l'utilisateur devra la configurer, pas de choix)
            </label>
            <div>
              <button type="submit" class="btn btn-primary">Envoyer l'invitation</button>
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
                ${res.emailSent ? '✓ E-mail envoyé.' : `⚠ E-mail non envoyé${res.emailError ? ' (' + esc(res.emailError) + ')' : ''} — copie ce lien manuellement :`}
              </span>
              ${esc(res.inviteUrl)}
            </div>`;
          showToast('Utilisateur invité.');
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">Erreur : ${esc(err.message)}</p>`;
    }
  }

  function openLibraryAccessEditor(user, libraries) {
    const backdrop = document.createElement('div');
    backdrop.className = 'dialog-backdrop';
    backdrop.innerHTML = `
      <div class="dialog">
        <div class="dialog-title">Bibliothèques accessibles — ${esc(user.username)}</div>
        <div class="dialog-body">Par défaut, un lecteur n'a accès à rien. Coche les bibliothèques auxquelles il doit pouvoir accéder.</div>
        <div class="lib-checks">
          ${
            libraries.length
              ? libraries
                  .map(
                    (l) =>
                      `<label class="lib-check"><input type="checkbox" value="${l.id}" ${user.library_ids.includes(l.id) ? 'checked' : ''} /> ${esc(l.name)}</label>`
                  )
                  .join('')
              : '<span class="text-muted" style="font-size:13px;">Aucune bibliothèque configurée.</span>'
          }
        </div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="laCancel">Annuler</button>
          <button type="button" class="btn btn-primary" id="laSave">Enregistrer</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);

    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.remove(); });
    document.getElementById('laCancel').addEventListener('click', () => backdrop.remove());
    document.getElementById('laSave').addEventListener('click', async () => {
      const libraryIds = Array.from(backdrop.querySelectorAll('.lib-checks input:checked')).map((el) => Number(el.value));
      try {
        await api('PUT', `/api/users/${user.id}`, { library_ids: libraryIds });
        showToast('Accès mis à jour.');
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
      list.innerHTML = '<p class="text-muted">Aucun utilisateur.</p>';
      return;
    }
    const ROLE_LABELS = { admin: 'Admin', reader: 'Utilisateur avancé', reader_basic: 'Utilisateur' };

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
          <span class="badge ${u.status === 'active' ? 'badge-active' : 'badge-invited'}">${u.status === 'active' ? 'Actif' : 'Invité'}</span>
          ${u.status === 'active' ? `<span class="badge ${u.mfa_enabled ? 'badge-mfa' : 'badge-nomfa'}">${u.mfa_enabled ? 'MFA activée' : 'MFA désactivée'}</span>` : ''}
          ${u.mfa_required ? `<span class="badge badge-mfa">MFA exigée</span>` : ''}
          ${
            isReaderTier
              ? libNames.length
                ? `<span class="badge badge-reader">${esc(libNames.join(', '))}</span>`
                : `<span class="badge badge-noaccess">Aucun accès</span>`
              : ''
          }
        </div>
        <div class="admin-row-actions">
          ${
            u.id !== currentUserId
              ? `<select class="input" data-change-role="${u.id}" style="width:auto;padding:6px 10px;font-size:12.5px;">
                  ${Object.entries(ROLE_LABELS).map(([val, label]) => `<option value="${val}" ${u.role === val ? 'selected' : ''}>${esc(label)}</option>`).join('')}
                </select>`
              : ''
          }
          ${isReaderTier ? `<button class="btn btn-secondary btn-sm" data-edit-access="${u.id}">Bibliothèques...</button>` : ''}
          <button class="btn btn-secondary btn-sm" data-toggle-mfa="${u.id}" data-current="${u.mfa_required ? '1' : '0'}">${u.mfa_required ? 'Lever l\'exigence MFA' : 'Exiger la MFA'}</button>
          ${u.status === 'invited' ? `<button class="btn btn-secondary btn-sm" data-resend="${u.id}">Renvoyer</button>` : ''}
          ${u.id !== currentUserId ? `<button class="btn btn-danger btn-sm" data-delete-user="${u.id}">Supprimer</button>` : ''}
        </div>
      </div>`;
      })
      .join('');

    list.querySelectorAll('[data-change-role]').forEach((sel) => {
      sel.addEventListener('change', async () => {
        try {
          await api('PUT', `/api/users/${sel.dataset.changeRole}`, { role: sel.value });
          showToast('Rôle mis à jour.');
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
          showToast(nextValue ? 'MFA exigée pour cet utilisateur.' : 'Exigence MFA levée.');
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
          alert((res.emailSent ? 'E-mail renvoyé.' : `E-mail non envoyé (${res.emailError || 'inconnu'}). Lien :`) + '\n' + res.inviteUrl);
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
    list.querySelectorAll('[data-delete-user]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Supprimer cet utilisateur ?')) return;
        try {
          await api('DELETE', `/api/users/${btn.dataset.deleteUser}`);
          showToast('Utilisateur supprimé.');
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
        <div class="dialog-title">Choisir un dossier</div>
        <div class="folder-picker-path" id="fpPath"></div>
        <div class="folder-picker-list" id="fpList"><p class="text-muted">Chargement...</p></div>
        <div class="dialog-actions">
          <button type="button" class="btn btn-secondary" id="fpCancel">Annuler</button>
          <button type="button" class="btn btn-primary" id="fpChoose">Choisir ce dossier</button>
        </div>
      </div>`;
    document.body.appendChild(backdrop);

    let current = startPath || '';

    async function load(path) {
      const pathEl = document.getElementById('fpPath');
      const listEl = document.getElementById('fpList');
      listEl.innerHTML = '<p class="text-muted">Chargement...</p>';
      try {
        const res = await api('GET', `/api/browse-libraries?path=${encodeURIComponent(path)}`);
        current = res.path;
        pathEl.textContent = 'libraries/' + (res.path || '');
        const rows = [];
        if (res.parent !== null) {
          rows.push(`<button type="button" class="folder-picker-item" data-nav="${esc(res.parent)}">.. (dossier parent)</button>`);
        }
        res.entries.forEach((entry) => {
          rows.push(`<button type="button" class="folder-picker-item" data-nav="${esc(entry.path)}">📁 ${esc(entry.name)}</button>`);
        });
        listEl.innerHTML = rows.length ? rows.join('') : '<p class="text-muted">Aucun sous-dossier ici.</p>';
        listEl.querySelectorAll('[data-nav]').forEach((btn) => {
          btn.addEventListener('click', () => load(btn.dataset.nav));
        });
      } catch (err) {
        listEl.innerHTML = `<p class="text-muted">Erreur : ${esc(err.message)}</p>`;
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

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };

  function typeOptionsHtml(selected) {
    return Object.entries(TYPE_LABELS)
      .map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${label}</option>`)
      .join('');
  }

  function formatSyncDate(iso) {
    if (!iso) return 'Jamais synchronisée';
    const d = new Date(iso);
    return 'Synchronisée le ' + d.toLocaleDateString('fr-FR') + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }

  function formatCount(n) {
    return new Intl.NumberFormat('fr-FR').format(n || 0);
  }

  /** Shared with the per-row button and the "Tout" button below it. */
  async function extractMissingForLibrary(libId, box) {
    let totalProcessed = 0;
    while (true) {
      box.innerHTML = `<p class="text-muted" style="font-size:13px;">Extraction en cours... (${totalProcessed} traité(s))</p>`;
      const res = await api('POST', `/api/libraries/${libId}/extract-missing?limit=25`);
      totalProcessed += res.processed;
      if (res.processed === 0 || res.remaining === 0) break;
    }
    box.innerHTML = `<div class="invite-link-box">${totalProcessed} fiche(s) traitée(s). Terminé.</div>`;
    return totalProcessed;
  }

  /** Shared with the per-row button and the "Tout" button below it. */
  async function regenerateCoversForLibrary(libId, box) {
    let offset = 0;
    let total = null;
    while (total === null || offset < total) {
      box.innerHTML = `<p class="text-muted" style="font-size:13px;">Régénération en cours... (${offset}${total !== null ? ` / ${total}` : ''})</p>`;
      const res = await api('POST', `/api/libraries/${libId}/regenerate-covers?limit=25&offset=${offset}`);
      total = res.total;
      offset = res.offset;
      if (res.processed === 0) break; // safety net against an infinite loop if total is somehow never reached
    }
    box.innerHTML = `<div class="invite-link-box">${offset} couverture(s) régénérée(s). Terminé.</div>`;
    return offset;
  }

  async function renderLibrariesTab() {
    const panel = panels.libraries;
    panel.innerHTML = '<p class="text-muted">Chargement...</p>';
    try {
      const libraries = await api('GET', '/api/libraries');
      panel.innerHTML = `
        <div class="admin-card">
          <div class="admin-card-head">
            <h2>Bibliothèques (${libraries.length})</h2>
            <div class="admin-row-actions-group">
              <button class="btn btn-secondary btn-sm" id="syncAllBtn" ${libraries.length ? '' : 'disabled'}>Tout synchroniser</button>
              <button class="btn btn-secondary btn-sm" id="extractAllBtn" ${libraries.length ? '' : 'disabled'} title="Extraire les métadonnées et couvertures manquantes de toutes les bibliothèques, une par une">Tout extraire (métadonnées)</button>
              <button class="btn btn-secondary btn-sm" id="regenerateAllBtn" ${libraries.length ? '' : 'disabled'} title="Re-générer toutes les couvertures de toutes les bibliothèques en miniatures, une par une">Tout régénérer (miniatures)</button>
            </div>
          </div>
          <div class="admin-list" id="libList"></div>
          <div id="syncAllResult"></div>
        </div>
        <div class="admin-card">
          <h2>Ajouter une bibliothèque</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">Le chemin est relatif au dossier <code>libraries/</code> monté par <code>compose.yml</code>.</p>
          <form class="admin-form" id="libForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="libName">Nom</label>
                <input class="input" id="libName" required placeholder="BD Franco-Belge" />
              </div>
              <div class="field">
                <label for="libPath">Chemin (relatif)</label>
                <div class="path-field">
                  <input class="input" id="libPath" required placeholder="bd-franco-belge" />
                  <button type="button" class="btn btn-secondary btn-sm" id="libBrowseBtn">Parcourir...</button>
                </div>
              </div>
              <div class="field">
                <label for="libType">Type de contenu</label>
                <select class="input" id="libType">${typeOptionsHtml('comic')}</select>
              </div>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">Ajouter</button>
            </div>
          </form>
        </div>
      `;
      renderLibList(libraries);

      document.getElementById('libBrowseBtn').addEventListener('click', () => {
        openFolderPicker(document.getElementById('libPath').value.trim(), (chosen) => {
          document.getElementById('libPath').value = chosen;
        });
      });

      document.getElementById('syncAllBtn').addEventListener('click', async () => {
        const box = document.getElementById('syncAllResult');
        box.innerHTML = '<p class="text-muted" style="font-size:13px;">Synchronisation en cours...</p>';
        try {
          const res = await api('POST', '/api/sync-all');
          box.innerHTML = res.libraries
            .map((r) => `<div class="invite-link-box"><strong>${esc(r.library)}</strong> — ${r.added} ajouté(s), ${r.unchanged} inchangé(s), ${r.orphaned.length} orphelin(s)</div>`)
            .join('');
          showToast('Synchronisation terminée.');
          renderLibrariesTab();
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
      });

      document.getElementById('extractAllBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const box = document.getElementById('syncAllResult');
        btn.disabled = true;
        let grandTotal = 0;
        try {
          for (const lib of libraries) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">Extraction en cours — ${esc(lib.name)}...</p>`;
            const libBox = document.getElementById(`sync-result-${lib.id}`) || document.createElement('div');
            grandTotal += await extractMissingForLibrary(lib.id, libBox);
          }
          box.innerHTML = `<div class="invite-link-box">${grandTotal} fiche(s) traitée(s) au total. Terminé.</div>`;
          showToast('Extraction terminée pour toutes les bibliothèques.');
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        } finally {
          btn.disabled = false;
        }
      });

      document.getElementById('regenerateAllBtn').addEventListener('click', async (e) => {
        if (!confirm('Re-générer toutes les couvertures de toutes les bibliothèques en miniatures ? Ça peut prendre un long moment pour une grosse collection.')) return;
        const btn = e.currentTarget;
        const box = document.getElementById('syncAllResult');
        btn.disabled = true;
        let grandTotal = 0;
        try {
          for (const lib of libraries) {
            box.innerHTML = `<p class="text-muted" style="font-size:13px;">Régénération en cours — ${esc(lib.name)}...</p>`;
            const libBox = document.getElementById(`sync-result-${lib.id}`) || document.createElement('div');
            grandTotal += await regenerateCoversForLibrary(lib.id, libBox);
          }
          box.innerHTML = `<div class="invite-link-box">${grandTotal} couverture(s) régénérée(s) au total. Terminé.</div>`;
          showToast('Régénération terminée pour toutes les bibliothèques.');
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
          showToast('Bibliothèque ajoutée.');
          renderLibrariesTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">Erreur : ${esc(err.message)}</p>`;
    }
  }

  function renderLibList(libraries) {
    const list = document.getElementById('libList');
    if (!libraries.length) {
      list.innerHTML = '<p class="text-muted">Aucune bibliothèque.</p>';
      return;
    }
    list.innerHTML = libraries
      .map(
        (l) => `
      <div class="admin-row" id="lib-row-${l.id}">
        <div class="admin-row-main">
          <strong>${esc(l.name)}</strong>
          <span>libraries/${esc(l.path)} — ${formatSyncDate(l.last_synced_at)} — ${formatCount(l.item_count)} objet${l.item_count === 1 ? '' : 's'}</span>
        </div>
        <div class="admin-row-badges">
          <span class="badge badge-reader">${TYPE_LABELS[l.type] || l.type}</span>
        </div>
        <div class="admin-row-actions">
          <div class="admin-row-actions-group">
            <button class="btn btn-secondary btn-sm" data-sync-lib="${l.id}">Synchroniser</button>
            <button class="btn btn-secondary btn-sm" data-extract-missing="${l.id}" title="Extraire les métadonnées et couvertures manquantes">Métadonnées manquantes</button>
            <button class="btn btn-secondary btn-sm" data-regenerate-covers="${l.id}" title="Re-générer toutes les couvertures en miniatures (utile après l'activation de GD)">Régénérer les miniatures</button>
          </div>
          <div class="admin-row-actions-group">
            <button class="btn btn-secondary btn-sm" data-edit-lib="${l.id}">Modifier</button>
            <button class="btn btn-danger btn-sm" data-delete-lib="${l.id}">Supprimer</button>
          </div>
        </div>
        <div class="sync-result" id="sync-result-${l.id}"></div>
      </div>`
      )
      .join('');

    list.querySelectorAll('[data-sync-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const libId = btn.dataset.syncLib;
        const box = document.getElementById(`sync-result-${libId}`);
        btn.disabled = true;
        box.innerHTML = '<p class="text-muted" style="font-size:13px;">Synchronisation en cours...</p>';
        try {
          const res = await api('POST', `/api/libraries/${libId}/sync`);
          renderSyncResult(box, res);
          showToast(`Synchronisation terminée : ${res.added} ajouté(s).`);
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
          showToast(`Extraction terminée : ${total} fiche(s) traitée(s).`);
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
        if (!confirm("Re-générer toutes les couvertures de cette bibliothèque en miniatures ? Ça peut prendre un moment pour une grosse bibliothèque.")) return;
        btn.disabled = true;
        try {
          const total = await regenerateCoversForLibrary(libId, box);
          showToast(`Régénération terminée : ${total} couverture(s).`);
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
                <label>Nom</label>
                <input class="input" id="editLibName-${lib.id}" value="${esc(lib.name)}" required />
              </div>
              <div class="field">
                <label>Chemin (relatif)</label>
                <div class="path-field">
                  <input class="input" id="editLibPath-${lib.id}" value="${esc(lib.path)}" required />
                  <button type="button" class="btn btn-secondary btn-sm" id="editLibBrowse-${lib.id}">Parcourir...</button>
                </div>
              </div>
              <div class="field">
                <label>Type de contenu</label>
                <select class="input" id="editLibType-${lib.id}">${typeOptionsHtml(lib.type)}</select>
              </div>
              <div style="display:flex;gap:6px;">
                <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                <button type="button" class="btn btn-secondary btn-sm" data-cancel-edit="${lib.id}">Annuler</button>
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
            showToast('Bibliothèque mise à jour.');
            renderLibrariesTab();
          } catch (err) {
            showToast(err.message, true);
          }
        });
      });
    });
    list.querySelectorAll('[data-delete-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Supprimer cette bibliothèque ? Les fiches déjà indexées ne seront pas supprimées, mais ne seront plus rattachées à elle.')) return;
        try {
          await api('DELETE', `/api/libraries/${btn.dataset.deleteLib}`);
          showToast('Bibliothèque supprimée.');
          renderLibrariesTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
  }

  function renderSyncResult(box, res) {
    const orphanRows = res.orphaned
      .map(
        (o) => `<div class="orphan-row">
          <span>${esc(o.title)} <span class="text-muted">(${esc(o.path)})</span></span>
          <button class="btn btn-danger btn-sm" data-delete-orphan="${o.id}">Supprimer</button>
        </div>`
      )
      .join('');
    box.innerHTML = `
      <div class="invite-link-box">
        ${res.added} ajouté(s), ${res.unchanged} inchangé(s)${res.orphaned.length ? `, ${res.orphaned.length} fichier(s) introuvable(s) :` : '.'}
        ${orphanRows}
      </div>`;
    box.querySelectorAll('[data-delete-orphan]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        try {
          await api('DELETE', `/api/items/${btn.dataset.deleteOrphan}`);
          btn.closest('.orphan-row').remove();
          showToast('Fiche supprimée.');
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
  }

  // ============================================================
  // Settings (SMTP)
  // ============================================================
  async function renderSettingsTab() {
    const panel = panels.settings;
    panel.innerHTML = '<p class="text-muted">Chargement...</p>';
    try {
      const s = await api('GET', '/api/settings');
      panel.innerHTML = `
        <div class="admin-card">
          <h2>Envoi d'e-mails (SMTP)</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">Nécessaire pour envoyer les liens d'invitation. Utilise un relais existant (Gmail, ton hébergeur, ...) — un conteneur n'a pas de serveur mail local fonctionnel.</p>
          <form class="admin-form" id="smtpForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpHost">Serveur SMTP</label>
                <input class="input" id="smtpHost" value="${esc(s.smtp_host || '')}" placeholder="smtp.gmail.com" />
              </div>
              <div class="field">
                <label for="smtpPort">Port</label>
                <input class="input" id="smtpPort" value="${esc(s.smtp_port || '587')}" />
              </div>
              <div class="field">
                <label for="smtpEncryption">Chiffrement</label>
                <select class="input" id="smtpEncryption">
                  <option value="starttls" ${s.smtp_encryption === 'starttls' ? 'selected' : ''}>STARTTLS</option>
                  <option value="ssl" ${s.smtp_encryption === 'ssl' ? 'selected' : ''}>SSL/TLS implicite</option>
                  <option value="none" ${s.smtp_encryption === 'none' ? 'selected' : ''}>Aucun</option>
                </select>
              </div>
            </div>
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpUsername">Utilisateur SMTP</label>
                <input class="input" id="smtpUsername" value="${esc(s.smtp_username || '')}" />
              </div>
              <div class="field">
                <label for="smtpPassword">Mot de passe SMTP</label>
                <input class="input" id="smtpPassword" type="password" placeholder="${s.smtp_password_set ? '•••••••• (laisser vide pour conserver)' : ''}" />
              </div>
            </div>
            <div class="admin-form-row">
              <div class="field">
                <label for="smtpFromEmail">Adresse d'expédition</label>
                <input class="input" id="smtpFromEmail" type="email" value="${esc(s.smtp_from_email || '')}" placeholder="codex@example.com" />
              </div>
              <div class="field">
                <label for="smtpFromName">Nom d'expéditeur</label>
                <input class="input" id="smtpFromName" value="${esc(s.smtp_from_name || 'Codex')}" />
              </div>
            </div>
            <div class="field">
              <label for="siteUrl">Adresse du site (pour les liens d'invitation)</label>
              <input class="input" id="siteUrl" value="${esc(s.site_url || '')}" />
            </div>
            <div>
              <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
          </form>
        </div>
        <div class="admin-card">
          <h2>Tester l'envoi</h2>
          <form class="admin-form" id="testForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="testEmail">Envoyer un e-mail de test à</label>
                <input class="input" id="testEmail" type="email" required />
              </div>
            </div>
            <div>
              <button type="submit" class="btn btn-secondary">Envoyer le test</button>
            </div>
            <div id="testResult"></div>
          </form>
        </div>
        <div class="admin-card">
          <h2>Miniatures</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            Taille des miniatures affichées dans les grilles — couvertures des objets et vignettes
            <code>folder.jpg</code> des grilles éditeur/collection, sur la page utilisateur comme ici dans
            l'admin. La hauteur suit toujours automatiquement, au ratio 25:36. ${
              s.gd_available
                ? "Ne change que les miniatures générées à partir de maintenant : une nouvelle synchronisation en tient compte automatiquement, mais les couvertures déjà extraites ont besoin d'un clic sur « Régénérer les miniatures » (onglet Bibliothèques) pour être reprises à la nouvelle taille."
                : "GD n'est pas disponible sur ce serveur (voir le conteneur) — les couvertures sont servies à leur résolution d'origine tant que ça n'est pas résolu ; ce réglage sera pris en compte dès que GD sera actif."
            }
          </p>
          <div class="field">
            <label for="thumbnailWidthSlider">Largeur des miniatures — <span id="thumbnailSizeLabel">${s.thumbnail_width} × ${s.thumbnail_height} px</span></label>
            <input id="thumbnailWidthSlider" type="range" min="50" max="300" step="5" value="${esc(s.thumbnail_width)}" style="width:100%;max-width:320px;" />
          </div>
          <div>
            <button type="button" class="btn btn-secondary" id="saveThumbnailSizeBtn" style="margin-top:var(--space-3);">Enregistrer</button>
          </div>
        </div>
        <div class="admin-card">
          <h2>Densité des grilles</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            La page de navigation classique et les grilles éditeur/collection tiennent sur un nombre de
            colonnes fixe, centrées, et paginent au-delà du total défini ci-dessous.
          </p>
          <div class="admin-form-row">
            <div class="field" style="flex:0 0 auto;">
              <label for="gridColumns">Colonnes</label>
              <input class="input" id="gridColumns" type="number" min="1" max="15" value="${esc(s.grid_columns)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="gridPageSize">Objets max par page</label>
              <input class="input" id="gridPageSize" type="number" min="1" max="300" value="${esc(s.grid_page_size)}" style="width:120px;" />
            </div>
            <div style="margin-left:auto;">
              <button type="button" class="btn btn-secondary" id="saveGridDensityBtn">Enregistrer</button>
            </div>
          </div>
        </div>
        <div class="admin-card">
          <h2>Étagères de la page d'accueil</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            Les rangées « Bandes Dessinées récentes », « Ebooks récents »... de la page d'accueil. « Objets
            chargés » définit combien d'objets récents sont récupérés au total (le maximum atteignable en
            faisant défiler) ; « Colonnes visibles » et « Rangées visibles » définissent combien de ces objets
            s'affichent avant qu'il faille faire défiler horizontalement pour voir les suivants.
          </p>
          <div class="admin-form-row">
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfFetchLimit">Objets chargés</label>
              <input class="input" id="homeShelfFetchLimit" type="number" min="40" max="120" value="${esc(s.home_shelf_fetch_limit)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfColumns">Colonnes visibles</label>
              <input class="input" id="homeShelfColumns" type="number" min="1" max="15" value="${esc(s.home_shelf_columns)}" style="width:100px;" />
            </div>
            <div class="field" style="flex:0 0 auto;">
              <label for="homeShelfRows">Rangées visibles</label>
              <input class="input" id="homeShelfRows" type="number" min="1" max="5" value="${esc(s.home_shelf_rows)}" style="width:100px;" />
            </div>
            <div style="margin-left:auto;">
              <button type="button" class="btn btn-secondary" id="saveHomeShelfBtn">Enregistrer</button>
            </div>
          </div>
        </div>
        <div class="admin-card">
          <h2>Navigation par éditeur</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            Pour une bibliothèque rangée en <code>Éditeur/Collection/Tome...</code>, ajoute une flèche à côté
            de l'onglet correspondant. Elle ouvre d'abord les bibliothèques de ce type (une tuile par bibliothèque,
            sautée directement s'il n'y en a qu'une), puis les éditeurs de la bibliothèque choisie, puis ses
            collections — avec, à côté des collections, les tomes posés directement dans le dossier de l'éditeur.
            Le nom de l'éditeur/de la collection est déduit du nom des dossiers ; leur vignette utilise
            <code>folder.jpg</code> si présent dans le dossier (sinon la couverture du premier objet trouvé).
          </p>
          <label class="mfa-force-toggle">
            <input type="checkbox" id="showPublishersToggle" ${s.show_publishers ? 'checked' : ''} />
            Afficher la navigation par éditeur
          </label>
          <label class="mfa-force-toggle">
            <input type="checkbox" id="showEmptyLibrariesNavToggle" ${s.show_empty_libraries_nav ? 'checked' : ''} />
            Afficher aussi les bibliothèques sans structure éditeur/collection dans cette liste
          </label>
        </div>
        <div class="admin-card">
          <h2>Filtre de synchronisation</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            Expression régulière (PCRE) appliquée au nom de chaque fichier/dossier pendant une synchronisation —
            une correspondance veut dire "ignorer, ce n'est pas du contenu". Les fichiers macOS <code>._*</code>
            et <code>.DS_Store</code> sont déjà ignorés automatiquement, pas besoin de les ajouter ici.
            Par défaut, exclut les fichiers d'accompagnement d'Ubooquity (<code>folder.jpg</code>, <code>header.jpg</code>,
            <code>folder.css</code>, <code>folder-info.html</code>).
          </p>
          <form class="admin-form" id="excludeForm">
            <div class="field">
              <label for="excludePattern">Motif d'exclusion</label>
              <input class="input" id="excludePattern" style="font-family:monospace;" value="${esc(s.scan_exclude_pattern)}" />
            </div>
            <div>
              <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
          </form>
          <div class="field" style="margin-top:16px;">
            <label for="excludeTest">Tester un nom de fichier</label>
            <input class="input" id="excludeTest" placeholder="folder.jpg" />
            <p class="text-muted" id="excludeTestResult" style="font-size:13px;margin:8px 0 0;"></p>
          </div>
          <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--color-divider);">
            <button type="button" class="btn btn-secondary btn-sm" id="previewCleanupBtn">Prévisualiser les fiches déjà scannées à tort</button>
            <div id="cleanupResult" style="margin-top:10px;"></div>
          </div>
        </div>
        <div class="admin-card">
          <h2>Synchronisation planifiée</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">
            La synchronisation se déclenche manuellement (onglet Bibliothèques) ou depuis l'extérieur —
            une tâche planifiée sur ta box/NAS (cron) qui appelle ces adresses avec le jeton ci-dessous,
            sans avoir besoin d'une session admin ouverte.
          </p>
          <div class="field">
            <label>Jeton de synchronisation</label>
            <div class="path-field">
              <input class="input" id="syncTokenField" value="${esc(s.sync_token)}" readonly onclick="this.select()" style="font-family:monospace;" />
              <button type="button" class="btn btn-secondary btn-sm" id="regenTokenBtn">Régénérer</button>
            </div>
          </div>
          <div class="field">
            <label>Exemple — tout synchroniser chaque nuit à 3h (crontab de l'hôte)</label>
            <textarea class="input" readonly rows="2" style="font-family:monospace;font-size:12px;" onclick="this.select()">0 3 * * * curl -s -X POST ${esc(s.site_url)}/api/sync-all -H "X-Sync-Token: ${esc(s.sync_token)}"</textarea>
          </div>
        </div>
      `;

      document.getElementById('regenTokenBtn').addEventListener('click', async () => {
        if (!confirm("Régénérer le jeton ? L'ancien cessera immédiatement de fonctionner — pense à mettre à jour ta tâche planifiée.")) return;
        try {
          const res = await api('POST', '/api/settings/regenerate-sync-token');
          document.getElementById('syncTokenField').value = res.sync_token;
          showToast('Jeton régénéré.');
          renderSettingsTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('thumbnailWidthSlider').addEventListener('input', (e) => {
        const w = Number(e.target.value);
        const h = Math.round((w * 36) / 25);
        document.getElementById('thumbnailSizeLabel').textContent = `${w} × ${h} px`;
      });

      document.getElementById('saveThumbnailSizeBtn').addEventListener('click', async () => {
        const width = Number(document.getElementById('thumbnailWidthSlider').value);
        try {
          await api('PUT', '/api/settings', { thumbnail_width: width });
          showToast('Enregistré.');
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('saveGridDensityBtn').addEventListener('click', async () => {
        const gridColumns = Number(document.getElementById('gridColumns').value);
        const gridSize = Number(document.getElementById('gridPageSize').value);
        if (!gridColumns || gridColumns < 1 || gridColumns > 15) {
          showToast('Le nombre de colonnes doit être compris entre 1 et 15.', true);
          return;
        }
        if (!gridSize || gridSize < 1 || gridSize > 300) {
          showToast('Le nombre d\u2019objets par page doit être compris entre 1 et 300.', true);
          return;
        }
        try {
          await api('PUT', '/api/settings', { grid_columns: gridColumns, grid_page_size: gridSize });
          showToast('Enregistré.');
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('saveHomeShelfBtn').addEventListener('click', async () => {
        const fetchLimit = Number(document.getElementById('homeShelfFetchLimit').value);
        const shelfColumns = Number(document.getElementById('homeShelfColumns').value);
        const shelfRows = Number(document.getElementById('homeShelfRows').value);
        if (!fetchLimit || fetchLimit < 40 || fetchLimit > 120) {
          showToast('Le nombre d\u2019objets chargés doit être compris entre 40 et 120.', true);
          return;
        }
        if (!shelfColumns || shelfColumns < 1 || shelfColumns > 15) {
          showToast('Le nombre de colonnes visibles doit être compris entre 1 et 15.', true);
          return;
        }
        if (!shelfRows || shelfRows < 1 || shelfRows > 5) {
          showToast('Le nombre de rangées visibles doit être compris entre 1 et 5.', true);
          return;
        }
        try {
          await api('PUT', '/api/settings', {
            home_shelf_fetch_limit: fetchLimit,
            home_shelf_columns: shelfColumns,
            home_shelf_rows: shelfRows,
          });
          showToast('Enregistré.');
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('showPublishersToggle').addEventListener('change', async (e) => {
        try {
          await api('PUT', '/api/settings', { show_publishers: e.target.checked });
          showToast('Enregistré.');
        } catch (err) {
          e.target.checked = !e.target.checked;
          showToast(err.message, true);
        }
      });
      document.getElementById('showEmptyLibrariesNavToggle').addEventListener('change', async (e) => {
        try {
          await api('PUT', '/api/settings', { show_empty_libraries_nav: e.target.checked });
          showToast('Enregistré.');
        } catch (err) {
          e.target.checked = !e.target.checked;
          showToast(err.message, true);
        }
      });

      document.getElementById('excludeForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('PUT', '/api/settings', { scan_exclude_pattern: document.getElementById('excludePattern').value });
          showToast('Motif enregistré.');
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
            resultEl.textContent = res.matches ? '✓ Correspond — serait ignoré au scan.' : '✗ Ne correspond pas — serait indexé normalement.';
            resultEl.style.color = res.matches ? '#7be3ab' : '';
          } catch (err) {
            resultEl.textContent = 'Erreur : ' + err.message;
          }
        }, 300);
      });

      document.getElementById('previewCleanupBtn').addEventListener('click', async () => {
        const box = document.getElementById('cleanupResult');
        box.innerHTML = '<p class="text-muted" style="font-size:13px;">Recherche...</p>';
        try {
          const res = await api('GET', '/api/cleanup-excluded');
          if (!res.matches.length) {
            box.innerHTML = '<p class="text-muted" style="font-size:13px;">Aucune fiche ne correspond au motif actuel.</p>';
            return;
          }
          box.innerHTML = `
            <p style="font-size:13px;">${res.matches.length} fiche(s) correspondent au motif actuel :</p>
            <ul style="font-size:12.5px;color:var(--color-text);opacity:0.8;max-height:160px;overflow-y:auto;margin:8px 0;padding-left:18px;">
              ${res.matches.map((m) => `<li>${esc(m.title)} <span class="text-muted">(${esc(m.path)})</span></li>`).join('')}
            </ul>
            <button type="button" class="btn btn-danger btn-sm" id="confirmCleanupBtn">Supprimer ces ${res.matches.length} fiche(s)</button>
          `;
          document.getElementById('confirmCleanupBtn').addEventListener('click', async () => {
            if (!confirm(`Supprimer définitivement ces ${res.matches.length} fiche(s) ?`)) return;
            try {
              const delRes = await api('POST', '/api/cleanup-excluded');
              box.innerHTML = `<div class="invite-link-box">${delRes.deleted} fiche(s) supprimée(s).</div>`;
              showToast('Nettoyage effectué.');
            } catch (err) {
              showToast(err.message, true);
            }
          });
        } catch (err) {
          box.innerHTML = '';
          showToast(err.message, true);
        }
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
          });
          showToast('Réglages enregistrés.');
        } catch (err) {
          showToast(err.message, true);
        }
      });

      document.getElementById('testForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const box = document.getElementById('testResult');
        box.innerHTML = '<p class="text-muted" style="font-size:13px;">Envoi...</p>';
        try {
          const res = await api('POST', '/api/settings/test-email', { to: document.getElementById('testEmail').value.trim() });
          box.innerHTML = `<div class="invite-link-box"><span class="status ${res.sent ? 'status-ok' : 'status-fail'}">${res.sent ? '✓ Envoyé.' : '⚠ ' + esc(res.error || 'Échec')}</span></div>`;
        } catch (err) {
          box.innerHTML = `<div class="invite-link-box"><span class="status status-fail">⚠ ${esc(err.message)}</span></div>`;
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">Erreur : ${esc(err.message)}</p>`;
    }
  }

  renderUsersTab();
})();
