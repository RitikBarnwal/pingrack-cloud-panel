const domain = (window.CST_DM && window.CST_DM.trim() !== '')
  ? window.CST_DM.trim()
  : window.BASE_URL;
const mainUrl = domain.startsWith('http')
  ? domain
  : 'https://' + domain;
// CloudVault — app.js
// ============================================================

/* ── Utilities ─────────────────────────────────────────── */
function $(sel, ctx=document) { return ctx.querySelector(sel); }
function $$(sel, ctx=document) { return [...ctx.querySelectorAll(sel)]; }
function csrf() { return $('meta[name=csrf]')?.content || ''; }

function formatBytes(b) {
  const u = ['B','KB','MB','GB','TB']; let i = 0;
  while(b >= 1024 && i < u.length-1) { b /= 1024; i++; }
  return b.toFixed(i===0?0:1) + ' ' + u[i];
}

/* ── Toast ─────────────────────────────────────────────── */
let _toastWrap = null;
function toast(msg, type='inf', dur=3800) {
  if (!_toastWrap) { _toastWrap = document.createElement('div'); _toastWrap.className='toast-wrap'; document.body.appendChild(_toastWrap); }
  const icons = { ok: iconCheck(), err: iconX(), inf: iconInfo() };
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.innerHTML = `${icons[type]||icons.inf}<span>${msg}</span>`;
  _toastWrap.appendChild(t);
  setTimeout(() => { t.style.cssText='opacity:0;transition:opacity .3s'; setTimeout(()=>t.remove(),300); }, dur);
}

/* ── SVG Icons ─────────────────────────────────────────── */
function iconCheck()  { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`; }
function iconX()      { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`; }
function iconInfo()   { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`; }
function iconDl()     { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>`; }
function iconCopy()   { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`; }
function iconTrash()  { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`; }
function iconDots()   { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`; }
function iconSearch() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`; }
function iconList()   { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`; }
function iconGrid()   { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>`; }

/* ── Copy to clipboard ─────────────────────────────────── */
function copyLink(url) {
  navigator.clipboard.writeText(url).then(
    () => toast('Link copied!', 'ok'),
    () => toast('Copy failed', 'err')
  );
}

/* ── View Mode (persist via localStorage) ─────────────── */
function getView()         { return localStorage.getItem('cv_view') || 'list'; }
function setView(v)        { localStorage.setItem('cv_view', v); }

function initMobile() {
  const sb   = $('#sidebar');
  const ov   = $('#overlay');
  const ham  = $('#ham-btn');
  if (!sb||!ham) return;
  ham.addEventListener('click', () => { sb.classList.toggle('open'); ov?.classList.toggle('open'); });
  ov?.addEventListener('click', () => { sb.classList.remove('open'); ov.classList.remove('open'); });
}

/* ── Register: username availability ──────────────────── */
function initRegisterPage() {
  const uField = $('#reg-username');
  const uHint  = $('#username-hint');
  if (!uField) return;

  let _uTimer = null;
  uField.addEventListener('input', () => {
    clearTimeout(_uTimer);
    const val = uField.value.trim();
    uField.classList.remove('is-valid','is-invalid');
    uHint.className='field-hint hint-info';
    uHint.innerHTML = '';
    if (!val) return;
    if (!/^[a-zA-Z0-9_]{3,30}$/.test(val)) {
      uHint.className='field-hint hint-err';
      uHint.innerHTML = iconX() + ' 3–30 chars, letters/numbers/underscore only';
      uField.classList.add('is-invalid');
      return;
    }
    uHint.className='field-hint hint-checking';
    uHint.innerHTML = '<span class="spinner spinner-blue"></span> Checking…';
    _uTimer = setTimeout(() => checkUsername(val), 500);
  });

  function checkUsername(u) {
    fetch(BASE_URL+'/check_username.php?u='+encodeURIComponent(u))
      .then(r=>r.json())
      .then(res=>{
        uField.classList.remove('is-valid','is-invalid');
        if (res.available) {
          uHint.className='field-hint hint-ok';
          uHint.innerHTML = iconCheck() + ' Username available!';
          uField.classList.add('is-valid');
        } else {
          uHint.className='field-hint hint-err';
          uHint.innerHTML = iconX() + ' Username taken, try another';
          uField.classList.add('is-invalid');
        }
      }).catch(()=>{});
  }

  // OTP section
  initOtpSection('reg');

  // Terms checkbox → enable submit
  const terms = $('#terms-check');
  const subBtn = $('#reg-submit');
  if (terms && subBtn) {
    terms.addEventListener('change', () => { subBtn.disabled = !terms.checked; });
  }
}

