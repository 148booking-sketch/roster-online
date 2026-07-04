# Booking Roster — Design System (libro dello stile)

> Sistema di design **ufficiale** del sito. Riferimento per ogni nuova schermata: se un elemento non è documentato qui, va aggiunto qui (e in `style.css` / `api.js`) prima di usarlo.
> Versione viva e visiva: **[/styleguide.html](../www/styleguide.html)** (apribile sul sito). Aggiornato 2026-07-04, importato dal design Claude "Admin layout e navigazione".

## Principi
- **Palette e font invariati** rispetto al brand storico: si evolvono layout, navigazione e iconografia.
- **Emoji → icone SVG lineari** (stile Lucele/Feather, stroke `currentColor`). Catalogo in `ICONS` dentro `assets/api.js`, reso con `icon(nome, size, stroke)`.
- **Superfici**: sito pubblico e aree utente su bianco; **shell admin** con sidebar scura e contenuto largo (densità gestionale).
- Ogni vista admin è una **pagina dedicata nella shell** (Panoramica, Artisti, Promoter, Agenzie, Richieste, Admin, Impostazioni), non tab affiancate.

## Token (`:root` in `style.css`)
| Token | Valore | Uso |
|---|---|---|
| `--brand` | `#d52454` | azioni primarie, stato attivo, accenti |
| `--brand2` | `#b81e47` | hover del brand |
| `--txt` | `#222222` | testo principale, bottone scuro |
| `--muted` | `#717171` | testo secondario |
| `--line` / `--line2` | `#dddddd` / `#ebebeb` | bordi input / divisori |
| `--panel2` | `#f7f7f7` | superfici secondarie, chip |
| sidebar admin | `#17171b` | fondo sidebar; testo voci `#9a9aa2`, dimmed `#6b6b74` |
| successo | `#0a7d38` su `#eafaf0` | stato "Online" |
| attesa | `#a86a00` su `#fff5e6` | stato "In attesa" |
| verificato | `#1d9bf0` | spunta blu |
| radius | card 14–16px · input/bottoni 10px · voci sidebar 9px · pill 20–40px | |
| shadow | `--shadow` card · `--shadow-lg` menu/overlay | |

## Tipografia
- **Space Grotesk** (600/700): titoli, numeri, logo, prezzi. `var(--f-display)`.
- **Inter** (400–700): testo, label, UI. `var(--f-body)`.
- `.section-label` = maiuscoletto 11px tenue per intestazioni di sezione nei form/admin.

## Icone
**Eccezione voluta (2026-07-05): i generi musicali usano le EMOJI** (`GENRE_ICONS`) nella category bar della home e nei chip del popup Cerca — l'utente le preferisce alle icone SVG generiche (che si ripetevano troppo). `GENRE_SVG`/`genreIcon()` restano disponibili ma non si usano lì.

Catalogo in `assets/api.js → ICONS`. Nomi principali: `search, bell, heart, check, chevronDown/Right/Left, arrowRight, plus, x, mic, music, speaker, agency, inbox, grid, shield, sliders, filter, home, calendar, pin, user, logout, edit, trash, eye, eyeOff, mail, lock, play, refresh, star, wave, key`.
Uso: `icon('heart', 20, 1.8)` → markup `<svg>` con `currentColor` (eredita il colore del testo). Aggiungere nuove icone al dizionario, non incollarle inline.

## Componenti (classi in `style.css`)
- **Bottoni** `.btn` + `.primary` / `.dark` / `.ghost` / `.outline`, taglia `.sm`. Icona a sinistra con `icon()`.
- **Header nav**: `.nav-links > .nav-link` (con `.on` = underline brand), `.nav-bell`, `.usermenu > .nav-avatar` + `.menu-pop > .menu-item`. Costruito da `renderNav()` in base al ruolo.
- **Avatar**: `.avatar` (iniziali + colore stabile da `avatarColor()`).
- **Stati**: `.status` con `.ok` / `.warn` / `.off` / `.promo` (pallino + testo). Verificato: `.verif` con `.tick`.
- **Chip generi**: `.chip` (selezionato = brand, non selezionato = grigio, "+aggiungi" = tratteggiato).
- **Interruttore**: `.switch` (on = brand).
- **Form**: `.field` + `<label>` + input; bordo `--line`, radius 10.
- **Card artista**: immagine quadrata (foto/gradiente) + `.verif` in alto a sx + cuore in alto a dx + nome/tipo·città/cachet sotto, senza bordo.
- **Shell admin**: sidebar `#17171b` (voci a icona, attiva = brand, contatori richieste, card utente in basso) + contenuto bianco con breadcrumb, titolo con avatar+stato+azioni, barra salvataggio sticky.

