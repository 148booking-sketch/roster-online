# 148 Roster — piattaforma artisti ↔ promoter

Marketplace di **matching** tra artisti emergenti e promoter.
L'artista pubblica dati, cachet e rimborsi; il promoter cerca per **cachet, distanza dal locale e genere** e invia una richiesta di contatto. Modello **disintermediato**: l'accordo si chiude fuori piattaforma (niente pagamenti/contratti gestiti qui).

- **Dominio:** artisti.148booking.it
- **Stack:** PHP 8 + MySQL (PDO), frontend HTML/JS vanilla, deploy via FTP
- **DB:** `web01207_148roster`

## Struttura

```
db/schema.sql          → schema MySQL (import una tantum)
www/                   → tutto ciò che va in public_html del sottodominio
  api/
    config.php         → credenziali REALI (gitignored, non caricare in repo)
    config.example.php → template
    _db.php            → connessione PDO
    _http.php          → helper JSON, sessione, auth
    _geo.php           → geocoding comuni + distanza Haversine
    register.php · login.php · me.php
    artist-save.php    → salva profilo artista (+ geocoding + generi)
    artists-search.php → RICERCA (cachet / distanza / genere / formazione)
    booking-request.php→ invio + lista richieste
    genres.php         → elenco generi
    _admin.php · admin-*.php → area admin (inserimento manuale artisti/promoter)
  admin.html           → pannello admin (gate login + form + elenco)
  assets/comuni.json   → comuni italiani per autocomplete
  app/                 → frontend (pagine)
```

## Setup iniziale (una volta)

1. **Crea il DB** su phpMyAdmin (già fatto: `web01207_148roster`).
2. **Importa lo schema:** phpMyAdmin → DB → SQL → incolla `db/schema.sql`.
3. **Configura:** copia `config.example.php` in `config.php` e metti la password DB
   (già predisposto in locale; verifica `db_host` — di norma `localhost`).
4. **Carica `www/`** dentro la cartella del sottodominio (public_html di roster).

## API (riassunto)

| Endpoint | Metodo | Ruolo | Scopo |
|---|---|---|---|
| `/api/register.php` | POST | — | crea artista o promoter |
| `/api/login.php` · `/api/me.php` | POST/GET | — | login / sessione |
| `/api/artist-save.php` | POST | artist | salva profilo + cachet + generi |
| `/api/artists-search.php` | GET | pubblico | ricerca filtrata (vedi sotto) |
| `/api/booking-request.php` | POST/GET | promoter/artist | contatto e storico |
| `/api/genres.php` | GET | pubblico | generi disponibili |
| `/api/admin-bootstrap.php` | POST | token | crea il PRIMO admin (usa-e-getta) |
| `/api/admin-create-artist.php` | POST | admin | crea artista a mano (+geo, generi, social) |
| `/api/admin-create-promoter.php` | POST | admin | crea promoter a mano (+geo) |
| `/api/admin-list.php` | GET | admin | elenco/ricerca artisti e promoter |

## Area admin (`/admin`)

Pannello per inserire **a mano** artisti e promoter (`www/admin.html`).

1. In `config.php` imposta `admin_setup_token` (già predisposto in locale).
2. Vai su `https://artisti.148booking.it/admin` → *"Primo avvio: crea il primo admin"*,
   inserisci token + email + password. Crea l'account admin (funziona una sola volta:
   si disabilita appena esiste un admin).
3. **Svuota** `admin_setup_token` in `config.php` per chiudere il bootstrap.
4. Da lì in poi accedi con email/password admin e usa i due form (artista / promoter)
   e la scheda *Elenco*. Gli account creati hanno `status=active` e `email_verified=1`,
   così l'artista/promoter può subito loggarsi con le credenziali che hai impostato.

**Ricerca** (`artists-search.php`) — parametri: `q`, `genre[]`, `cachet_max`,
`cachet_min`, `comune`+`provincia` (o `lat`+`lng`), `max_km`, `formazione`,
`sort` (`distance|cachet|recent`), `page`, `limit`. La distanza usa Haversine in SQL.

## Statistiche social — modello indipendente di roster

Roster ha la **sua copia** del metodo del sito 148 (mai fuso col 148). Due livelli:

1. **Hosting** (`_stats.php` + `stats-cron.php`) — gira sul server roster. Prende ciò che
   funziona da lì: **TikTok** (tikwm), **Twitch** (decapi), **YouTube ultimo video** (RSS),
   **YouTube iscritti** (Data API, `youtube_api_key`), **Instagram/Facebook** (Apify,
   `apify_token`, solo con `&apify=1`), **Spotify follower** (Web API, serve app con owner
   Premium). Attivato dal cron settimanale DirectAdmin + refresh guidato dal traffico.
2. **Cloud** (`worker/social-stats.php` + `.github/workflows/social-stats.yml`) — repo GitHub
   **di roster**, separato dal 148. Gira da IP cloud → riesce a scrapare gli **ascoltatori
   mensili Spotify** (dall'hosting impossibile: Spotify serve solo lo shell JS). Legge gli
   artisti da `stats-feed.php`, calcola tutto e li rimanda a `stats-ingest.php`.
   I valori "cloud-only" (Spotify ascoltatori, IG, FB) vengono preservati dai refresh hosting.

**Setup cloud (opzionale, per gli ascoltatori Spotify):** crea un repo GitHub per QUESTO
progetto, aggiungi i secret `ROSTER_STATS_TOKEN` (= `stats_token` di config.php),
`YOUTUBE_API_KEY`, `APIFY_TOKEN`. Il workflow parte ogni lunedì (o a mano da Actions).

Chiavi in `config.php`: `stats_token`, `youtube_api_key`, `apify_token`,
`spotify_client_id`/`spotify_client_secret`.

## Roadmap
- [x] Schema DB, auth, profilo artista, ricerca, richieste
- [x] Statistiche social (modello indipendente: hosting + worker cloud roster)
- [ ] Frontend: registrazione, editor profilo artista, pagina ricerca promoter, inbox richieste
- [ ] Email di verifica + notifica richieste (riuso `_mail` del sito 148)
- [ ] Upload foto/media artista
- [ ] Area promoter: gestione locali salvati (`venues`)
- [ ] Verifica/curatela artisti (badge `verified`)
```
