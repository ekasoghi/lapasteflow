/* lp-build: saas-2026.10
 * OTP masuk (verifikasi 2 langkah) + animasi kubus Rubik – La Paste Bazzar Management
 * Dipakai di aplikasi bazzar (menggantikan srvLogin) dan Konsol Platform (menggantikan F.login): bila server meminta OTP, tampilkan 6 kotak kode; setelah benar, kotak terlipat menjadi kubus Rubik lalu masuk ke dashboard.
 */
(function () {
  'use strict';
  const IS_APP = typeof srvLogin === 'function' && typeof pull === 'function' && typeof api === 'function';
  const IS_PF = !IS_APP && typeof F === 'object' && !!F && typeof F.login === 'function' && typeof ADMIN !== 'undefined';
  if (!IS_APP && !IS_PF) return;

  const css = `
.otp-ov{position:fixed;inset:0;z-index:90;display:grid;place-items:center;padding:16px;overflow-y:auto;transition:background .6s ease;background:color-mix(in srgb,#070F22 62%,transparent);backdrop-filter:blur(10px) saturate(1.2);-webkit-backdrop-filter:blur(10px) saturate(1.2);animation:otpIn .28s ease-out}
@keyframes otpIn{from{opacity:0}to{opacity:1}}
.otp-ov *,.rb-stage *{box-sizing:border-box}
.otp-ov.win{background:color-mix(in srgb,#070F22 90%,transparent)}
.otp-card{position:relative;width:min(440px,100%);max-width:100%;background:var(--surface,#fff);color:var(--fg,#0C1830);border:1px solid var(--line,#D3DAE8);border-radius:20px;padding:28px 24px 22px;box-shadow:0 30px 80px rgba(7,15,34,.45);text-align:center;animation:otpUp .35s cubic-bezier(.2,.8,.2,1)}
@keyframes otpUp{from{transform:translateY(16px) scale(.98);opacity:0}to{transform:none;opacity:1}}
.otp-ico{width:56px;height:56px;margin:0 auto 10px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(135deg,#0D1F45,#1F4FA8);color:#F6EFE0;box-shadow:0 8px 22px rgba(13,31,69,.35)}
.otp-ico svg{width:28px;height:28px}
.otp-card h2{margin:0 0 6px;font-size:1.25rem}
.otp-sub{margin:0 auto 18px;max-width:34ch;color:var(--muted,#566381);font-size:.92rem;line-height:1.5}
.otp-sub b{color:var(--fg,#0C1830)}
.otp-boxes{display:flex;justify-content:center;gap:clamp(5px,1.6vw,10px);margin:0 0 12px}
.otp-boxes input{flex:0 1 auto;min-width:0;width:clamp(38px,11vw,52px);height:clamp(48px,13.5vw,62px);padding:0;text-align:center;font:700 clamp(1.3rem,5vw,1.6rem)/1 var(--f-mono,ui-monospace,Menlo,monospace);color:var(--fg,#0C1830);background:var(--bg,#F3F5FA);border:2px solid var(--line,#D3DAE8);border-radius:12px;outline:0;transition:border-color .15s,box-shadow .15s,transform .15s;caret-color:#D6A645}
.otp-boxes input:focus{border-color:#D6A645;box-shadow:0 0 0 4px rgba(214,166,69,.22);transform:translateY(-1px)}
.otp-boxes input.f{border-color:color-mix(in srgb,#D6A645 70%,var(--line,#D3DAE8))}
.otp-boxes.bad{animation:otpShake .42s}
.otp-boxes.bad input{border-color:var(--bad,#B42318)}
@keyframes otpShake{0%,100%{transform:none}20%{transform:translateX(-8px)}40%{transform:translateX(7px)}60%{transform:translateX(-5px)}80%{transform:translateX(3px)}}
.otp-msg{min-height:20px;margin:0 0 10px;font-size:.86rem;color:var(--bad,#B42318)}
.otp-msg.ok{color:var(--ok,#17803D)}
.otp-go{width:100%;justify-content:center}
.otp-meta{margin:14px 0 4px;font-size:.84rem;color:var(--muted,#566381)}
.otp-alt{margin-top:6px;font-size:.84rem;color:var(--muted,#566381);display:flex;flex-direction:column;gap:6px;align-items:center}
.otp-link{background:none;border:0;padding:2px 4px;color:var(--accent,#1F4FA8);font:inherit;font-weight:600;cursor:pointer;border-radius:6px}
.otp-link:disabled{color:var(--muted,#566381);cursor:default;font-weight:500}
.otp-link:focus-visible{outline:2px solid #D6A645}
.otp-cancel{margin-top:12px;font-weight:500;color:var(--muted,#566381)}
.otp-card.hide{transition:opacity .25s;opacity:0}
/* Kubus Rubik */
.rb-stage{position:fixed;width:0;height:0;z-index:95;perspective:900px;pointer-events:none}
.rb-cube{position:absolute;left:0;top:0;transform-style:preserve-3d;transition:transform 1s cubic-bezier(.22,.9,.25,1)}
.rb-face{position:absolute;left:var(--h);top:var(--h);width:var(--s);height:var(--s);box-sizing:border-box;border-radius:12px;background:var(--surface,#fff);border:2px solid #D6A645;display:grid;grid-template-columns:repeat(3,1fr);gap:4px;padding:5px;transform-origin:50% 50%;transition:transform .95s cubic-bezier(.65,.02,.24,1.12),background .45s ease,border-color .45s ease,box-shadow .45s ease;backface-visibility:visible}
.rb-face i{border-radius:6px;opacity:0;transform:scale(.6);transition:opacity .35s ease,transform .45s cubic-bezier(.3,1.6,.5,1),background .55s ease;box-shadow:inset 0 2px 0 rgba(255,255,255,.45),inset 0 -3px 6px rgba(0,0,0,.18)}
.rb-face b{position:absolute;inset:0;display:grid;place-items:center;font:700 calc(var(--s)*.42)/1 var(--f-mono,ui-monospace,Menlo,monospace);color:var(--fg,#0C1830);transition:opacity .3s ease}
.rb-cube.fold .rb-face{background:#0B1630;border-color:#0B1630;box-shadow:inset 0 0 0 1px rgba(255,255,255,.06)}
.rb-cube.fold .rb-face i{opacity:1;transform:none}
.rb-cube.fold .rb-face b{opacity:0}
.rb-cube.spin{animation:rbSpin 1.45s cubic-bezier(.45,.05,.2,1) forwards}
@keyframes rbSpin{from{transform:rotateX(-24deg) rotateY(-38deg)}55%{transform:rotateX(-32deg) rotateY(200deg) rotateZ(4deg)}to{transform:rotateX(-26deg) rotateY(322deg)}}
.rb-glow{position:absolute;left:-90px;top:-90px;width:180px;height:180px;border-radius:50%;background:radial-gradient(circle,rgba(214,166,69,.55),rgba(214,166,69,0) 68%);opacity:0;transform:scale(.4);transition:opacity .6s ease,transform .9s ease}
.rb-stage.done .rb-glow{opacity:1;transform:scale(1.25)}
.rb-cap{position:absolute;left:-160px;top:86px;width:320px;text-align:center;color:#F6EFE0;font-weight:700;font-size:1.02rem;opacity:0;transform:translateY(8px);transition:opacity .45s ease .1s,transform .45s ease .1s;text-shadow:0 2px 12px rgba(7,15,34,.6)}
.rb-cap small{display:block;font-weight:500;font-size:.82rem;color:#D6A645;letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px}
.rb-stage.done .rb-cap{opacity:1;transform:none}
.rb-stage.out .rb-cube{transition:transform .8s cubic-bezier(.6,0,.4,1),opacity .42s ease .08s;transform:rotateX(-26deg) rotateY(322deg) scale(9)!important;opacity:0}
.rb-stage.out .rb-cap,.rb-stage.out .rb-glow{opacity:0;transition:opacity .25s}
.otp-ov.out{transition:opacity .7s ease .2s,backdrop-filter .7s ease;opacity:0}
@media (max-width:420px){.otp-card{padding:24px 16px 18px;border-radius:18px}}
@media (prefers-reduced-motion:reduce){.otp-ov,.otp-card{animation:none}.rb-face,.rb-cube,.rb-face i{transition:none!important}.rb-cube.spin{animation:none}}
`;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  const ICO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>';
  const PAL = ['#F5F7FA', '#F6C343', '#E5484D', '#F08A24', '#2F6FED', '#2BB673'];
  const reduce = () => window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const e = s => (typeof esc === 'function' ? esc(s) : String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])));
  const chName = id => (id === 'wa' ? 'WhatsApp' : 'email');
  let O = null; // state OTP aktif

  /* ---------- Integrasi 1: aplikasi bazzar (app.html) ---------- */
  let AD = null;
  if (IS_APP) {
    const finishApp = async j => {
      U = j.user; ui.loginErr = ''; ui.loginCode = ''; ui.mustChange = !!j.mustChange;
      if (!ui.mustChange) await pull();
      view = U.kind === 'tenant' ? 'm_home' : 'dashboard'; SRV.lastAct = Date.now();
      render();
      if (ui.mustChange) A.pwChange({ force: 1 });
    };
    AD = { title: 'Verifikasi masuk', send: ch => api('otp_send', { channel: ch }), verify: code => api('otp_verify', { code }), after: finishApp,
      expired: t => { ui.loginErr = t || 'Sesi verifikasi berakhir. Silakan masuk ulang.'; render(); },
      cancel: () => { const i = document.getElementById('lg-pw'); if (i) { i.value = ''; i.focus(); } } };
    window.srvLoginNoOtp = srvLogin;
    window.srvLogin = async function (fd) {
      ui.lastId = String(fd.get('id'));
      const btn = document.querySelector('form[data-f=login] button[type=submit]');
      if (btn) { btn.disabled = true; btn.dataset.t = btn.textContent; btn.textContent = 'Memeriksa…'; }
      try {
        const j = await api('login', { id: String(fd.get('id')), pw: String(fd.get('pw')) });
        if (j && j.otp) { ui.loginErr = ''; ui.loginCode = ''; openOtp(j); return; }
        await finishApp(j); window.scrollTo(0, 0);
      } catch (err) { ui.loginErr = err.message; ui.loginCode = err.code || ''; render(); }
      finally { if (btn && btn.isConnected) { btn.disabled = false; btn.textContent = btn.dataset.t || 'Masuk'; } }
    };
  }

  /* ---------- Integrasi 2: Konsol Platform (platform.html) ---------- */
  if (IS_PF) {
    const papi = async (a, body) => {
      const o = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (body) { o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF'] = CSRF; o.body = JSON.stringify(body); }
      let r, j = null;
      try { r = await fetch('api.php?a=' + a, o); } catch (err) { throw new Error('Tidak dapat terhubung ke server'); }
      try { j = await r.json(); } catch (err) { /* bukan JSON */ }
      if (j && j.csrf) CSRF = j.csrf;
      if (!r.ok || !j || j.ok === false) { const er = new Error((j && j.error) || ('Kode ' + r.status)); er.code = j && j.code; throw er; }
      return j;
    };
    AD = { title: 'Verifikasi Konsol Platform', send: ch => papi('p_otp_send', { channel: ch }), verify: code => papi('p_otp_verify', { code }),
      after: async () => { const me = await papi('p_me'); ADMIN = me.admin; D = null; render(); },
      expired: t => toast(t || 'Sesi verifikasi berakhir. Silakan masuk ulang.'),
      cancel: () => { const i = document.getElementById('pp'); if (i) { i.value = ''; i.focus(); } } };
    F.login = async fd => {
      const btn = document.querySelector('form[data-f=login] button[type=submit]');
      if (btn) { btn.disabled = true; btn.dataset.t = btn.textContent; btn.textContent = 'Memeriksa…'; }
      try {
        const j = await papi('p_login', { email: fd.get('email'), pw: fd.get('pw') });
        if (j && j.otp) { openOtp(j); return; }
        await AD.after(j);
      } catch (err) { toast(err.message); }
      finally { if (btn && btn.isConnected) { btn.disabled = false; btn.textContent = btn.dataset.t || 'Masuk'; } }
    };
  }

  /* ---------- Layar OTP ---------- */
  function openOtp(j) {
    close(true);
    O = { j, busy: false, timer: 0 };
    const ov = document.createElement('div');
    ov.className = 'otp-ov'; ov.id = 'otp-ov';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-labelledby', 'otp-t');
    ov.innerHTML = `<div class="otp-card"><div class="otp-ico">${ICO}</div><h2 id="otp-t">${AD.title}</h2><p class="otp-sub" id="otp-sub"></p>
      <form id="otp-f" autocomplete="off" novalidate><div class="otp-boxes" id="otp-b" role="group" aria-label="Kode OTP 6 digit">${[0, 1, 2, 3, 4, 5].map(i => `<input inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Digit ${i + 1}" ${i === 0 ? 'autocomplete="one-time-code"' : 'autocomplete="off"'}>`).join('')}</div>
      <div class="otp-msg" id="otp-m" aria-live="polite"></div><button class="btn pri otp-go" type="submit" id="otp-go">Verifikasi &amp; masuk</button></form>
      <div class="otp-meta" id="otp-meta"></div><div class="otp-alt" id="otp-alt"></div>
      <button type="button" class="otp-link otp-cancel" data-x="cancel">← Kembali ke halaman masuk</button></div>`;
    document.body.appendChild(ov);
    const ins = [...ov.querySelectorAll('#otp-b input')];
    ins.forEach((inp, i) => {
      inp.addEventListener('input', () => {
        const d = inp.value.replace(/\D/g, '');
        if (d.length > 1) { spread(ins, d, i); return; }
        inp.value = d; inp.classList.toggle('f', !!d);
        if (d && i < 5) ins[i + 1].focus();
        clearBad(); autoSubmit(ins);
      });
      inp.addEventListener('keydown', ev => {
        if (ev.key === 'Backspace' && !inp.value && i > 0) { ins[i - 1].focus(); ins[i - 1].value = ''; ins[i - 1].classList.remove('f'); ev.preventDefault(); }
        else if (ev.key === 'ArrowLeft' && i > 0) { ins[i - 1].focus(); ev.preventDefault(); }
        else if (ev.key === 'ArrowRight' && i < 5) { ins[i + 1].focus(); ev.preventDefault(); }
      });
      inp.addEventListener('paste', ev => { const t = (ev.clipboardData || window.clipboardData).getData('text').replace(/\D/g, ''); if (t) { ev.preventDefault(); spread(ins, t, 0); } });
      inp.addEventListener('focus', () => inp.select());
    });
    ov.querySelector('#otp-f').addEventListener('submit', ev => { ev.preventDefault(); verify(ins); });
    ov.addEventListener('click', ev => {
      const b = ev.target.closest('[data-x]'); if (!b || b.disabled) return;
      if (b.dataset.x === 'cancel') close();
      if (b.dataset.x === 'send') resend(b.dataset.ch);
    });
    ov.addEventListener('keydown', ev => { if (ev.key === 'Escape') close(); });
    paint(j);
    if (j.sent === false && j.error) msg(j.error);
    setTimeout(() => ins[0].focus(), 60);
    O.timer = setInterval(tick, 1000);
  }
  function spread(ins, digits, from) {
    digits.slice(0, 6 - from).split('').forEach((d, k) => { ins[from + k].value = d; ins[from + k].classList.add('f'); });
    ins[Math.min(5, from + digits.length)].focus(); clearBad(); autoSubmit(ins);
  }
  function autoSubmit(ins) { if (ins.every(x => /^\d$/.test(x.value)) && O && !O.busy) setTimeout(() => verify(ins), 120); }
  function msg(t, ok) { const m = document.getElementById('otp-m'); if (m) { m.textContent = t || ''; m.classList.toggle('ok', !!ok); } }
  function clearBad() { const b = document.getElementById('otp-b'); if (b) b.classList.remove('bad'); if (document.getElementById('otp-m')?.textContent && !O?.busy) msg(''); }
  const mmss = s => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

  /** Tulis tujuan, hitung mundur, dan tombol saluran alternatif. */
  function paint(j) {
    O.j = j; O.exp = Date.now() + (j.expires || 0) * 1000; O.res = Date.now() + (j.resendIn || 0) * 1000;
    const sub = document.getElementById('otp-sub');
    sub.innerHTML = j.via ? `${j.name ? 'Halo ' + e(j.name) + ', k' : 'K'}ode 6 digit sudah dikirim ke <b>${chName(j.via)} ${e(j.to)}</b>. Masukkan kode untuk melanjutkan.` : 'Kode belum terkirim. Pilih saluran di bawah untuk mengirim kode.';
    const alts = (j.channels || []).filter(c => c.id !== j.via);
    document.getElementById('otp-alt').innerHTML = alts.length ? `<span>Tidak menerima kode?</span>${alts.map(c => `<button type="button" class="otp-link" data-x="send" data-ch="${c.id}">Kirim lewat ${chName(c.id)} (${e(c.to)})</button>`).join('')}` : '';
    tick();
  }
  function tick() {
    if (!O) return;
    const meta = document.getElementById('otp-meta'); if (!meta) return;
    const ex = Math.max(0, Math.round((O.exp - Date.now()) / 1000)), rs = Math.max(0, Math.round((O.res - Date.now()) / 1000));
    const via = O.j.via || (O.j.channels && O.j.channels[0] && O.j.channels[0].id) || 'wa';
    meta.innerHTML = (O.j.via ? (ex ? `Kode berlaku <b>${mmss(ex)}</b> · ` : '<b style="color:var(--bad,#B42318)">Kode kedaluwarsa</b> · ') : '') +
      `<button type="button" class="otp-link" data-x="send" data-ch="${via}" ${rs ? 'disabled' : ''}>${rs ? `Kirim ulang (${rs} dtk)` : 'Kirim ulang kode'}</button>`;
  }
  async function resend(ch) {
    if (!O || O.busy) return;
    O.busy = true; msg('Mengirim kode lewat ' + chName(ch) + '…', true);
    try { const j = await AD.send(ch); paint(j); msg('Kode baru sudah dikirim lewat ' + chName(j.via) + '.', true); resetBoxes(); }
    catch (err) { if (err.code === 'otp_expired') return expired(err.message); msg(err.message); }
    finally { if (O) O.busy = false; }
  }
  function resetBoxes() { document.getElementById('otp-b')?.classList.remove('bad'); document.querySelectorAll('#otp-b input').forEach(x => { x.value = ''; x.classList.remove('f'); }); const f = document.querySelector('#otp-b input'); f && f.focus(); }
  function expired(t) { close(true); AD.expired(t); }

  async function verify(ins) {
    if (!O || O.busy) return;
    const code = ins.map(x => x.value).join('');
    if (!/^\d{6}$/.test(code)) { msg('Masukkan 6 digit kode.'); return; }
    O.busy = true; const go = document.getElementById('otp-go'); go.disabled = true; go.textContent = 'Memverifikasi…'; msg('');
    try {
      const j = await AD.verify(code);
      clearInterval(O.timer);
      await success(j, ins);
    } catch (err) {
      if (!O) return;
      O.busy = false; go.disabled = false; go.textContent = 'Verifikasi & masuk';
      if (err.code === 'otp_expired') return expired(err.message);
      const b = document.getElementById('otp-b'); b.classList.remove('bad'); void b.offsetWidth; b.classList.add('bad');
      msg(err.message); resetBoxes();
    }
  }

  /* ---------- Berhasil: kotak menyatu menjadi kubus Rubik ---------- */
  async function success(j, ins) {
    const ov = document.getElementById('otp-ov'), card = ov.querySelector('.otp-card');
    const name = (O.j && O.j.name) || '';
    const data = (async () => { await AD.after(j); })();   // muat data dashboard di balik layar
    if (reduce()) { try { await data; } catch (err) { toast(err.message || 'Gagal memuat data'); } ov.classList.add('out'); await wait(700); ov.remove(); O = null; return; }
    const rects = ins.map(x => x.getBoundingClientRect());
    const digits = ins.map(x => x.value);
    const cx = (rects[0].left + rects[5].right) / 2, cy = rects[0].top + rects[0].height / 2;
    const size = Math.round(Math.min(110, Math.max(84, innerWidth * .22))), h = size / 2;
    const stage = document.createElement('div'); stage.className = 'rb-stage';
    stage.style.left = cx + 'px'; stage.style.top = cy + 'px';
    stage.innerHTML = `<div class="rb-glow"></div><div class="rb-cube" style="--s:${size}px;--h:${-h}px"></div><div class="rb-cap"><small>Terverifikasi</small>${name ? 'Selamat datang, ' + e(name) : 'Selamat datang'}</div>`;
    const cube = stage.querySelector('.rb-cube');
    const FT = [`rotateY(0deg) translateZ(${h}px)`, `rotateY(90deg) translateZ(${h}px)`, `rotateX(90deg) translateZ(${h}px)`, `rotateY(-90deg) translateZ(${h}px)`, `rotateX(-90deg) translateZ(${h}px)`, `rotateY(180deg) translateZ(${h}px)`];
    const faces = rects.map((r, i) => {
      const f = document.createElement('div'); f.className = 'rb-face';
      f.innerHTML = Array.from({ length: 9 }, () => `<i style="background:${PAL[(Math.random() * 6) | 0]}"></i>`).join('') + `<b>${e(digits[i])}</b>`;
      f.style.transform = `translate3d(${r.left + r.width / 2 - cx}px,${r.top + r.height / 2 - cy}px,0) scale(${(r.width / size).toFixed(3)},${(r.height / size).toFixed(3)})`;
      cube.appendChild(f); return f;
    });
    ins.forEach(x => { x.style.visibility = 'hidden'; });
    document.body.appendChild(stage);
    card.classList.add('hide'); ov.classList.add('win');
    await wait(30);
    // 1) lipat: setiap kotak terbang menjadi satu sisi kubus
    cube.style.transform = 'rotateX(-24deg) rotateY(-38deg)';
    faces.forEach((f, i) => setTimeout(() => { f.style.transform = FT[i]; }, i * 70));
    setTimeout(() => cube.classList.add('fold'), 380);
    await wait(1250);
    // 2) putar & selesaikan warna (acak -> rapi per sisi)
    cube.classList.add('spin');
    faces.forEach((f, i) => [...f.querySelectorAll('i')].forEach((s, k) => setTimeout(() => { s.style.background = PAL[i]; }, 260 + k * 55 + i * 30)));
    await wait(1250);
    stage.classList.add('done');
    await Promise.all([data.catch(() => {}), wait(650)]);
    // 3) masuk ke dashboard: kubus membesar menembus layar
    stage.classList.add('out'); ov.classList.add('out');
    await wait(950);
    stage.remove(); ov.remove(); O = null;
    try { await data; } catch (err) { toast(err.message || 'Gagal memuat data'); }
  }

  function close(silent) {
    const ov = document.getElementById('otp-ov');
    if (O) clearInterval(O.timer);
    O = null;
    if (ov) ov.remove();
    if (!silent && AD) AD.cancel();
  }
})();
