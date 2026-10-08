/**
 * The JS half of src/I18n.php's convention. Every page that needs
 * translated strings in its own JS embeds the current dictionary as
 * `window.I18N` right before this script tag:
 *
 *   <script>window.I18N = <?= json_encode(I18n::all()) ?>;</script>
 *   <script src="<?= asset('js/i18n.js') ?>"></script>
 *
 * so there is exactly one source of truth (src/translations/*.php) for
 * both sides — this file never hardcodes any string of its own, it just
 * looks values up. Also wires up any `[data-lang-switch]` link found on
 * the page (the small FR/EN toggle in the topbar) to set the locale
 * cookie and reload.
 */
(function () {
  const DICT = window.I18N || {};

  window.t = function (key, vars) {
    let text = DICT[key] || key;
    if (vars) {
      for (const name in vars) {
        text = text.split('{' + name + '}').join(String(vars[name]));
      }
    }
    return text;
  };

  document.querySelectorAll('[data-lang-switch]').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      const locale = el.dataset.langSwitch;
      document.cookie = 'codex_locale=' + locale + ';path=/;max-age=' + 60 * 60 * 24 * 365;
      location.reload();
    });
  });
})();
