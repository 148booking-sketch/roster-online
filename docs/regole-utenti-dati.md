# Booking Roster — Regole e vincoli su utenti e dati

> Documento di lavoro: fotografa **come funziona oggi** la gestione di utenti, ruoli, permessi e dati sul sito, così da rileggerlo insieme e ottimizzarlo.
> Ultima ricognizione del codice: **2026-07-04**. Fonte: cartella `www/api/`.

---

## 1. Ruoli utente

Quattro ruoli, nella colonna `users.role` (ENUM):

| Ruolo | Chi è | Dove opera |
|---|---|---|
| **artist** | Artista/band | `profilo.html`, riceve richieste |
| **promoter** | Locale/festival/associazione/privato | Cerca artisti, invia richieste, preferiti |
| **management** (Agenzia) | Booking/label che gestisce un roster | Come promoter **+** gestisce artisti propri (`management.html`) |
| **admin** | Staff | Pannello `admin.html` |

Sotto-livello admin: colonna `users.admin_super` (0/1). **Super admin** = pieni poteri; **admin ridotto** (`admin_super=0`) = tutto tranne gestione altri admin ed eliminazioni (vedi §9).

---

## 2. Registrazione e attivazione (`register.php`)

**Campi obbligatori per ruolo:**

| Campo | artist | promoter / management |
|---|:---:|:---:|
| email valida | ✅ | ✅ |
| password ≥ 8 caratteri | ✅ | ✅ |
| nome + cognome (anagrafica) | ✅ | — |
| **nome d'arte** (unico nel roster) | ✅ | — |
| telefono | ✅ | ✅ |
| comune | ✅ | ✅ |
| **verifica Apple Music/iTunes** (≥2 brani/12 mesi e ≥6 totali) | ✅ | — |
| **verifica Google Calendar** (iCal valido) | ✅ | — |
| tipo (locale/festival/…) | — | ✅ |
| sito web / link | — | ✅ |

- Email **univoca** (`users.email` UNIQUE): se già presente → `email_taken` (409).
- Alla registrazione **non c'è login automatico**: l'account nasce con `email_verified = 0` e va confermato via email.
- **Stato iniziale account:**
  - artist → `status = active`, ma **profilo `published = 0`** (invisibile finché un admin non approva).
  - promoter / management → `status = pending` (vedono i prezzi / gestiscono il roster solo dopo approvazione admin → `active`).

**Wizard artista a 3 step** (`registrati-artista.html`), aggiornato 2026-07-04:
1. **Verifica Apple Music/iTunes** — link profilo, idoneità ≥2 brani/12 mesi e ≥6 totali.
2. **Verifica Google Calendar** — indirizzo iCal segreto; deve essere valido e raggiungibile.
3. **Dati** — nome, cognome, **nome d'arte**, telefono, comune (+ provincia auto), email, password.

- `display_name` = nome anagrafico (Nome Cognome, per SIAE/contratti); il **nome d'arte** va in `artist_profiles.stage_name` ed è **unico** nel roster (`stage_name_taken` se già preso).
- **Entrambe le verifiche sono ri-controllate server-side** in `register.php` (idoneità iTunes + validità iCal): non aggirabili dal client.
- Il profilo nasce già con comune (geocodificato) e calendario collegato (date occupate precalcolate).

---

## 3. Verifica email (`verify-email.php`, `resend-verification.php`)

- Login **bloccato** finché `email_verified = 0` → errore `email_not_verified`.
- Il **token di verifica NON viene invalidato** dopo l'uso (scelta voluta: i client email pre-aprono i link e consumerebbero un token usa-e-getta). Un secondo click rifà semplicemente login.
- `resend-verification.php` rigenera il token e reinvia; risponde **sempre ok** senza rivelare se l'email esiste.

---

## 4. Stati dell'account (`users.status`)

| Stato | Significato | Effetti |
|---|---|---|
| `active` | Attivo/approvato | Accesso pieno al proprio ruolo |
| `pending` | In attesa di approvazione admin | Può accedere ma: promoter non vede i prezzi; management non gestisce artisti |
| `blocked` | Bloccato | Login negato (`account_blocked`), ogni endpoint protetto rifiuta |

Chi diventa `active`:
- artist → nasce già `active`.
- promoter / management → **solo un admin** li porta `pending → active` (da `admin-update-promoter.php`).

---

## 5. Visibilità dei prezzi (cachet) — `_access.php`

Regola unica condivisa da ricerca, mappa, scheda artista, preferiti:

