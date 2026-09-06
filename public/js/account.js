/**
 * The account popup opened from the topbar's username button — email
 * change (reader pages only, via data-show-email on the trigger),
 * password change, and logout. Shared between library.php and
 * admin.php rather than duplicated, since the two only differ in
 * whether the email section is shown at all.
 *
 * Every change here is two-step from the server's own perspective
 * (AccountManager.php): a request, then a confirmation code — except a
 * password change for an MFA-enrolled account, which is one step since
 * a live TOTP code is already proof enough on its own. This file just
 * reflects whichever state the server reports back on each fetch,
 * rather than tracking its own "which step are we on" — reopening the
 * popup after navigating away mid-flow picks up exactly where it left
 * off, since that state lives in the database, not in this script.
 */
(function () {
  async function api(method, url, body) {
    const res = await fetch(url, {
      method,
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(data.error || `Erreur ${res.status}`);
    }
    return data;
  }

  // A deliberately simple check — not RFC 5322, just enough to catch an
  // obviously-incomplete address before bothering the server with it
  // (which does the real validation via filter_var(FILTER_VALIDATE_EMAIL)
  // anyway). Good enough to gate the button, not meant to be exhaustive.
  const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  function passwordFieldHtml(id, label) {
    return `
      <div class="account-field">
        <label for="${id}">${label}</label>
        <div class="account-password-row">
          <input class="input" id="${id}" type="password" minlength="12" autocomplete="new-password" required />
          <button type="button" class="account-password-toggle" data-toggle-for="${id}">Afficher</button>
        </div>
      </div>`;
  }

  function bindPasswordToggle(root) {
    root.querySelectorAll('[data-toggle-for]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = root.querySelector(`#${btn.dataset.toggleFor}`);
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.textContent = showing ? 'Afficher' : 'Cacher';
      });
    });
  }

  function formatExpiry(iso) {
    const d = new Date(iso);
    return d.toLocaleDateString('fr-FR') + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  }

  function initAccountMenu(trigger) {
    const showEmail = trigger.dataset.showEmail === '1';

    // Built fresh on each open() and removed entirely on close(), rather
    // than created once and toggled via the hidden attribute — matching
    // how every other dialog in this app already does it (admin.js,
    // item.js). Toggling `hidden` fought a real CSS bug: .dialog-backdrop's
    // own `display: grid` and the browser default `[hidden] { display:
    // none }` are equal specificity, and the later one (this app's own
    // stylesheet) wins the tie — so `hidden` was silently never actually
    // hiding anything, and the popup appeared open on every page load.
    let backdrop = null;
    let emailSection = null;
    let passwordSection = null;

    function close() {
      if (backdrop) {
        backdrop.remove();
        backdrop = null;
      }
    }

    async function open() {
      backdrop = document.createElement('div');
      backdrop.className = 'dialog-backdrop';
      backdrop.innerHTML = `
        <div class="dialog account-dialog" role="dialog" aria-modal="true" aria-label="Mon compte">
          <div class="dialog-title">Mon compte</div>
          ${showEmail ? '<div class="account-section" id="accountEmailSection"></div>' : ''}
          <div class="account-section" id="accountPasswordSection"></div>
          <div class="account-section">
            <a class="btn btn-secondary" href="logout.php">Se déconnecter</a>
          </div>
          <div class="dialog-actions">
            <button type="button" class="btn btn-ghost" id="accountCloseBtn">Fermer</button>
          </div>
        </div>`;
      document.body.appendChild(backdrop);

      emailSection = backdrop.querySelector('#accountEmailSection');
      passwordSection = backdrop.querySelector('#accountPasswordSection');

      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) close();
      });
      backdrop.querySelector('#accountCloseBtn').addEventListener('click', close);

      if (emailSection) emailSection.innerHTML = '<p class="text-muted">Chargement...</p>';
      passwordSection.innerHTML = '<p class="text-muted">Chargement...</p>';
      try {
        const status = await api('GET', '/api/account');
        if (emailSection) renderEmail(status);
        renderPassword(status);
      } catch (err) {
        if (emailSection) emailSection.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        passwordSection.innerHTML = '';
      }
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && backdrop) close();
    });

    // ---------- email ----------

    function renderEmail(status) {
      if (status.pending_email) {
        emailSection.innerHTML = `
          <h3>Adresse e-mail</h3>
          <div class="account-pending-banner">
            En attente de validation pour <strong>${esc(status.pending_email)}</strong> — expire le ${formatExpiry(status.pending_email_expires)}.
            Tant que ce n'est pas confirmé, <strong>${esc(status.email || '(aucune adresse actuelle)')}</strong> reste l'adresse active.
          </div>
          <div class="account-field">
            <label for="emailCode">Code reçu par e-mail</label>
            <input class="input account-code-input" id="emailCode" inputmode="numeric" maxlength="6" placeholder="000000" />
          </div>
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-primary" id="emailConfirmBtn">Valider</button>
            <button type="button" class="btn btn-ghost" id="emailCancelBtn">Annuler la demande</button>
          </div>
          <div id="emailResult"></div>`;

        backdrop.querySelector('#emailConfirmBtn').addEventListener('click', async () => {
          const resultEl = backdrop.querySelector('#emailResult');
          const code = backdrop.querySelector('#emailCode').value.trim();
          if (!code) return;
          try {
            await api('POST', '/api/account-email-confirm', { code });
            resultEl.innerHTML = '<p class="account-success">Adresse confirmée.</p>';
            const fresh = await api('GET', '/api/account');
            renderEmail(fresh);
          } catch (err) {
            resultEl.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
          }
        });
        backdrop.querySelector('#emailCancelBtn').addEventListener('click', async () => {
          try {
            await api('POST', '/api/account-email-cancel');
            const fresh = await api('GET', '/api/account');
            renderEmail(fresh);
          } catch (err) {
            backdrop.querySelector('#emailResult').innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
          }
        });
        return;
      }

      emailSection.innerHTML = `
        <h3>Adresse e-mail</h3>
        <p class="text-muted" style="font-size:13px;margin-top:-4px;">Actuelle : ${esc(status.email || '(aucune)')}</p>
        <div class="account-field">
          <label for="newEmail">Nouvelle adresse</label>
          <input class="input" id="newEmail" type="email" required />
        </div>
        <button type="button" class="btn btn-primary" id="emailRequestBtn" disabled>Envoyer un code</button>
        <div id="emailResult"></div>`;

      const newEmailInput = backdrop.querySelector('#newEmail');
      const emailRequestBtn = backdrop.querySelector('#emailRequestBtn');
      newEmailInput.addEventListener('input', () => {
        emailRequestBtn.disabled = !EMAIL_PATTERN.test(newEmailInput.value.trim());
      });

      emailRequestBtn.addEventListener('click', async () => {
        const resultEl = backdrop.querySelector('#emailResult');
        const email = newEmailInput.value.trim();
        if (!EMAIL_PATTERN.test(email)) return;
        resultEl.innerHTML = '<p class="text-muted">Envoi...</p>';
        try {
          await api('POST', '/api/account-email-request', { email });
          const fresh = await api('GET', '/api/account');
          renderEmail(fresh);
        } catch (err) {
          resultEl.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        }
      });
    }

    // ---------- password ----------

    function renderPassword(status) {
      if (status.mfa_enabled) {
        renderPasswordMfaForm();
        return;
      }
      if (status.pending_password) {
        renderPasswordPendingConfirm(status);
        return;
      }
      renderPasswordRequestForm();
    }

    function renderPasswordRequestForm() {
      passwordSection.innerHTML = `
        <h3>Mot de passe</h3>
        <p class="text-muted" style="font-size:13px;margin-top:-4px;">
          Un code de confirmation sera envoyé à ton adresse actuelle — le mot de passe actuel reste valide tant
          qu'il n'est pas entré.
        </p>
        ${passwordFieldHtml('newPassword1', 'Nouveau mot de passe (12 caractères minimum)')}
        ${passwordFieldHtml('newPassword2', 'Confirmer le nouveau mot de passe')}
        <button type="button" class="btn btn-primary" id="passwordRequestBtn">Envoyer un code</button>
        <div id="passwordResult"></div>`;
      bindPasswordToggle(passwordSection);

      passwordSection.querySelector('#passwordRequestBtn').addEventListener('click', async () => {
        const resultEl = passwordSection.querySelector('#passwordResult');
        const p1 = passwordSection.querySelector('#newPassword1').value;
        const p2 = passwordSection.querySelector('#newPassword2').value;
        if (p1.length < 12) {
          resultEl.innerHTML = '<p class="account-error">12 caractères minimum.</p>';
          return;
        }
        if (p1 !== p2) {
          resultEl.innerHTML = '<p class="account-error">Les deux mots de passe ne correspondent pas.</p>';
          return;
        }
        resultEl.innerHTML = '<p class="text-muted">Envoi...</p>';
        try {
          await api('POST', '/api/account-password-request', { new_password: p1 });
          const fresh = await api('GET', '/api/account');
          renderPassword(fresh);
        } catch (err) {
          resultEl.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        }
      });
    }

    function renderPasswordPendingConfirm(status) {
      passwordSection.innerHTML = `
        <h3>Mot de passe</h3>
        <div class="account-pending-banner">
          Un code a été envoyé à ton adresse actuelle — expire le ${formatExpiry(status.pending_password_expires)}.
          Le mot de passe actuel reste valide tant que ce n'est pas confirmé.
        </div>
        <div class="account-field">
          <label for="passwordCode">Code reçu par e-mail</label>
          <input class="input account-code-input" id="passwordCode" inputmode="numeric" maxlength="6" placeholder="000000" />
        </div>
        <div style="display:flex;gap:8px;">
          <button type="button" class="btn btn-primary" id="passwordConfirmBtn">Valider</button>
          <button type="button" class="btn btn-ghost" id="passwordCancelBtn">Annuler la demande</button>
        </div>
        <div id="passwordResult"></div>`;

      passwordSection.querySelector('#passwordConfirmBtn').addEventListener('click', async () => {
        const resultEl = passwordSection.querySelector('#passwordResult');
        const code = passwordSection.querySelector('#passwordCode').value.trim();
        if (!code) return;
        try {
          await api('POST', '/api/account-password-confirm', { code });
          resultEl.innerHTML = '<p class="account-success">Mot de passe changé.</p>';
          const fresh = await api('GET', '/api/account');
          renderPassword(fresh);
        } catch (err) {
          resultEl.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        }
      });
      passwordSection.querySelector('#passwordCancelBtn').addEventListener('click', async () => {
        try {
          await api('POST', '/api/account-password-cancel');
          const fresh = await api('GET', '/api/account');
          renderPassword(fresh);
        } catch (err) {
          passwordSection.querySelector('#passwordResult').innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        }
      });
    }

    function renderPasswordMfaForm() {
      passwordSection.innerHTML = `
        <h3>Mot de passe</h3>
        <p class="text-muted" style="font-size:13px;margin-top:-4px;">
          La double authentification est active sur ce compte — pas besoin de code par e-mail, le code de ton
          application d'authentification suffit à confirmer le changement immédiatement.
        </p>
        ${passwordFieldHtml('newPassword1', 'Nouveau mot de passe (12 caractères minimum)')}
        ${passwordFieldHtml('newPassword2', 'Confirmer le nouveau mot de passe')}
        <div class="account-field">
          <label for="mfaCode">Code de l'application d'authentification</label>
          <input class="input account-code-input" id="mfaCode" inputmode="numeric" maxlength="6" placeholder="000000" />
        </div>
        <button type="button" class="btn btn-primary" id="passwordMfaBtn">Changer le mot de passe</button>
        <div id="passwordResult"></div>`;
      bindPasswordToggle(passwordSection);

      passwordSection.querySelector('#passwordMfaBtn').addEventListener('click', async () => {
        const resultEl = passwordSection.querySelector('#passwordResult');
        const p1 = passwordSection.querySelector('#newPassword1').value;
        const p2 = passwordSection.querySelector('#newPassword2').value;
        const totp = passwordSection.querySelector('#mfaCode').value.trim();
        if (p1.length < 12) {
          resultEl.innerHTML = '<p class="account-error">12 caractères minimum.</p>';
          return;
        }
        if (p1 !== p2) {
          resultEl.innerHTML = '<p class="account-error">Les deux mots de passe ne correspondent pas.</p>';
          return;
        }
        if (!totp) {
          resultEl.innerHTML = '<p class="account-error">Code de double authentification requis.</p>';
          return;
        }
        resultEl.innerHTML = '<p class="text-muted">Vérification...</p>';
        try {
          await api('POST', '/api/account-password-mfa', { new_password: p1, totp_code: totp });
          resultEl.innerHTML = '<p class="account-success">Mot de passe changé.</p>';
          passwordSection.querySelector('#newPassword1').value = '';
          passwordSection.querySelector('#newPassword2').value = '';
          passwordSection.querySelector('#mfaCode').value = '';
        } catch (err) {
          resultEl.innerHTML = `<p class="account-error">${esc(err.message)}</p>`;
        }
      });
    }

    trigger.addEventListener('click', open);
  }

  document.querySelectorAll('[data-account-trigger]').forEach(initAccountMenu);
})();
