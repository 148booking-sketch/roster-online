/* Booking Roster — form artista CONDIVISO tra profilo.html (self-edit) e admin.html (admin).
 * Un'unica fonte per markup + logica dei campi comuni: se cambia qui, cambia ovunque.
 * Uso:
 *   artistFormMount('mountId', prefix)         una volta, all'avvio pagina (inietta HTML + autocomplete comune)
 *   await loadGenres()                          una volta, prima di populate/reset
 *   artistFormPopulate(prefix, profileObj)      per pre-compilare con i dati esistenti
 *   artistFormReset(prefix)                     per un form vuoto/di default (nuovo artista)
 *   artistFormCollect(prefix)                   per leggere i valori da inviare al backend
 *   setPhotoFromServer(prefix, url)              per aggiornare l'anteprima dopo il salvataggio
 * `prefix` è il prefisso degli id (es. '' in profilo.html, 'a_' in admin.html) — permette
 * di montare lo stesso form due volte nella stessa pagina senza collisioni di id.
 */

const GEAR_BASE = ['Impianto audio (PA)','Mixer','Casse spia / Monitor','Microfoni con aste','Batteria','Ampli basso','Ampli chitarra','Tastiera / Piano','Consolle DJ','Luci'];
const GEAR_BRING = [...GEAR_BASE, 'Backline completa', 'Auto Tune'];
const GEAR_NEED  = [...GEAR_BASE, 'Palco', 'Video wall', 'In Ear Monitor'];
const GEAR_NEED_DEFAULT = ['Palco','Luci','Impianto audio (PA)','Mixer','Casse spia / Monitor','Microfoni con aste'];

/* ---- Genres (cache condivisa) ---- */
let GENRES_CACHE = [];
async function loadGenres() {
  if (!GENRES_CACHE.length) GENRES_CACHE = (await api('genres.php')).genres || [];
  return GENRES_CACHE;
}