**Può vedere i cachet** (`viewer_can_see_prices`):
- ✅ admin
- ✅ l'artista stesso (sul proprio profilo)
- ✅ promoter/management con `status = active` (approvati)
- ❌ non loggati, promoter/management `pending`, artisti su altri profili

Quando bloccato, il server **azzera** dalla risposta: `cachet_min/max`, `cachet_promo`, `promo_until`, `rimborso_*`, `cachet_trattabile` e `tech_sheet_url` (scheda tecnica). Non è solo nascondere lato UI: i dati non escono proprio dall'API.

---

## 6. Pubblicazione profilo artista (`admin-publish.php` + `_admin.php`)

Un artista diventa visibile (`published = 1`) **solo se un admin lo approva** e se sono soddisfatte **tutte** queste condizioni:

1. `email_verified = 1`
2. Tutti i **16 campi obbligatori** compilati (`artist_publish_missing_fields`):

`Bio` · `Calendario` · `Comune` · `Provincia`¹ · `Telefono` · `Tipo di show` · `On stage` · `Generi` · `Spotify` · `Apple Music` · `Instagram` · `Cachet a serata` · `Cachet` (trattabile sì/no) · `Viaggi` (rimborso) · `Durata set` · `Scheda tecnica`

¹ La **provincia non è richiesta** se il comune base è **estero** (`is_italian_comune()` = false).

Se manca qualcosa → `missing_fields_for_publish` con l'elenco dei campi mancanti.
`published_at` si registra solo alla **prima** transizione 0→1 (usato dal digest email "nuovi artisti").

---

## 7. Artista "verificato" (`artist_profiles.verified`) e idoneità iTunes

- La spunta **verified** la assegna l'admin, oppure è **automatica** per gli artisti creati da un'agenzia.
- **Idoneità minima** per candidarsi/essere aggiunti come artista (`artist-eligibility-check.php`, iTunes/Apple Music pubblico): **≥ 2 brani pubblicati negli ultimi 12 mesi** e **≥ 6 brani totali** sul profilo.
  - Ri-verificata **lato server** quando un'agenzia crea un artista (`not_eligible` se non passa).
- **Trattativa riservata** (solo artisti **verificati**): se attiva, cachet/promo/condizioni viaggi non compaiono MAI in chiaro (ricerca, mappa, pagina artista, preferiti, richieste); le UI mostrano "Trattativa riservata". I non verificati non possono attivarla (switch disabilitato + il server la forza a 0).
- **Limite generi:** artista **non verificato → max 3 generi**; **verificato → illimitati**. L'artista non può cambiarsi il flag da solo (il limite segue lo stato già in DB).

---

## 8. Richieste di booking (`booking-request.php`, `booking-respond.php`)

- **Chi può inviare:** promoter **e management** loggati (anche `pending`), sia da backend che da UI (scheda artista). *(Allineato il 2026-07-04.)*
- Campi: artista (obbligatorio), messaggio (obbligatorio), data evento e offerta economica opzionali. Il venue, se indicato, deve appartenere al promoter.
- **Stati richiesta** (`booking_requests.status`): `inviata` → `vista` → `accettata` / `rifiutata`, oppure `ritirata`.
- **Chi risponde:**
  - artista → `accetta` / `rifiuta` / `vista` (solo sulle richieste ricevute)
  - promoter / management → `ritira` (solo sulle proprie inviate)
- Ognuno agisce **solo sulle proprie** righe (controllo su `artist_user_id` / `promoter_user_id`).

---

## 9. Permessi admin — Super vs Ridotto

| Azione | Super (`admin_super=1`) | Ridotto (`admin_super=0`) |
|---|:---:|:---:|
| Creare/modificare artisti, promoter, agenzie | ✅ | ✅ |
| Approvare/pubblicare artisti | ✅ | ✅ |
| Gestire richieste | ✅ | ✅ |
| **Eliminare** artisti/promoter/agenzie | ✅ | ❌ (`forbidden_not_super_admin`) |
| **Creare/modificare/eliminare altri admin** | ✅ | ❌ |

- Nessun admin può eliminare **sé stesso** (`cannot_delete_self`) né un altro **admin** dall'endpoint generico (`cannot_delete_admin`); la gestione admin passa dai 4 endpoint dedicati riservati ai super.
- L'eliminazione di un utente cancella a **cascata** (FK) profilo, generi, venue, richieste, preferiti.

---

## 10. Agenzia (management): gestione del proprio roster (`_management.php`, `management-*-artist.php`)

