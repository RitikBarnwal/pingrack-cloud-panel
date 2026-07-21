/**
 * assets/js/cookie-consent.js
 * Auto-loaded site-wide via inject_global_head() when cookie_consent_enabled=1
 *
 * Features:
 *  - GDPR-compliant banner (bottom / bottom-left / bottom-right)
 *  - Accept All / Decline / Manage Preferences
 *  - Per-category toggle: essential (locked), analytics, preferences, marketing
 *  - Remembers choice in localStorage for 365 days
 *  - Fires custom events: cookieConsent:accepted, cookieConsent:declined, cookieConsent:partial
 *  - Integrates with GA4: loads gtag only after analytics consent
 */

(function () {
  'use strict';

  const STORAGE_KEY    = 'gh_cookie_consent';
  const EXPIRY_DAYS    = 365;
  const config         = window.__cookieConsentConfig || {};

  const title          = config.title       || 'We use cookies 🍪';
  const message        = config.message     || 'We use cookies to enhance your browsing experience.';
  const btnAccept      = config.btnAccept   || 'Accept All';
  const btnDecline     = config.btnDecline  || 'Decline';
  const btnManage      = config.btnManage   || 'Manage Preferences';
  const position       = config.position    || 'bottom';
  const policySlug     = config.policySlug  || '';
  const policyUrl      = policySlug ? (window.__siteBase || '') + '/page/' + policySlug : '';
  const gaId           = config.gaId        || '';

  const cats = {
    analytics:   { label: '📊 Analytics',   enabled: config.analyticsEnabled    !== false, locked: false },
    preferences: { label: '⚙️ Preferences', enabled: config.preferencesEnabled  !== false, locked: false },
    marketing:   { label: '📣 Marketing',   enabled: config.marketingEnabled     === true,  locked: false },
  };

  // ── Stored consent ─────────────────────────────────────────
  function getStored() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const d = JSON.parse(raw);
      if (d.expires && Date.now() > d.expires) { localStorage.removeItem(STORAGE_KEY); return null; }
      return d;
    } catch(e) { return null; }
  }

  function saveConsent(choices) {
    const expires = Date.now() + EXPIRY_DAYS * 864e5;
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ choices, expires, ts: Date.now() }));
  }

  // ── GA4 conditional loader ────────────────────────────────
  function loadGA() {
    if (!gaId || typeof gtag !== 'undefined') return;
    const s = document.createElement('script');
    s.async = true;
    s.src   = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
    document.head.appendChild(s);
    window.dataLayer = window.dataLayer || [];
    window.gtag = function(){ window.dataLayer.push(arguments); };
    gtag('js', new Date());
    gtag('config', gaId);
  }

  function applyConsent(choices) {
    if (choices.analytics) loadGA();
    // Fire custom events for other scripts to hook into
    const evtName = choices.analytics && choices.preferences
      ? 'cookieConsent:accepted'
      : (choices.analytics || choices.preferences || choices.marketing)
        ? 'cookieConsent:partial'
        : 'cookieConsent:declined';
    document.dispatchEvent(new CustomEvent(evtName, { detail: choices }));
  }

  // ── Already decided ──────────────────────────────────────
  const stored = getStored();
  if (stored) {
    applyConsent(stored.choices);
    return; // Banner not needed
  }

  // ── Build banner HTML ─────────────────────────────────────
  const posStyle = {
    'bottom':       'left:0;right:0;bottom:0;border-radius:0;',
    'bottom-left':  'left:20px;bottom:20px;max-width:420px;border-radius:16px;',
    'bottom-right': 'right:20px;bottom:20px;max-width:420px;border-radius:16px;',
  }[position] || 'left:0;right:0;bottom:0;border-radius:0;';

  const policyLink = policyUrl
    ? ` <a href="${policyUrl}" style="color:#a5b4fc;font-size:12px;" target="_blank">Learn more →</a>`
    : '';

  // Build category checkboxes HTML
  const catHTML = Object.entries(cats).map(([key, cat]) => `
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:10px;">
      <input type="checkbox" data-cat="${key}"
             ${cat.enabled ? 'checked' : ''}
             ${cat.locked  ? 'disabled' : ''}
             style="width:16px;height:16px;accent-color:#6366f1;cursor:pointer;">
      <span style="font-size:13px;color:#cbd5e1;">${cat.label}</span>
    </label>`).join('');

  const bannerHTML = `
<div id="cc-banner" style="
  position:fixed;${posStyle}
  background:#1e293b;color:#e2e8f0;
  padding:24px;z-index:999999;
  box-shadow:0 -4px 30px rgba(0,0,0,.3);
  font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;
  border-top:1px solid #334155;
  transition:transform .3s cubic-bezier(.4,0,.2,1),opacity .3s;
">
  <div style="max-width:900px;margin:0 auto;">

    <!-- Main view -->
    <div id="cc-main-view">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
          <div style="font-size:16px;font-weight:700;color:#f1f5f9;margin-bottom:6px;">${title}</div>
          <div style="font-size:13px;color:#94a3b8;line-height:1.6;">${message}${policyLink}</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;flex-shrink:0;">
          <button id="cc-manage-btn" style="
            padding:9px 16px;border-radius:8px;border:1px solid #475569;
            background:transparent;color:#94a3b8;font-size:13px;font-weight:500;cursor:pointer;
          ">${btnManage}</button>
          <button id="cc-decline-btn" style="
            padding:9px 18px;border-radius:8px;border:none;
            background:#334155;color:#94a3b8;font-size:13px;font-weight:600;cursor:pointer;
          ">${btnDecline}</button>
          <button id="cc-accept-btn" style="
            padding:9px 22px;border-radius:8px;border:none;
            background:#6366f1;color:#fff;font-size:13px;font-weight:600;cursor:pointer;
          ">${btnAccept}</button>
        </div>
      </div>
    </div>

    <!-- Manage preferences panel (hidden by default) -->
    <div id="cc-manage-view" style="display:none;">
      <div style="font-size:15px;font-weight:700;color:#f1f5f9;margin-bottom:4px;">Manage Cookie Preferences</div>
      <div style="font-size:12px;color:#64748b;margin-bottom:16px;">Essential cookies are always active and cannot be disabled.</div>

      <!-- Essential (always locked on) -->
      <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;opacity:.7;cursor:not-allowed;">
        <input type="checkbox" checked disabled style="width:16px;height:16px;accent-color:#6366f1;">
        <span style="font-size:13px;color:#cbd5e1;">🔒 Essential — Always On (required for login, security)</span>
      </label>

      ${catHTML}

      <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;">
        <button id="cc-save-prefs" style="
          padding:9px 22px;border-radius:8px;border:none;
          background:#6366f1;color:#fff;font-size:13px;font-weight:600;cursor:pointer;
        ">Save Preferences</button>
        <button id="cc-back-btn" style="
          padding:9px 16px;border-radius:8px;border:1px solid #475569;
          background:transparent;color:#94a3b8;font-size:13px;cursor:pointer;
        ">← Back</button>
      </div>
    </div>

  </div>
</div>`;

  // ── Mount banner ──────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    document.body.insertAdjacentHTML('beforeend', bannerHTML);

    const banner      = document.getElementById('cc-banner');
    const mainView    = document.getElementById('cc-main-view');
    const manageView  = document.getElementById('cc-manage-view');

    function dismiss() {
      banner.style.opacity = '0';
      banner.style.transform = 'translateY(100%)';
      setTimeout(() => banner.remove(), 350);
    }

    function getChoices() {
      const choices = { analytics: false, preferences: false, marketing: false };
      banner.querySelectorAll('[data-cat]').forEach(el => {
        choices[el.dataset.cat] = el.checked;
      });
      return choices;
    }

    // Accept All
    document.getElementById('cc-accept-btn').addEventListener('click', () => {
      const choices = { analytics: cats.analytics.enabled, preferences: cats.preferences.enabled, marketing: cats.marketing.enabled };
      saveConsent(choices);
      applyConsent(choices);
      dismiss();
    });

    // Decline All
    document.getElementById('cc-decline-btn').addEventListener('click', () => {
      const choices = { analytics: false, preferences: false, marketing: false };
      saveConsent(choices);
      applyConsent(choices);
      dismiss();
    });

    // Open manage panel
    document.getElementById('cc-manage-btn').addEventListener('click', () => {
      mainView.style.display  = 'none';
      manageView.style.display = '';
    });

    // Back from manage
    document.getElementById('cc-back-btn').addEventListener('click', () => {
      manageView.style.display = 'none';
      mainView.style.display   = '';
    });

    // Save preferences
    document.getElementById('cc-save-prefs').addEventListener('click', () => {
      const choices = getChoices();
      saveConsent(choices);
      applyConsent(choices);
      dismiss();
    });
  });

})();