/* ---- Markup condiviso ---- */
function artistFormHTML(p) {
  return `
    <section class="fsec info">
      <h2 class="fsec-h">Informazioni personali</h2>
      <div class="row">
        <div class="field"><label>Nome d'arte *</label><input id="${p}stage_name" required></div>
        <div class="field"><label>Label Musicale</label><input id="${p}label" placeholder="Nome della label"></div>
        <div class="field"><label>Agenzia</label><input id="${p}management" placeholder="Nome dell'agenzia"></div>
      </div>
      <div class="hint" id="${p}nameHint" style="margin:8px 0 14px">La foto profilo viene presa automaticamente da <b>Spotify</b> (link nella sezione Musica). Se non è collegato, assegniamo in automatico un'icona a tema in base al <b>primo genere</b> scelto qui sotto.</div>
      <div class="field">
        <label>Bio</label>
        <textarea id="${p}bio" placeholder="Chi è, cosa propone, esperienze live..."></textarea>
        <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:10px 12px;background:#f7f7f7;border:1px solid var(--line2);border-radius:10px">
          <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;margin:0">
            <input type="checkbox" id="${p}bio_from_spotify" onchange="bioSpotifyUI('${p}')" style="width:auto">
            Usa la bio da Spotify (Artist's Pick) e tienila sincronizzata
          </label>
        </div>
        <div class="hint" id="${p}bioSpotifyHint" style="display:none;margin-top:6px">Aggiornata automaticamente dal testo <b>Artist's Pick</b> del tuo profilo Spotify: qui non è modificabile a mano. Ricorda di tenerlo aggiornato su Spotify!</div>
      </div>

      <h3>Disponibilità (Google Calendar)</h3>
      <div class="row">
        <div class="field" style="flex:2">
          <label>Link iCal del calendario</label>
          <input id="${p}calendar_url" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
          <div class="hint">Google Calendar → <b>Impostazioni del calendario</b> → <b>Indirizzo segreto in formato iCal</b>. I promoter vedranno solo <b>quali date sono libere/occupate</b>.</div>
        </div>
        <div class="field"><label>Telefono</label><input id="${p}phone" placeholder="+39..."></div>
      </div>

      <div class="row">
        <div class="autocomplete field"><label>Comune base</label><input id="${p}comune" placeholder="Es. Latina"></div>
        <div class="field" style="max-width:110px"><label>Prov.</label><input id="${p}provincia" maxlength="2" placeholder="LT"></div>
        <div class="field" style="max-width:150px"><label>Raggio (km)</label><input id="${p}travel_max_km" type="number" min="0" placeholder="150"></div>
      </div>
    </section>

    <section class="fsec social" id="${p}socialSec">
      <h2 class="fsec-h">Link & social</h2>
      <div class="hint" style="margin:-4px 0 12px">Da Spotify/TikTok/YouTube/Twitch ricaviamo automaticamente le statistiche (ascoltatori, follower, iscritti).</div>
      <div class="row">
        <div class="field"><label>Sito web</label><input id="${p}website" placeholder="https://..."></div>
        <div class="field"><label>Apple Music</label><input id="${p}s_am" placeholder="https://music.apple.com/..."></div>
      </div>
      <div class="row">
        <div class="field"><label>Spotify (artista)</label><input id="${p}s_sp" placeholder="https://open.spotify.com/artist/..."></div>
        <div class="field"><label>Instagram</label><input id="${p}s_ig" placeholder="@handle o URL"></div>
        <div class="field"><label>Facebook</label><input id="${p}s_fb" placeholder="@pagina o URL"></div>
      </div>
      <div class="row">
        <div class="field"><label>TikTok</label><input id="${p}s_tt" placeholder="@handle o URL"></div>
        <div class="field"><label>YouTube</label><input id="${p}s_yt" placeholder="URL canale"></div>
        <div class="field"><label>Twitch</label><input id="${p}s_tw" placeholder="@handle o URL"></div>
      </div>
    </section>

    <section class="fsec music">
      <h2 class="fsec-h">Musica</h2>
      <div class="row">
        <div class="field"><label>Tipo di Show</label><div class="chips" id="${p}showChips"></div></div>
        <div class="field" style="max-width:150px"><label>On Stage</label><input id="${p}on_stage" type="number" min="0" placeholder="4"></div>
        <div class="field" style="max-width:170px"><label>Durata set (min)</label><input id="${p}durata_set_min" type="number" min="0" placeholder="90"></div>
      </div>
      <div class="field"><label>Generi <span class="hint" style="display:inline" id="${p}genreMaxHint">(max 3)</span></label><div class="chips" id="${p}genreChips"></div></div>
    </section>


    <section class="fsec money">
      <h2 class="fsec-h">Cachet & sconti</h2>
      <div class="row">
        <div class="field" style="max-width:195px;flex:none"><label>Trattativa riservata <span class="hint" style="display:inline" id="${p}trv_hint"></span></label>
          <div style="display:flex;align-items:center;height:38px">
            <span class="switch"><input type="checkbox" id="${p}trv_ris" onchange="trvRisUI('${p}')"><span class="slider"></span></span>
          </div>
        </div>
        <div class="field trvhide-${p}" style="min-width:140px"><label>Cachet a serata (€)</label><input id="${p}cachet" type="number" min="0" placeholder="500"></div>
        <div class="field trvhide-${p}" style="max-width:170px"><label>Cachet</label>
          <select id="${p}cachet_trattabile"><option value="1">Trattabile</option><option value="0">Non trattabile</option></select>
        </div>
        <div class="field trvhide-${p}" style="max-width:170px"><label>Viaggi</label>
          <select id="${p}rimborso_tipo" onchange="rimbUI('${p}')">
            <option value="da_concordare">Da concordare</option>
            <option value="incluso">Incluso nel cachet</option>
            <option value="forfait">Forfait fisso</option>
          </select></div>
        <div class="field trvhide-${p}" id="${p}wrap_forf" style="display:none;max-width:140px"><label>Forfait (€)</label><input id="${p}rimborso_forfait" type="number" min="0" placeholder="80"></div>
      </div>
      <div class="row trvhide-${p}">
        <div class="field"><label>Cachet PROMO (€) <span class="hint" style="display:inline">opzionale</span></label>
          <input id="${p}cachet_promo" type="number" min="0" placeholder="es. 400">
          <div class="hint">Se compilato, sul profilo appare il badge <b>PROMO</b> con il prezzo pieno barrato.</div></div>
        <div class="field" style="max-width:200px"><label>Promo valida fino al</label><input id="${p}promo_until" type="date"></div>
      </div>
      <div class="hint trvnote-${p}" style="display:none;margin:-6px 0 0">Con la trattativa riservata attiva, cachet, promo e condizioni viaggi NON compaiono sul profilo pubblico: i promoter vedono "Trattativa riservata".</div>
    </section>

    <section class="fsec tech">
      <h2 class="fsec-h">Rider tecnico</h2>
      <div class="field"><label>Scheda tecnica (URL Drive/Dropbox/PDF)</label><input id="${p}tech_sheet_url" type="url" placeholder="https://drive.google.com/..."></div>
      <div class="row" style="align-items:flex-start">
        <div class="field"><label>Cosa porta l'artista</label><div class="chips" id="${p}gearBring"></div></div>
        <div class="field"><label>Cosa serve sul posto</label><div class="chips" id="${p}gearNeed"></div></div>
      </div>
    </section>`;
}