- Serve `role = management` **e** `status = active` (un management `pending` accede ma non gestisce → `account_pending`).
- Un'agenzia può **creare / aggiornare / eliminare solo i propri artisti** (`manager_user_id = suo id`, altrimenti `forbidden_not_owner`).
- Gli artisti creati da un'agenzia:
  - devono passare l'**idoneità iTunes** (≥2 brani/12 mesi e ≥6 totali);
  - nascono **gestiti**: email/password **auto-generate** (alias `148booking+slug@gmail.com`), l'artista **non ha login proprio**;
  - nascono **`verified = 1`, `published = 1`, `top8 = 0`** (l'agenzia non decide featured né stato).

---

## 11. Preferiti (`favorites-*.php`) — dal 2026-07-04

- **Chi può usarli:** promoter, management, admin. Gli artisti no.
- Tabella `favorites (user_id, artist_user_id)`; si possono salvare **solo artisti pubblicati**.
- Pagina dedicata `preferiti.html` (disponibilità + prezzo scontato, i prezzi seguono la regola §5) e calendario aggregato `preferiti-calendario.html`.
- La tabella viene creata **automaticamente** al primo uso (`ensure_favorites_table`).

---

## 12. Password e dati account

| Operazione | Regola |
|---|---|
| Cambio password (`password-change.php`) | Utente loggato, min 8 caratteri. **Non chiede la password attuale** (basta la sessione — trade-off documentato). |
| Reset password (`password-forgot` → `password-reset`) | Token valido **2 ore**, monouso (azzerato dopo l'uso). Forgot risponde sempre ok (no enumeration). |
| Cambio email/nome (`account-save.php`) | Email deve restare **univoca**. Al **cambio email** l'indirizzo torna `email_verified = 0` e parte una nuova email di verifica (la sessione corrente resta valida, ma per riaccedere serve verificare). *(Sistemato il 2026-07-04.)* |

---

## 13. Protezione dati e privacy

- **`calendar_url` (iCal privato) non è MAI esposto** dall'API pubblica: si espongono solo le **date occupate** già calcolate (`calendar_busy`).
- **Prezzi e scheda tecnica** filtrati server-side per chi non è autorizzato (§5).
- **Anti-enumeration:** login, forgot-password e resend-verification non rivelano se un'email esiste.
- Migrazioni dati/PII: **solo via phpMyAdmin manuale**, mai endpoint HTTP temporanei (regola operativa consolidata).

---

## 14. Vincoli tecnici del database (`db/schema.sql`)

- `users.email` **UNIQUE** — una sola registrazione per indirizzo.
- FK con **`ON DELETE CASCADE`** (profili/generi/venue/richieste/preferiti) e `ON DELETE SET NULL` per `manager_user_id`.
- ⚠️ **MySQL non-strict**: un valore ENUM non valido (es. `role` fuori lista) viene **silenziosamente salvato come `''`** invece di dare errore → riga "invisibile" ma email occupata. È stata la causa di bug reali (account agenzia che "spariscono"). Da tenere a mente per ogni nuovo valore ENUM.

---

## 15. 🔧 Osservazioni e possibili ottimizzazioni (da discutere)

Punti dove il comportamento attuale è incoerente o migliorabile — candidati per l'ottimizzazione:

1. ✅ **RISOLTO (2026-07-04) — Richiesta booking: backend vs UI.** Ora anche le **agenzie** (management) possono inviare richieste dalla scheda artista, allineate al backend.
2. ✅ **RISOLTO (2026-07-04) — Cambio email senza re-verifica.** Al cambio email l'indirizzo torna `email_verified = 0` e parte una nuova verifica.
3. **Cambio password senza password attuale.** Comodo ma se una sessione viene rubata consente il takeover completo. Valutare richiesta della password attuale (almeno per cambi "sensibili").
4. **Notifiche email mancanti.** `booking-request.php` ha un `// TODO: notifica email all'artista`: oggi l'artista non è avvisato di una nuova richiesta.
5. **`management` e i prezzi.** Un management `pending` non vede i prezzi (come i promoter): confermare che sia il comportamento voluto anche per chi gestisce un roster.
6. **Blocco account (`blocked`).** Esiste lo stato ma non risulta un endpoint/azione UI per bloccare rapidamente un utente problematico: valutare un toggle in admin.
7. ✅ **RISOLTO (2026-07-04) — Idoneità iTunes aggirabile.** Ora `register.php` **ri-verifica lato server** sia l'idoneità iTunes sia la validità del Google Calendar, oltre che nel wizard. Non è più aggirabile dal client.

---

*Fine report. Rileggiamolo e segnamo cosa cambiare: posso aggiornare direttamente questo file.*
