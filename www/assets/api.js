/* Booking Roster — helper condivisi frontend */
const API = '/api';

async function api(path, {method = 'GET', body = null} = {}) {
  const opt = {method, credentials: 'include', headers: {}};
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const res = await fetch(`${API}/${path}`, opt);
  let data = {};
  try { data = await res.json(); } catch (e) {}
  if (!res.ok || data.ok === false) {
    const err = new Error(data.error || `http_${res.status}`);
    err.data = data; throw err;
  }
  return data;
}

/* messaggi d'errore in italiano */
const ERR = {
  email_invalid:'Email non valida', password_too_short:'Password troppo corta (min 8)',
  role_invalid:'Ruolo non valido', email_taken:'Email già registrata',
  credentials_invalid:'Email o password errati', account_blocked:'Account bloccato',
  not_authenticated:'Devi accedere', forbidden_role:'Non hai i permessi',
  artist_required:'Artista mancante', message_required:'Scrivi un messaggio',
  db_connection_failed:'Errore di connessione al database',
  forbidden_admin:'Accesso riservato agli admin', stage_name_required:"Inserisci il nome d'arte",
  org_name_required:'Inserisci il nome del locale/organizzazione', create_failed:'Creazione fallita',
  token_invalid:'Token di setup errato', setup_disabled:'Bootstrap disabilitato',
  admin_exists:'Esiste già un admin: usa il login',
  token_invalid_or_expired:'Link scaduto o non valido', current_password_wrong:'Password attuale errata',
  name_required:'Inserisci un nome', not_found:'Non trovato',
  not_an_artist:'Non è un artista', not_a_promoter:'Non è un promoter', update_failed:'Salvataggio fallito',
  cannot_delete_self:'Non puoi eliminare te stesso', cannot_delete_admin:'Non puoi eliminare un admin',
  email_not_verified:'Devi verificare la tua email prima di accedere', register_failed:'Registrazione fallita',
  tipo_required:'Seleziona il tipo', comune_required:'Inserisci il comune',
  phone_required:'Inserisci il telefono', website_required:'Inserisci un sito web o altro link',
  email_not_verified_yet:"L'artista deve prima verificare l'email",
  missing_fields_for_publish:'Profilo incompleto: mancano dei campi obbligatori prima di pubblicare',
  itunes_url_invalid:'Inserisci un link valido a un profilo artista Apple Music/iTunes',
  itunes_lookup_failed:'Verifica non disponibile al momento, riprova più tardi',
  itunes_artist_not_found:'Profilo Apple Music non trovato: controlla il link',
  forbidden_management:'Accesso riservato agli account agenzia',
  account_pending:'Account in attesa di approvazione dallo staff 148',
  forbidden_not_owner:'Questo artista non è gestito dal tuo account',
  applemusic_required:'Serve il link Apple Music per verificare l’artista',
  not_eligible:'Artista non idoneo: servono almeno 4 brani pubblicati negli ultimi 2 anni',
  forbidden_not_super_admin:'Azione riservata ai super admin: il tuo account admin ha privilegi ridotti',
  cannot_edit_self:'Non puoi modificare il tuo stesso account admin da qui', not_an_admin:'Non è un admin',
};
const errMsg = e => {
  const base = ERR[e?.message] || 'Errore: ' + (e?.message || 'imprevisto');
  const fields = e?.data?.fields;
  return (Array.isArray(fields) && fields.length) ? `${base}: ${fields.join(', ')}` : base;
};

/* toast */
function toast(msg, isErr = false) {
  let t = document.querySelector('.toast');
  if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = msg; t.classList.toggle('err', isErr);
  requestAnimationFrame(() => t.classList.add('show'));
  clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 3000);
}

/* ---- Cookie consent (GDPR / ePrivacy): un'unica categoria facoltativa "terze parti"
   (Spotify, YouTube/Twitch, mappe OpenStreetMap/CARTO). I cookie tecnici (sessione) sono
   sempre attivi e non richiedono consenso. Scelta salvata in localStorage, non richiede
   consenso essa stessa in quanto strettamente necessaria a ricordare la preferenza. ---- */
const COOKIE_CONSENT_KEY = 'roster_cookie_consent_v1';
const _gatedMapInit = {};