/* ── Forgot password OTP ───────────────────────────────── */
function initForgotPage() {
  initOtpSection('forgot');
}

/* ── OTP Section (shared for register + forgot) ────────── */
function initOtpSection(mode) {
  const emailField  = $(`#${mode}-email`);
  const verifyBtn   = $(`#${mode}-verify-btn`);
  const otpSection  = $(`#${mode}-otp-section`);
  const otpDigits   = $$('.otp-d');
  const otpStatus   = $(`#${mode}-otp-status`);
  const resendBtn   = $(`#${mode}-resend`);
  const timerTxt    = $(`#${mode}-timer`);
  const emailCheck  = $(`#${mode}-email-check`);
  if (!verifyBtn || !emailField) return;

  let resendCooldown = null;

  // Send OTP
  //verifyBtn.addEventListener('click', async () => {
  verifyBtn.addEventListener('click', () => {

  const email = emailField.value.trim();
  const warn  = document.getElementById('disposable-warn');

  if (!email || !/\S+@\S+\.\S+/.test(email)) {
    toast('Enter a valid email','err'); 
    return;
  }
  
  verifyBtn.disabled = true;
  verifyBtn.innerHTML = '<span class="spinner"></span> Sending…';

  const fd = new FormData();
  fd.append('email', email);
  fd.append('mode', mode);
  fd.append('csrf_token', csrf());
  fd.append('account_type', document.querySelector('input[name=account_type]:checked')?.value);

  fetch(BASE_URL+'/includes/send_otp.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
      if (res.success) {
        verifyBtn.style.display='none';
        emailField.readOnly = true;
        if(emailCheck) emailCheck.style.display='inline-flex';
        if(otpSection) otpSection.style.display='block';
        toast('OTP sent to '+email,'ok');
        otpDigits[0]?.focus();
        startResendTimer(60);
      } else {
        verifyBtn.disabled=false;
        verifyBtn.innerHTML='Verify Email';
        toast(res.error||'Failed to send OTP','err');
      }
    })
    .catch(()=>{
      verifyBtn.disabled=false;
      verifyBtn.innerHTML='Verify Email';
      toast('OTP Network error','err');
    });
});

  // OTP digit navigation
  otpDigits.forEach((d,i) => {
    d.addEventListener('input', e => {
      d.classList.add('filled');
      const v = d.value.replace(/\D/g,'');
      d.value = v.slice(-1);
      if (v && i < otpDigits.length-1) otpDigits[i+1].focus();
      if (otpDigits.every(x=>x.value)) verifyOtp();
    });
    d.addEventListener('keydown', e => {
      if (e.key==='Backspace' && !d.value && i>0) { otpDigits[i-1].focus(); otpDigits[i-1].value=''; otpDigits[i-1].classList.remove('filled','ok','bad'); }
      if (e.key==='ArrowLeft' && i>0) otpDigits[i-1].focus();
      if (e.key==='ArrowRight' && i<otpDigits.length-1) otpDigits[i+1].focus();
    });
    d.addEventListener('paste', e => {
      e.preventDefault();
      const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
      [...p.slice(0,6)].forEach((c,j)=>{ if(otpDigits[i+j]) { otpDigits[i+j].value=c; otpDigits[i+j].classList.add('filled'); } });
      const next = Math.min(i+p.length, otpDigits.length-1);
      otpDigits[next].focus();
      if(otpDigits.every(x=>x.value)) verifyOtp();
    });
  });

  function verifyOtp() {
  const code = otpDigits.map(d => d.value).join('');
  if (code.length < 6) return;

  otpDigits.forEach(d => {
    d.classList.remove('ok', 'bad');
    d.classList.add('filled');
  });

  if (otpStatus) {
    otpStatus.className = 'field-hint hint-checking';
    otpStatus.innerHTML = '<span class="spinner spinner-blue"></span> Verifying…';
  }

  const fd = new FormData();
  fd.append('email', emailField.value.trim());
  fd.append('otp', code);
  fd.append('mode', mode);
  fd.append('csrf_token', csrf());

  fetch(BASE_URL + '/includes/verify_otp.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {

      if (res.success) {

        window.OTP_VERIFIED = true;
        
        otpDigits.forEach(d => d.classList.add('ok'));

        if (otpStatus) {
          otpStatus.className = 'field-hint hint-ok';
          otpStatus.innerHTML = iconCheck() + ' Email verified!';
        }

        // ✅ Show next step
        const nextSection = $(`#${mode}-after-otp`);
        if (nextSection) {
          nextSection.style.display = 'block';
          const row = otpSection?.querySelector('.otp-row');
          if (row) row.style.pointerEvents = 'none';
        }

        const otpHiddenField = $(`#${mode}-otp-value`);
        if (otpHiddenField) otpHiddenField.value = code;

        // 🔥 NEW: hide resend + stop timer
        const resendBtn = document.getElementById('reg-resend');
        const timerTxt = document.getElementById('reg-timer');

        if (resendBtn) resendBtn.style.display = 'none';
        if (timerTxt) timerTxt.textContent = '';

        if (typeof _resendTimer !== 'undefined') {
          clearInterval(_resendTimer);
        }

      } else {

        otpDigits.forEach(d => {
          d.classList.remove('filled');
          d.classList.add('bad');
        });

        if (otpStatus) {
          otpStatus.className = 'field-hint hint-err';
          otpStatus.innerHTML = iconX() + ' Incorrect OTP. Try again.';
        }

        setTimeout(() => {
          otpDigits.forEach(d => {
            d.value = '';
            d.classList.remove('bad', 'filled');
          });
          otpDigits[0].focus();
          if (otpStatus) otpStatus.innerHTML = '';
        }, 1000);
      }

    })
    .catch(() => {
      if (otpStatus) {
        otpStatus.className = 'field-hint hint-err';
        otpStatus.innerHTML = iconX() + ' Network error';
      }
    });
}

  // Resend
  resendBtn?.addEventListener('click', () => {
    resendBtn.disabled=true;
    const fd=new FormData();
    fd.append('email', emailField.value.trim());
    fd.append('mode', mode);
    fd.append('csrf_token', csrf());
    fetch(BASE_URL+'/includes/send_otp.php',{method:'POST',body:fd})
      .then(r=>r.json())
      .then(res=>{
        if(res.success){ toast('OTP resent!','ok'); startResendTimer(60); }
        else toast(res.error||'Failed','err');
      }).catch(()=>toast('Network error','err'));
  });

  function startResendTimer(sec) {

  if (window.OTP_VERIFIED) return; // 👈 ADD THIS

  clearInterval(resendCooldown);

  if(resendBtn) resendBtn.disabled = true;

  let s = sec;
  tick();

  resendCooldown = setInterval(() => {

    if (window.OTP_VERIFIED) {
      clearInterval(resendCooldown);
      if(timerTxt) timerTxt.textContent = '';
      return;
    }

    s--;

    if (s <= 0) {
      clearInterval(resendCooldown);
      if(resendBtn) resendBtn.disabled = false;
      if(timerTxt) timerTxt.textContent = '';
    } else tick();

  }, 1000);

  function tick() {
    if (window.OTP_VERIFIED) return;
    if (timerTxt) timerTxt.textContent = 'Resend in ' + s + 's';
  }
}
}

/* ── Debounce ──────────────────────────────────────────── */
function debounce(fn, delay) {
  let t; return (...a) => { clearTimeout(t); t = setTimeout(()=>fn(...a), delay); };
}

/* ── Init ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initMobile();
  if ($('#reg-username'))  initRegisterPage();
  if ($('#forgot-email'))  initForgotPage();
});


window.toast = toast;
window.copyLink = copyLink;