/** Da chiamare UNA VOLTA per pagina: inietta l'HTML e collega l'autocomplete comune. */
function artistFormMount(containerId, p) {
  document.getElementById(containerId).innerHTML = artistFormHTML(p);
  comuniAutocomplete(document.getElementById(p + 'comune'), (n, pr) => {
    const el = document.getElementById(p + 'provincia'); if (el) el.value = pr;
  });
}

/* ---- Chips: generi (max 3) ---- */
function renderGenreChips(id, selectedIds) {
  const sel = new Set(selectedIds || []);
  document.getElementById(id).innerHTML = GENRES_CACHE.map(g =>
    `<span class="chip ${sel.has(g.id) ? 'on' : ''}" data-id="${g.id}" onclick="toggleGenreChip(this,'${id}')">${GENRE_ICONS[g.slug] || '🎵'} ${esc(g.name)}</span>`
  ).join('');
}
function toggleGenreChip(el, containerId) {
  const max = +document.getElementById(containerId)?.dataset.max || 3;
  if (!el.classList.contains('on') && document.querySelectorAll('#' + containerId + ' .chip.on').length >= max) {
    toast(`Puoi scegliere al massimo ${max} generi (illimitati per gli artisti verificati)`, true); return;
  }
  el.classList.toggle('on');
}
function genresSelected(containerId) { return [...document.querySelectorAll('#' + containerId + ' .chip.on')].map(c => +c.dataset.id); }

/* Artisti verificati: generi illimitati; non verificati: fino a 3.
 * Aggiorna il tetto e l'hint, e taglia la selezione attuale se supera il nuovo massimo
 * (es. l'admin toglie la spunta "Verificato" mentre erano già selezionati più generi). */
function setGenreVerified(p, verified) {
  const containerId = p + 'genreChips';
  const el = document.getElementById(containerId);
  if (!el) return;
  const max = verified ? 999 : 3;
  el.dataset.max = max;
  const hint = document.getElementById(p + 'genreMaxHint');
  if (hint) hint.textContent = verified ? '' : '(max 3)';
  const onChips = [...el.querySelectorAll('.chip.on')];
  if (onChips.length > max) {
    onChips.slice(max).forEach(c => c.classList.remove('on'));
    toast(`Gli artisti non verificati possono scegliere fino a 3 generi: tenuti i primi 3`, true);
  }
}