function getCookieConsent() {
  try {
    const v = JSON.parse(localStorage.getItem(COOKIE_CONSENT_KEY));
    if (v && typeof v.thirdparty === 'boolean') return v;
  } catch (e) {}
  return null;
}
function hasThirdPartyConsent() { const c = getCookieConsent(); return !!(c && c.thirdparty); }

function setCookieConsent(thirdparty) {
  const v = { necessary: true, thirdparty: !!thirdparty, ts: Date.now() };
  localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify(v));
  hideCookieBanner();
  if (v.thirdparty) {
    applyCookieGates();
    Object.entries(_gatedMapInit).forEach(([id, fn]) => {
      const el = document.getElementById(id);
      if (el && el.querySelector('.cookie-gate')) { el.innerHTML = ''; fn(); }
    });
  }
  document.dispatchEvent(new CustomEvent('cookieconsentchange', { detail: v }));
}

/* pop-up di consenso iniziale, centrato in pagina (nessuna chiusura cliccando fuori:
   richiede una scelta esplicita). Riusa lo stesso stile overlay/modal del resto del sito. */
function renderCookieBanner() {
  applyCookieGates();
  if (getCookieConsent() || document.getElementById('cookieConsentModal')) return;
  const ov = document.createElement('div');
  ov.id = 'cookieConsentModal'; ov.className = 'overlay cookie-consent-modal';
  ov.innerHTML = `
    <div class="modal" style="max-width:480px">
      <div class="mhead"><h3>La tua privacy</h3></div>
      <div class="mbody">
        <p>Usiamo cookie tecnici essenziali per il funzionamento del sito (es. login). Con il tuo consenso carichiamo anche contenuti di terze parti (player Spotify, video YouTube/Twitch, mappe) che impostano propri cookie. Vedi la <a href="/cookie.html">Cookie Policy</a>.</p>
      </div>
      <div class="mfoot">
        <button type="button" class="btn ghost sm" onclick="openCookiePrefs()">Personalizza</button>
        <div>
          <button type="button" class="btn outline sm" onclick="setCookieConsent(false)">Rifiuta</button>
          <button type="button" class="btn primary sm" onclick="setCookieConsent(true)">Accetta tutti</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(ov);
  ov.classList.add('open');
}
function hideCookieBanner() {
  document.getElementById('cookieConsentModal')?.remove();
  closeCookiePrefs();
}

/* modale "Preferenze cookie": riapribile in qualsiasi momento dal footer o dalla cookie policy */
function openCookiePrefs() {
  let ov = document.getElementById('cookiePrefsModal');
  if (!ov) { ov = document.createElement('div'); ov.className = 'overlay'; ov.id = 'cookiePrefsModal'; document.body.appendChild(ov); }
  const c = getCookieConsent() || { thirdparty: false };
  ov.innerHTML = `
    <div class="modal" style="max-width:480px">
      <div class="mhead"><h3>Preferenze cookie</h3><button type="button" class="iconbtn" onclick="closeCookiePrefs()">✕</button></div>
      <div class="mbody">
        <div class="cookie-cat">
          <div class="cookie-cat-head"><strong>Cookie tecnici necessari</strong><span class="cookie-always">Sempre attivi</span></div>
          <p>Indispensabili per login e sessione. Non richiedono consenso.</p>
        </div>
        <div class="cookie-cat">
          <div class="cookie-cat-head"><strong>Contenuti di terze parti</strong>
            <label class="switch" style="margin:0"><input type="checkbox" id="cp_thirdparty"${c.thirdparty ? ' checked' : ''}><span class="slider"></span></label>
          </div>
          <p>Player Spotify, video YouTube/Twitch e mappe (OpenStreetMap/CARTO): attivandoli accetti i loro cookie.</p>
        </div>
      </div>
      <div class="mfoot"><span></span><button type="button" class="btn primary" onclick="saveCookiePrefs()">Salva preferenze</button></div>
    </div>`;
  ov.classList.add('open');
}
function closeCookiePrefs() { document.getElementById('cookiePrefsModal')?.classList.remove('open'); }
function saveCookiePrefs() { setCookieConsent(document.getElementById('cp_thirdparty').checked); }

/* Href sicuro per un valore social (usato da artista.html e link.html): un URL http/s passa
 * intatto, un @handle/handle nudo viene espanso per le piattaforme note. Qualsiasi altro schema
 * URI (javascript:, data:...) diventa '#': il valore arriva da un campo profilo compilato
 * dall'artista, senza questo filtro sarebbe una XSS memorizzata cliccabile sulla pagina pubblica. */
function socialHref(k, v) {
  if (/^https?:\/\//i.test(v)) return v;
  if (/^[a-z][a-z0-9+.-]*:/i.test(v)) return '#';
  const h = v.replace(/^@/, '');
  return ({instagram:'https://instagram.com/'+h, facebook:'https://facebook.com/'+h, tiktok:'https://tiktok.com/@'+h,
    youtube:'https://youtube.com/'+h, twitch:'https://twitch.tv/'+h}[k]) || v;
}

/* contenuti embed di terze parti (Spotify/YouTube/Twitch): bloccati finché non c'è consenso */
function gatedEmbed(html, label) {
  if (hasThirdPartyConsent()) return html;
  return `<div class="cookie-gate" data-embed="${encodeURIComponent(html)}">
    <p>Contenuto ${esc(label)} bloccato: richiede cookie di terze parti.</p>
    <button type="button" class="btn dark sm" onclick="setCookieConsent(true)">Attiva ${esc(label)}</button>
  </div>`;
}
function applyCookieGates() {
  if (!hasThirdPartyConsent()) return;
  document.querySelectorAll('.cookie-gate[data-embed]').forEach(el => {
    const tmp = document.createElement('div');
    tmp.innerHTML = decodeURIComponent(el.dataset.embed);
    el.replaceWith(...tmp.childNodes);
  });
}

/* mappe (Leaflet/CARTO): init rimandata finché non c'è consenso terze parti */
function initGatedMap(containerId, initFn) {
  _gatedMapInit[containerId] = initFn;
  const el = document.getElementById(containerId);
  if (!el) return;
  if (hasThirdPartyConsent()) { initFn(); return; }
  el.innerHTML = `<div class="cookie-gate map-gate">
    <p>La mappa carica riquadri da OpenStreetMap/CARTO (terze parti).</p>
    <button type="button" class="btn dark sm" onclick="setCookieConsent(true)">Attiva mappa</button>
  </div>`;
}

document.addEventListener('DOMContentLoaded', renderCookieBanner);

/* sessione corrente (cache in memoria) */
let _me;
async function getMe(force = false) {
  if (_me && !force) return _me;
  try { _me = (await api('me.php')).user ? await api('me.php') : {user:null}; }
  catch (e) { _me = {user:null}; }
  return _me;
}

/* icone generi (per category bar / chips) */
const GENRE_ICONS = {
  pop:'🎤', rock:'🎸', indie:'🎧', cantautore:'🎼', 'rap-hiphop':'🎙️', trap:'💿',
  elettronica:'🎛️', 'house-techno':'🔊', jazz:'🎷', blues:'🎺', 'funk-soul':'🕺',
  reggae:'🥁', folk:'🪕', metal:'🤘', punk:'⚡', classica:'🎻',
  'tributo-cover':'🎭', 'dance-commerciale':'🪩', world:'🌍', hard:'🔞', format:'🎪'
};

/* tipi di show (ex "formazione") — slug ↔ etichetta, condivisi in tutto il frontend */
const SHOW_TYPES = [['live_dj','Live con DJ'],['dj_set','DJ Set'],['acustico','Acustico'],['live_band','Live Band'],['meet_greet','Meet & Greet']];
const SHOW_LABEL = Object.fromEntries(SHOW_TYPES);
function showLabel(v){ return SHOW_LABEL[v] || ''; }
function showOptions(sel){ return SHOW_TYPES.map(([v,l]) => `<option value="${v}"${sel===v?' selected':''}>${l}</option>`).join(''); }

/* header dinamico stile Airbnb. center = HTML opzionale per l'area di ricerca centrale */
async function renderNav(center = '') {
  const el = document.getElementById('nav'); if (!el) return;
  const me = await getMe(true);
  const u = me.user;
  let right;
  const navic = (icon, label, attrs) => `<${attrs.href?'a':'button'} class="navicon" ${attrs.href?`href="${attrs.href}"`:'type="button"'} ${attrs.onclick?`onclick="${attrs.onclick}"`:''} title="${label}"><span class="ic">${icon}</span>${label}</${attrs.href?'a':'button'}>`;
  if (u) {
    const mine = u.role === 'admin'
      ? navic('🔍','Cerca',{onclick:'openSearchPopup()'}) + navic('🛠️','Admin',{href:'/admin'})
      : u.role === 'artist'
      ? navic('👤','Profilo',{href:'/profilo.html'}) + navic('📨','Richieste',{href:'/richieste.html'})
      : u.role === 'management'
      ? navic('🔍','Cerca',{onclick:'openSearchPopup()'}) + navic('🎧','Roster',{href:'/management.html'}) + navic('📨','Richieste',{href:'/richieste.html'})
      : navic('🔍','Cerca',{onclick:'openSearchPopup()'}) + navic('📨','Richieste',{href:'/richieste.html'});
    const accountLink = u.role === 'artist' ? '' : navic('⚙️','Account',{href:'/account.html'});
    const menuLinks = `${mine}${accountLink}${navic('👋','Esci',{onclick:'logout()'})}`;
    right = `<div class="nav-actions">${menuLinks}</div>`;
  } else {
    right = `
      <div class="nav-actions">
        ${navic('🔍','Cerca',{onclick:'openSearchPopup()'})}
        ${navic('🔑','Accedi',{href:'/accedi.html'})}
        ${navic('📝','Registrati',{href:'/registrati.html'})}
      </div>`;
  }
  el.innerHTML = `<div class="container">
    <a class="logo" href="/">Booking<span style="color:var(--txt)"> Roster</span></a>
    <div id="navCenter" style="flex:1;display:flex;justify-content:center">${center}</div>
    <span></span>${right}
  </div>`;
  renderFooter();
}

/* footer condiviso (dati SHADE-OFF S.R.L.S.) */
function renderFooter() {
  if (document.querySelector('.site-footer')) return;
  const f = document.createElement('footer');
  f.className = 'site-footer';
  f.innerHTML = `<div class="container">
    <div class="foot-grid">
      <div class="foot-brand">
        <div class="logo">Booking Roster</div>
        <p>La piattaforma che collega artisti emergenti e promoter. Un progetto SHADE-OFF S.R.L.S. · Latina, Lazio.</p>
      </div>
      <div class="foot-col">
        <h4>Piattaforma</h4>
        <ul>
          <li><a href="/">Cerca artisti</a></li>
          <li><a href="/mappa.html">Mappa artisti</a></li>
          <li><a href="/calendario.html">Calendario artisti</a></li>
          <li><a href="/registrati-artista.html">Diventa artista</a></li>
          <li><a href="/accedi.html">Area promoter</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <h4>Contatti</h4>
        <ul>
          <li><a href="/contatti.html">Pagina contatti</a></li>
          <li><a href="mailto:support@bookingroster.it">support@bookingroster.it</a></li>
        </ul>
      </div>
      <div class="foot-col">
        <h4>Legale</h4>
        <ul>
          <li><a href="/faq.html">FAQ</a></li>
          <li><a href="/privacy.html">Privacy Policy</a></li>
          <li><a href="/cookie.html">Cookie Policy</a></li>
          <li><a href="/termini.html">Termini di Servizio</a></li>
          <li><button type="button" class="linklike" onclick="openCookiePrefs()">Preferenze cookie</button></li>
        </ul>
      </div>
    </div>
    <div class="foot-bottom">
      <span>© 2026 SHADE-OFF S.R.L.S. · P.IVA IT03133050595</span>
    </div>
  </div>`;
  document.body.appendChild(f);
}

/* Pin mappa raggruppati per location: se più artisti condividono la città,
   un solo pin con badge "+n" e popup che li elenca tutti. (usa Leaflet L, solo su pagine mappa) */
function renderArtistPins(map, layer, list, opts = {}) {
  layer.clearLayers();
  const cap = s => s ? s[0].toUpperCase() + s.slice(1) : '';
  const cachet = a => { if (opts.locked) return opts.pendingVerification ? '🔒 Verifica in corso' : '🔒 Accedi per il prezzo'; const v = a.cachet_min ?? a.cachet_max; if (v == null) return 'Trattativa riservata'; if (v === 0) return 'Disponibile, senza impegno'; return 'da ' + eur(v) + ' a serata'; };
  const groups = {};
  list.forEach(a => {
    if (a.lat == null || a.lng == null) return;
    const key = (+a.lat).toFixed(4) + ',' + (+a.lng).toFixed(4);
    (groups[key] = groups[key] || { lat: +a.lat, lng: +a.lng, items: [] }).items.push(a);
  });
  const pts = [];
  Object.values(groups).forEach(g => {
    const a = g.items[0], n = g.items.length;
    const badge = n > 1 ? `<span class="pin-count">+${n - 1}</span>` : '';
    const inner = a.photo_url
      ? `<img src="${esc(a.photo_url)}" referrerpolicy="no-referrer" alt="">`
      : `<div class="fallback">🎵</div>`;
    const html = `<div class="pin ${a.verified ? 'vok' : ''}">${inner}${badge}</div>`;
    const icon = L.divIcon({ className: 'artist-pin', html, iconSize: [46, 46], iconAnchor: [23, 46], popupAnchor: [0, -44] });
    let pop;
    const vchk = x => x.verified ? '<span class="vchk" title="Artista verificato">✓</span>' : '';
    if (n === 1) {
      pop = `<div class="pp"><div class="nm">${esc(a.stage_name || 'Artista')}${vchk(a)}</div>
        <div class="mt">${esc(showLabel(a.formazione))}${a.comune ? ' · ' + esc(a.comune) : ''}</div>
        <div style="font-weight:600;margin-bottom:8px">${cachet(a)}</div>
        <a class="btn primary sm" href="/${esc(a.slug || '')}">Vedi profilo</a></div>`;
    } else {
      pop = `<div class="pp"><div class="nm">${n} artisti${a.comune ? ' · ' + esc(a.comune) : ''}</div>
        <div style="max-height:230px;overflow:auto;margin-top:6px">
        ${g.items.map(x => `<a href="/${esc(x.slug || '')}" style="display:flex;gap:10px;align-items:center;padding:8px 2px;text-decoration:none;color:inherit;border-top:1px solid var(--line2)">
          <span style="width:36px;height:36px;border-radius:50%;overflow:hidden;flex:none;background:#eee;display:flex;align-items:center;justify-content:center;font-size:15px">${x.photo_url ? `<img src="${esc(x.photo_url)}" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover">` : '🎵'}</span>
          <span style="min-width:0"><b style="display:block">${esc(x.stage_name || 'Artista')}${vchk(x)}</b><span style="color:var(--muted);font-size:12px">${cachet(x)}</span></span></a>`).join('')}
        </div></div>`;
    }
    L.marker([g.lat, g.lng], { icon }).addTo(layer).bindPopup(pop, { maxWidth: 290 });
    pts.push([g.lat, g.lng]);
  });
  if (opts.fit !== false) {
    if (pts.length > 1) map.fitBounds(pts, { padding: [50, 50], maxZoom: 11 });
    else if (pts.length === 1) map.setView(pts[0], 10);
  }
}

