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
    if (!res.ok) throw new Error(data.error || `Erreur ${res.status}`);
    return data;
  }

  const currentUserId = Number(document.querySelector('meta[name="current-user-id"]').content);

  // ---------- tabs ----------
  const tabs = document.querySelectorAll('.admin-tab');
  const panels = {
    users: document.getElementById('panel-users'),
    libraries: document.getElementById('panel-libraries'),
    settings: document.getElementById('panel-settings'),
  };
  const loaded = {};
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => t.classList.remove('active'));
      tab.classList.add('active');
      Object.entries(panels).forEach(([key, panel]) => { panel.hidden = key !== tab.dataset.tab; });
      const key = tab.dataset.tab;
      if (loaded[key]) return;
      loaded[key] = true;
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
                  <option value="reader">Lecteur</option>
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
          loaded.users = false;
          renderUsersTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    } catch (err) {
      panel.innerHTML = `<p class="text-muted">Erreur : ${esc(err.message)}</p>`;
    }
  }

  function renderUserList(users, libraries) {
    const list = document.getElementById('userList');
    if (!users.length) {
      list.innerHTML = '<p class="text-muted">Aucun utilisateur.</p>';
      return;
    }
    list.innerHTML = users
      .map((u) => {
        const libNames = u.library_ids.map((id) => libraries.find((l) => l.id === id)?.name).filter(Boolean);
        return `
      <div class="admin-row">
        <div class="admin-row-main">
          <strong>${esc(u.username)}</strong>
          <span>${esc(u.email || '')}</span>
        </div>
        <div class="admin-row-badges">
          <span class="badge ${u.role === 'admin' ? 'badge-admin' : 'badge-reader'}">${u.role === 'admin' ? 'Admin' : 'Lecteur'}</span>
          <span class="badge ${u.status === 'active' ? 'badge-active' : 'badge-invited'}">${u.status === 'active' ? 'Actif' : 'Invité'}</span>
          ${u.status === 'active' ? `<span class="badge ${u.mfa_enabled ? 'badge-mfa' : 'badge-nomfa'}">${u.mfa_enabled ? 'MFA activée' : 'MFA désactivée'}</span>` : ''}
          ${u.mfa_required ? `<span class="badge badge-mfa">MFA exigée</span>` : ''}
          ${libNames.length ? `<span class="badge badge-reader">${esc(libNames.join(', '))}</span>` : ''}
        </div>
        <div class="admin-row-actions">
          <button class="btn btn-secondary btn-sm" data-toggle-mfa="${u.id}" data-current="${u.mfa_required ? '1' : '0'}">${u.mfa_required ? 'Lever l\'exigence MFA' : 'Exiger la MFA'}</button>
          ${u.status === 'invited' ? `<button class="btn btn-secondary btn-sm" data-resend="${u.id}">Renvoyer</button>` : ''}
          ${u.id !== currentUserId ? `<button class="btn btn-danger btn-sm" data-delete-user="${u.id}">Supprimer</button>` : ''}
        </div>
      </div>`;
      })
      .join('');

    list.querySelectorAll('[data-toggle-mfa]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const nextValue = btn.dataset.current !== '1';
        try {
          await api('PUT', `/api/users/${btn.dataset.toggleMfa}`, { mfa_required: nextValue });
          showToast(nextValue ? 'MFA exigée pour cet utilisateur.' : 'Exigence MFA levée.');
          loaded.users = false;
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
          loaded.users = false;
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
  async function renderLibrariesTab() {
    const panel = panels.libraries;
    panel.innerHTML = '<p class="text-muted">Chargement...</p>';
    try {
      const libraries = await api('GET', '/api/libraries');
      panel.innerHTML = `
        <div class="admin-card">
          <h2>Bibliothèques (${libraries.length})</h2>
          <div class="admin-list" id="libList"></div>
        </div>
        <div class="admin-card">
          <h2>Ajouter une bibliothèque</h2>
          <p class="text-muted" style="font-size:13px;margin-top:-6px;">Le chemin est relatif au dossier <code>libraries/</code> monté par <code>compose.yml</code> — ex. <code>comics</code>, <code>bd-franco-belge</code>, <code>fumetti</code>, <code>livres</code>, <code>magazines</code>.</p>
          <form class="admin-form" id="libForm">
            <div class="admin-form-row">
              <div class="field">
                <label for="libName">Nom</label>
                <input class="input" id="libName" required placeholder="BD Franco-Belge" />
              </div>
              <div class="field">
                <label for="libPath">Chemin (relatif)</label>
                <input class="input" id="libPath" required placeholder="bd-franco-belge" />
              </div>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">Ajouter</button>
            </div>
          </form>
        </div>
      `;
      renderLibList(libraries);
      document.getElementById('libForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('POST', '/api/libraries', {
            name: document.getElementById('libName').value.trim(),
            path: document.getElementById('libPath').value.trim(),
          });
          showToast('Bibliothèque ajoutée.');
          loaded.libraries = false;
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
      <div class="admin-row">
        <div class="admin-row-main">
          <strong>${esc(l.name)}</strong>
          <span>libraries/${esc(l.path)}</span>
        </div>
        <div class="admin-row-actions">
          <button class="btn btn-secondary btn-sm" data-edit-lib="${l.id}">Modifier</button>
          <button class="btn btn-danger btn-sm" data-delete-lib="${l.id}">Supprimer</button>
        </div>
      </div>`
      )
      .join('');

    list.querySelectorAll('[data-edit-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const lib = libraries.find((l) => l.id === Number(btn.dataset.editLib));
        const name = prompt('Nom de la bibliothèque :', lib.name);
        if (name === null) return;
        const path = prompt('Chemin relatif :', lib.path);
        if (path === null) return;
        try {
          await api('PUT', `/api/libraries/${lib.id}`, { name, path });
          showToast('Bibliothèque mise à jour.');
          loaded.libraries = false;
          renderLibrariesTab();
        } catch (err) {
          showToast(err.message, true);
        }
      });
    });
    list.querySelectorAll('[data-delete-lib]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Supprimer cette bibliothèque ? Les fiches déjà indexées ne seront pas supprimées, mais ne seront plus rattachées à elle.')) return;
        try {
          await api('DELETE', `/api/libraries/${btn.dataset.deleteLib}`);
          showToast('Bibliothèque supprimée.');
          loaded.libraries = false;
          renderLibrariesTab();
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
      `;

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
  loaded.users = true;
})();