/* ---- Chips: tipo di show ---- */
function renderShowChips(id, selected) {
  document.getElementById(id).innerHTML = SHOW_TYPES.map(([v, l]) =>
    `<span class="chip ${v === selected ? 'on' : ''}" data-v="${v}" onclick="pickShowChip(this,'${id}')">${esc(l)}</span>`
  ).join('');
}
function pickShowChip(el, containerId) {
  document.querySelectorAll('#' + containerId + ' .chip').forEach(c => c.classList.remove('on'));
  el.classList.add('on');
}
function showSelected(containerId) { return document.querySelector('#' + containerId + ' .chip.on')?.dataset.v || 'live_band'; }

/* ---- Chips: backline (cosa porto / cosa serve) ---- */
function renderGearChips(containerId, selected, options) {
  const sel = new Set(selected || []);
  document.getElementById(containerId).innerHTML = (options || GEAR_BASE).map(g =>
    `<span class="chip ${sel.has(g) ? 'on' : ''}" onclick="this.classList.toggle('on')">${esc(g)}</span>`
  ).join('');
}
function gearSelected(containerId) { return [...document.querySelectorAll('#' + containerId + ' .chip.on')].map(c => c.textContent); }

/* ---- Bio da Spotify (disabilita/abilita il campo Bio manuale) ---- */
function bioSpotifyUI(p) {
  const cb = document.getElementById(p + 'bio_from_spotify');
  const bio = document.getElementById(p + 'bio');
  const hint = document.getElementById(p + 'bioSpotifyHint');
  if (!cb || !bio) return;
  // readOnly (non disabled): il testo sincronizzato da Spotify deve restare ben leggibile,
  // non spento come i campi "disabled" di serie del browser — così si vede che esiste ed è aggiornato.
  bio.readOnly = cb.checked;
  bio.style.background = cb.checked ? '#f1f1f3' : '';
  if (hint) hint.style.display = cb.checked ? '' : 'none';
}

/* ---- Rimborso (mostra/nasconde forfait) ---- */
/* Trattativa riservata: se attiva nasconde tutti i campi cachet/viaggi/promo.
 * Solo gli artisti VERIFICATI possono attivarla (lo switch resta disabilitato per gli altri;
 * il server la ignora comunque per i non verificati). */
function trvRisUI(p) {
  const cb = document.getElementById(p + 'trv_ris');
  if (!cb) return;
  const on = cb.checked;
  document.querySelectorAll('.trvhide-' + p).forEach(el => { el.style.display = on ? 'none' : ''; });
  const note = document.querySelector('.trvnote-' + p); if (note) note.style.display = on ? '' : 'none';
  if (!on) rimbUI(p);   // ripristina la visibilità condizionale del forfait
}
function setTrvVerified(p, verified) {
  const cb = document.getElementById(p + 'trv_ris');
  if (!cb) return;
  cb.disabled = !verified;
  if (!verified && cb.checked) cb.checked = false;
  const hint = document.getElementById(p + 'trv_hint');
  if (hint) hint.textContent = verified ? '' : '(solo verificati)';
  trvRisUI(p);
}

/* Campo "Agenzia": se l'artista è assegnato a un'agenzia registrata (manager_user_id),
 * mostra il suo nome reale e blocca il campo (niente testo libero disallineato dal dato vero).
 * Senza assegnazione resta un campo libero come prima. */
function setManagementLock(p, orgName) {
  const el = document.getElementById(p + 'management');
  if (!el) return;
  if (orgName) { el.value = orgName; el.readOnly = true; el.style.background = '#f1f1f3'; }
  else { el.readOnly = false; el.style.background = ''; }
}

function rimbUI(p) {
  const w = document.getElementById(p + 'wrap_forf'), s = document.getElementById(p + 'rimborso_tipo');
  if (w && s) w.style.display = s.value === 'forfait' ? '' : 'none';
}

/** Foto profilo: 100% automatica lato server (Spotify → icona dal 1° genere → invariata).
 *  Qui si aggiorna solo l'anteprima con l'URL che il backend ha calcolato. */