## Struttura per area (template ufficiale, dal progetto design — 2026-07-05)
| Area | Layout |
|---|---|
| Pubbliche + artista loggato (home 5a, pagina artista 5b, profilo 4a, richieste artista 6a, mappa, calendario) | **Header orizzontale** con link testuali + campana + avatar |
| **Promoter/agenzia loggati** — Preferiti (5c), Le mie richieste (6b), Account & notifiche (7a) | **Sidebar bianca** `mountPromoterShell()` (216px, voci con badge, card utente) |
| Auth (10a/10b/11d) | Card centrata su fondo `#fafafa`, CTA brand full-width (`.auth-*`) |
| Admin (2b, 3a-c, 10c, 12b) | Rail scuro `#17171b` |
| Email (13a-i) | `mail_layout()`: barra brand 4px, logo centrato, CTA rosa, footer societario; digest = `digest_layout()` con header scuro + KPI |

**Punti chiave del template:**
- Card artista home (5a): badge "Verificato" pill bianco in alto a sx sull'immagine, cuore bianco in alto a dx, sotto solo nome / tipo·città / prezzo — nessun overlay informativo.
- Pagina artista (5b): breadcrumb, hero 380px foto + info (nome, chips generi grigie, stat row numeri grandi), booking box con prezzo grande + CTA "Richiedi questo artista" → **modale 12a** (data con check libero/occupato dal calendario, cachet proposto, messaggio).
- Preferiti (5c): pagina UNICA — lista a sinistra (con ricerca) + calendario disponibilità del selezionato a destra; voce "Tutti i preferiti" = vista aggregata X/Y liberi per giorno.
- Richieste promoter (6b): tabella densa con pill filtri stato; artista (6a): card. Thread messaggi integrato in entrambe.
- Account & notifiche (7a): impostazioni a sinistra, feed notifiche + riepilogo email (segmented control) a destra.
- Multilink (11c): header gradiente scuro con avatar bordo bianco + social translucidi, link bianchi centrati su fondo `#fafafa`.
- Stati vuoti (11e): `.empty` con titolo display + sottotitolo (+ `.empty-ico`).

## Stato di adozione (rollout completato 2026-07-04, template integrale 2026-07-05)
- ✅ **Fondamenta**: token, set icone SVG (43 icone), `renderNav()` con avatar dropdown + campana, componenti condivisi, `/styleguide.html` + questo doc.
- ✅ **Shell admin** (`admin.html`): sidebar scura + contenuto largo + titoli vista + badge contatori; KPI e liste senza emoji (icone SVG via `injectIcons()`/`data-ic`).
- ✅ **Pagine migrate**: home (category bar e icone rapide in SVG, cuore preferiti), registrati (card ruolo con icone), richieste (card redesign + avatar iniziali + thread messaggi), account, preferiti + calendario preferiti, management. Il pulsante preferito è ora un **cuore** (pieno brand quando attivo).
- ✅ **Nuove funzioni** (design "Email di sistema" + 12d): 
  - **Email transazionali** in `_mail.php`: nuova richiesta → artista, risposta → promoter, profilo online → artista, benvenuto promoter post-verifica, **promemoria evento 3 giorni prima** (`send_event_reminders()`, dedup in `booking_reminders`, girata dal cron digest E dal tick giornaliero di artists-search), nuovo messaggio → controparte.
  - **Thread messaggi** promoter↔artista per richiesta: `booking_messages` (auto-creata), API `booking-messages.php`, UI chat in `richieste.html`.
  - **Feed notifiche**: `notifications.php` (eventi per ruolo) + campana con pallino/dropdown in `api.js` ("visto" in localStorage `roster_notif_seen`).
- Convenzione: elementi statici con icona → attributo `data-ic="nome"` + chiamata `injectIcons()` dopo `renderNav()`.