async function logout() { await api('me.php?logout=1', {method:'POST'}); location.href = '/'; }

/* ---- Popup "Cerca" (header, utenti non loggati): stessi filtri che vedrebbero su "/" ---- */
const SP_BUDGET_MAX_DEFAULT = 3000;   // fallback se il roster non ha ancora nessun cachet impostato
let _searchGenres;
async function loadSearchGenres(){
  if (!_searchGenres) _searchGenres = (await api('genres.php')).genres || [];
  return _searchGenres;
}
async function openSearchPopup(){
  let ov = document.getElementById('searchPopup');
  if (!ov) { ov = document.createElement('div'); ov.className = 'overlay'; ov.id = 'searchPopup'; document.body.appendChild(ov); }
  const genres = await loadSearchGenres();
  // Il filtro budget ha senso solo per chi vede davvero i cachet (admin o promoter approvato):
  // per non loggati, artisti e promoter in attesa di verifica i prezzi sono comunque nascosti.
  const me = await getMe();
  const canSeePrices = !!(me.user && (me.user.role === 'admin' || (me.user.role === 'promoter' && me.user.status === 'active')));
  let budgetMax = SP_BUDGET_MAX_DEFAULT;
  if (canSeePrices) {
    try { const r = await api('artists-search.php?limit=1'); if (r.roster_max_cachet > 0) budgetMax = r.roster_max_cachet; } catch (e) {}
  }
  const budgetField = !canSeePrices ? '' : `
          <div class="field">
            <label>Budget massimo · <span id="sp_budgetVal">${eur(budgetMax)}</span></label>
            <input type="range" id="sp_budget" min="0" max="${budgetMax}" step="50" value="${budgetMax}"
              style="width:100%;accent-color:var(--brand)" oninput="sp_budgetVal.textContent = eur(+sp_budget.value)">
          </div>`;
  ov.innerHTML = `
    <div class="modal" style="max-width:480px">
      <div class="mhead"><button type="button" class="iconbtn" onclick="closeSearchPopup()">✕</button><h3>Cerca artisti</h3><span style="width:34px"></span></div>
      <div class="mbody">
        <form onsubmit="return submitSearchPopup(event)">
          <div class="field"><label>Cerca per nome</label><input type="text" id="sp_q" placeholder="Nome artista o parola chiave"></div>
          ${budgetField}
          <div class="field" style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:8px">
              <label class="switch" style="margin:0" title="Solo artisti entro 250 km da te"><input type="checkbox" id="sp_gps"><span class="slider"></span></label>
              <span style="font-weight:600">Vicino a me</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <label class="switch" style="margin:0" title="Solo artisti disponibili a trattare il cachet"><input type="checkbox" id="sp_tratt"><span class="slider"></span></label>
              <span style="font-weight:600">Trattabile</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <label class="switch" style="margin:0" title="Solo artisti con promozione attiva"><input type="checkbox" id="sp_promo"><span class="slider"></span></label>
              <span style="font-weight:600">Promo</span>
            </div>
          </div>
          <div class="field"><label>Generi</label>
            <div class="chips">${genres.map(g => `<span class="chip" data-slug="${esc(g.slug)}" onclick="pickSearchGenre(this)">${GENRE_ICONS[g.slug] || '🎵'} ${esc(g.name)}</span>`).join('')}</div>
          </div>
          <div class="field"><label>Tipo di show</label>
            <div class="chips">${SHOW_TYPES.map(([v, l]) => `<span class="chip" data-v="${v}" onclick="pickSearchShow(this)">${esc(l)}</span>`).join('')}</div>
          </div>
          <button class="btn primary" style="width:100%;margin-top:6px">Cerca</button>
        </form>
        <button type="button" class="btn ghost" style="width:100%;margin-top:8px" onclick="goSearchAll()">Vedi tutti gli artisti</button>
      </div>
    </div>`;
  ov.classList.add('open');
}
function closeSearchPopup(){ document.getElementById('searchPopup')?.classList.remove('open'); }
function goSearchAll(){ location.href = '/'; }
function pickSearchGenre(el){ location.href = '/genere/' + encodeURIComponent(el.dataset.slug); }
function pickSearchShow(el){ location.href = '/?' + new URLSearchParams({ formazione: el.dataset.v }).toString(); }
function submitSearchPopup(e){
  e.preventDefault();
  const p = new URLSearchParams();
  const q = document.getElementById('sp_q').value.trim();
  if (q) p.set('q', q);
  const budgetEl = document.getElementById('sp_budget');
  if (budgetEl && +budgetEl.value < +budgetEl.max) p.set('cachet_max', budgetEl.value);
  if (document.getElementById('sp_tratt').checked) p.set('trattabile', '1');
  if (document.getElementById('sp_promo').checked) p.set('promo', '1');
  if (document.getElementById('sp_gps').checked && navigator.geolocation && window.isSecureContext) {
    navigator.geolocation.getCurrentPosition(
      pos => { p.set('lat', pos.coords.latitude); p.set('lng', pos.coords.longitude); p.set('max_km', '250'); location.href = '/?' + p.toString(); },
      () => { location.href = '/?' + p.toString(); },
      { enableHighAccuracy: false, timeout: 8000, maximumAge: 600000 }
    );
    return false;
  }
  location.href = '/?' + p.toString();
  return false;
}
document.addEventListener('click', e => { if (e.target.classList.contains('overlay') && e.target.id !== 'cookieConsentModal') e.target.classList.remove('open'); });
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function thousands(n){return Math.round(Number(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.');}
function eur(n){return n==null?'—':'€'+thousands(n);}
/* formatta un numero di follower/ascoltatori in K/M (usato da profilo artista e card home) */
function fmt(n){ if(n==null)return'—'; if(n>=1e6)return(n/1e6).toFixed(n>=1e7?0:1).replace('.',',')+'M';
  if(n>=1e3)return(n/1e3).toFixed(n>=1e4?0:1).replace('.',',')+'K'; return Number(n).toLocaleString('it-IT'); }

/* icone social condivise (profilo artista + card home) */
const SM={spotify:['Spotify','#1db954'],instagram:['Instagram','#e1306c'],facebook:['Facebook','#1877f2'],
  tiktok:['TikTok','#111'],youtube:['YouTube','#f00'],twitch:['Twitch','#9146ff'],applemusic:['Apple Music','#fa243c']};
const SOCIAL_SVG={
  spotify:'<path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.36-.66.48-1.02.24-2.82-1.74-6.36-2.1-10.56-1.14-.42.12-.78-.18-.9-.54-.12-.42.18-.78.54-.9 4.56-1.02 8.52-.6 11.64 1.32.42.18.48.66.3 1.02zm1.44-3.3c-.3.42-.84.6-1.26.3-3.24-1.98-8.16-2.58-11.94-1.38-.48.12-1.02-.12-1.14-.6-.12-.48.12-1.02.6-1.14C9.6 9.9 15 10.56 18.72 12.84c.36.18.54.78.24 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.3c-.6.18-1.2-.18-1.38-.72-.18-.6.18-1.2.72-1.38 4.26-1.26 11.28-1.02 15.72 1.62.54.3.72 1.02.42 1.56-.3.42-1.02.6-1.56.3z"/>',
  instagram:'<path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85 0 3.2-.01 3.58-.07 4.85-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.64.07-4.85.07-3.2 0-3.58-.01-4.85-.07-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.64-.07-4.85 0-3.2.01-3.58.07-4.85.15-3.23 1.66-4.77 4.92-4.92 1.27-.06 1.65-.07 4.85-.07zM12 0C8.74 0 8.33.01 7.05.07 2.7.27.27 2.69.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.2-4.35-2.62-6.78-6.98-6.98C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 100 12.32 6.16 6.16 0 000-12.32zM12 16a4 4 0 110-8 4 4 0 010 8zm6.41-11.85a1.44 1.44 0 100 2.88 1.44 1.44 0 000-2.88z"/>',
  facebook:'<path d="M24 12.07C24 5.44 18.63.07 12 .07S0 5.44 0 12.07c0 5.99 4.39 10.95 10.13 11.85v-8.38H7.08v-3.47h3.05V9.43c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87v2.25h3.33l-.53 3.47h-2.8v8.38C19.61 23.02 24 18.06 24 12.07z"/>',
  youtube:'<path d="M23.5 6.19a3.02 3.02 0 00-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 00.5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 002.12 2.14c1.87.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 002.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81zM9.55 15.57V8.43L15.82 12l-6.27 3.57z"/>',
  tiktok:'<path d="M12.53.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03C3.23 25.74 1.81 23.56 1.6 21.22c-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/>',
  twitch:'<path d="M11.57 4.71h1.72v5.14h-1.72zm4.72 0H18v5.14h-1.71zM6 0L1.71 4.29v15.42h5.14V24l4.29-4.29h3.43L22.29 12V0zm14.57 11.14l-3.43 3.43h-3.43l-3 3v-3H6.86V1.71h13.71z"/>',
  website:'<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93A8 8 0 014.06 13H9v2a2 2 0 002 2v2.93zM17.9 17.39A2 2 0 0016 16h-1v-3a1 1 0 00-1-1H8v-2h2a1 1 0 001-1V7h2a2 2 0 002-2v-.41A8 8 0 0117.9 17.39z"/>',
  applemusic:'<path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/>'
};

/* per le card: fino a 3 icone dei social con i numeri più alti (una per piattaforma, il metro
   migliore se ce n'è più di uno, es. spotify_followers vs spotify_listeners) */
const SOCIAL_STAT_PLATFORM = {
  spotify_followers:'spotify', spotify_listeners:'spotify', youtube_subs:'youtube',
  tiktok_followers:'tiktok', twitch_followers:'twitch', instagram_followers:'instagram', facebook_followers:'facebook',
};
function topSocialStats(stats){
  const byPlatform = {};
  for (const [k, v] of Object.entries(stats || {})) {
    const plat = SOCIAL_STAT_PLATFORM[k];
    if (!plat || v == null || v <= 0) continue;
    if (!byPlatform[plat] || v > byPlatform[plat]) byPlatform[plat] = v;
  }
  return Object.entries(byPlatform).sort((a, b) => b[1] - a[1]).slice(0, 3);
}
function miniSocialIcon(plat, v){
  const svg = SOCIAL_SVG[plat]; if (!svg) return '';
  return `<span class="soc-mini" title="${SM[plat] ? esc(SM[plat][0]) : ''}">
    <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor">${svg}</svg>${fmt(v)}</span>`;
}

/* autocomplete comuni: /assets/comuni.json (Italia, ["Nome","PR"]) + /assets/europe-cities.json
   (resto d'Europa, ["Nome","Paese"]) + /assets/world-capitals.json (capitali extra-europee,
   ["Nome","Paese"]) + /assets/us-cities.json (principali città USA, ["Nome","Paese"]) unite in
   un'unica lista. Le città non italiane non hanno provincia: il campo Prov. resta vuoto per
   loro (colonna DB CHAR(2), solo per le sigle italiane). */
let _comuni;
async function loadComuni() {
  if (!_comuni) {
    const [it, eu, cap, us] = await Promise.all([
      fetch('/assets/comuni.json').then(r => r.json()),
      fetch('/assets/europe-cities.json').then(r => r.json()),
      fetch('/assets/world-capitals.json').then(r => r.json()),
      fetch('/assets/us-cities.json').then(r => r.json()),
    ]);
    _comuni = it.map(c => [c[0], c[1], true])
      .concat(eu.map(c => [c[0], c[1], false]))
      .concat(cap.map(c => [c[0], c[1], false]))
      .concat(us.map(c => [c[0], c[1], false]));
  }
  return _comuni;
}
/* true se il comune è italiano (specchio di is_italian_comune() in api/_geo.php). Se l'elenco
   non è ancora stato caricato risponde true (assume italiano): stesso comportamento prudente
   di prima, la provincia resta richiesta finché la lista non è pronta. */
function isItalianComune(name) {
  if (!_comuni) return true;
  const n = (name || '').trim().toLowerCase();
  if (!n) return false;
  const hit = _comuni.find(c => c[0].toLowerCase() === n);
  return hit ? !!hit[2] : false;
}
async function comuniAutocomplete(input, onPick) {
  await loadComuni();
  const wrap = document.createElement('div'); wrap.className = 'ac-list';
  input.parentNode.style.position = 'relative'; input.parentNode.appendChild(wrap);
  let sel = -1, items = [];
  const close = () => { wrap.classList.remove('show'); sel = -1; };
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    if (q.length < 2) return close();
    items = _comuni.filter(c => c[0].toLowerCase().startsWith(q)).slice(0, 8);
    if (!items.length) return close();
    wrap.innerHTML = items.map((c,i) => `<div class="ac-item" data-i="${i}">${esc(c[0])} <span style="color:var(--muted)">(${esc(c[1])})</span></div>`).join('');
    wrap.classList.add('show');
    wrap.querySelectorAll('.ac-item').forEach(el => el.onclick = () => pick(+el.dataset.i));
  });
  const pick = i => { const c = items[i]; const prov = c[2] ? c[1] : ''; input.value = c[0]; input.dataset.prov = prov; close(); onPick && onPick(c[0], prov); };
  input.addEventListener('keydown', e => {
    if (!wrap.classList.contains('show')) return;
    if (e.key === 'ArrowDown') { sel = Math.min(sel+1, items.length-1); }
    else if (e.key === 'ArrowUp') { sel = Math.max(sel-1, 0); }
    else if (e.key === 'Enter') { e.preventDefault(); if (sel>=0) pick(sel); return; }
    else return;
    e.preventDefault();
    wrap.querySelectorAll('.ac-item').forEach((el,i)=>el.classList.toggle('sel',i===sel));
  });
  input.addEventListener('blur', () => setTimeout(close, 150));
}