function setPhotoFromServer(p, url) {
  const el = document.getElementById(p + 'photoPrev');
  if (!el) return;   // l'anteprima foto non è più nel form: la foto resta gestita dal server
  el.innerHTML = url ? `<img src="${esc(url)}" alt="" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover">` : icon('music',30,1.4);
}

/* ---- Popolamento / reset / raccolta dati ---- */
function artistFormPopulate(p, prof) {
  const pf = prof || {};
  const set = (id, v) => { const el = document.getElementById(id); if (el && v != null) el.value = v; };
  set(p + 'stage_name', pf.stage_name);
  set(p + 'bio', pf.bio);
  const bioCb = document.getElementById(p + 'bio_from_spotify');
  if (bioCb) bioCb.checked = !!pf.bio_from_spotify;
  bioSpotifyUI(p);
  set(p + 'calendar_url', pf.calendar_url);
  set(p + 'comune', pf.comune);
  set(p + 'provincia', pf.provincia);
  set(p + 'phone', pf.phone);
  set(p + 'travel_max_km', pf.travel_max_km);
  set(p + 'website', pf.website);
  set(p + 'label', pf.label);
  set(p + 'management', pf.management);
  setManagementLock(p, pf.manager_user_id ? (pf.manager_org_name || null) : null);
  set(p + 'cachet', pf.cachet_min ?? pf.cachet_max);
  set(p + 'cachet_trattabile', pf.cachet_trattabile != null ? String(pf.cachet_trattabile) : '1');
  set(p + 'cachet_promo', pf.cachet_promo);
  set(p + 'promo_until', pf.promo_until);
  set(p + 'rimborso_tipo', pf.rimborso_tipo || 'da_concordare');
  set(p + 'rimborso_forfait', pf.rimborso_forfait);
  set(p + 'durata_set_min', pf.durata_set_min);
  set(p + 'on_stage', pf.componenti);
  set(p + 'tech_sheet_url', pf.tech_sheet_url);
  const soc = pf.socials ? (typeof pf.socials === 'string' ? JSON.parse(pf.socials) : pf.socials) : {};
  set(p + 's_sp', soc.spotify); set(p + 's_ig', soc.instagram); set(p + 's_fb', soc.facebook);
  set(p + 's_tt', soc.tiktok); set(p + 's_yt', soc.youtube); set(p + 's_tw', soc.twitch);
  set(p + 's_am', soc.applemusic);
  const parseArr = v => Array.isArray(v) ? v : (typeof v === 'string' && v ? JSON.parse(v) : []);
  renderGearChips(p + 'gearBring', parseArr(pf.gear_bring), GEAR_BRING);
  renderGearChips(p + 'gearNeed', (pf.gear_need != null ? parseArr(pf.gear_need) : GEAR_NEED_DEFAULT), GEAR_NEED);
  renderShowChips(p + 'showChips', pf.formazione || 'live_band');
  const genreIds = (pf.genres || []).map(g => (g && g.id != null) ? g.id : g);
  renderGenreChips(p + 'genreChips', genreIds);
  setGenreVerified(p, pf.verified == 1);
  const trvCb = document.getElementById(p + 'trv_ris');
  if (trvCb) trvCb.checked = pf.trattativa_riservata == 1 && pf.verified == 1;
  setTrvVerified(p, pf.verified == 1);
  setPhotoFromServer(p, pf.photo_url);
  rimbUI(p);
}

function artistFormReset(p) {
  ['stage_name', 'bio', 'calendar_url', 'comune', 'provincia', 'phone', 'travel_max_km', 'website',
   'label', 'management', 'cachet', 'cachet_promo', 'promo_until', 'rimborso_forfait',
   'durata_set_min', 'on_stage', 'tech_sheet_url', 's_sp', 's_ig', 's_fb', 's_tt', 's_yt', 's_tw', 's_am'
  ].forEach(f => { const el = document.getElementById(p + f); if (el) el.value = ''; });
  const trat = document.getElementById(p + 'cachet_trattabile'); if (trat) trat.value = '1';
  const rim = document.getElementById(p + 'rimborso_tipo'); if (rim) rim.value = 'da_concordare';
  const bioCb = document.getElementById(p + 'bio_from_spotify'); if (bioCb) bioCb.checked = false;
  bioSpotifyUI(p);
  setManagementLock(p, null);
  renderShowChips(p + 'showChips', 'live_band');
  renderGenreChips(p + 'genreChips', []);
  setGenreVerified(p, false);
  const trvCb = document.getElementById(p + 'trv_ris'); if (trvCb) trvCb.checked = false;
  setTrvVerified(p, false);
  renderGearChips(p + 'gearBring', [], GEAR_BRING);
  renderGearChips(p + 'gearNeed', GEAR_NEED_DEFAULT, GEAR_NEED);
  setPhotoFromServer(p, '');
  rimbUI(p);
}

function artistFormCollect(p) {
  const v = id => document.getElementById(p + id)?.value ?? '';
  return {
    stage_name: v('stage_name'),
    formazione: showSelected(p + 'showChips'),
    bio: v('bio'),
    bio_from_spotify: document.getElementById(p + 'bio_from_spotify')?.checked ? '1' : '0',
    calendar_url: v('calendar_url'),
    comune: v('comune'), provincia: v('provincia'),
    phone: v('phone'), travel_max_km: v('travel_max_km'),
    website: v('website'), label: v('label'), management: v('management'),
    genres: genresSelected(p + 'genreChips'),
    socials: { spotify: v('s_sp'), instagram: v('s_ig'), facebook: v('s_fb'), tiktok: v('s_tt'), youtube: v('s_yt'), twitch: v('s_tw'), applemusic: v('s_am') },
    cachet_min: v('cachet'), cachet_max: v('cachet'), cachet_trattabile: v('cachet_trattabile'),
    trattativa_riservata: document.getElementById(p + 'trv_ris')?.checked ? '1' : '0',
    cachet_promo: v('cachet_promo'), promo_until: v('promo_until'),
    rimborso_tipo: v('rimborso_tipo'), rimborso_forfait: v('rimborso_forfait'),
    durata_set_min: v('durata_set_min'),
    componenti: v('on_stage'),
    tech_sheet_url: v('tech_sheet_url'),
    gear_bring: gearSelected(p + 'gearBring'), gear_need: gearSelected(p + 'gearNeed'),
  };
}

/* ---- Campi obbligatori per pubblicare (specchio lato client di artist_publish_missing_fields()
 * in api/_admin.php): usata per mostrare all'artista/admin cosa manca PRIMA di provare a
 * pubblicare, non solo dopo un tentativo fallito. Tenere le due liste allineate. ---- */
function artistMissingFields(pf) {
  pf = pf || {};
  const soc = pf.socials ? (typeof pf.socials === 'string' ? JSON.parse(pf.socials) : pf.socials) : {};
  const filled = v => String(v ?? '').trim() !== '';
  const checks = {
    'Bio': filled(pf.bio), 'Calendario': filled(pf.calendar_url),
    'Comune': filled(pf.comune),
    // Non richiesta per un comune base estero (nessuna sigla provincia italiana esiste per lui).
    'Provincia': filled(pf.provincia) || !isItalianComune(pf.comune),
    'Telefono': filled(pf.phone),
    'Tipo di show': filled(pf.formazione), 'On stage': filled(pf.componenti),
    'Generi': (pf.genres || []).length > 0,
    'Spotify': filled(soc.spotify), 'Apple Music': filled(soc.applemusic), 'Instagram': filled(soc.instagram),
    'Cachet a serata': pf.cachet_min != null || pf.cachet_max != null,
    'Cachet': filled(pf.cachet_trattabile), 'Viaggi': filled(pf.rimborso_tipo),
    'Durata set': filled(pf.durata_set_min), 'Scheda tecnica': filled(pf.tech_sheet_url),
  };
  return Object.keys(checks).filter(k => !checks[k]);
}
