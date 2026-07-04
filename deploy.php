<?php
/**
 * 148 BOOKING — motore di deploy FTPS (cifrato via OpenSSL).
 * Chiamato da deploy.sh, che carica le credenziali da .deploy.env.
 *
 *   php deploy.php upload <file...>     carica i file (path relativi a www/)
 *   php deploy.php delete <file...>     elimina i file sul server
 *   php deploy.php changed              carica i file modificati dall'ultimo deploy
 *   php deploy.php prune <dir> [giorni] elimina le proposte card/story vecchie (file con
 *                                       data nel nome <slug>-YYYYMMDD.*); default tieni 7 gg.
 *                                       NON tocca i file senza data (card permanenti/curate).
 *
 * Protezioni: non tocca mai api/config.php, api/cache/*, .DS_Store, né i file senza data.
 */

$ROOT  = 'www';
$STAMP = '.deploy.stamp';

$host = getenv('FTP_HOST'); $port = (int)(getenv('FTP_PORT') ?: 21);
$user = getenv('FTP_USER'); $pass = getenv('FTP_PASS');
$base = rtrim(getenv('FTP_REMOTE_DIR') ?: '/public_html', '/');
$proto = getenv('FTP_PROTO') ?: 'ftps';

function forbidden(string $rel): bool {
    return $rel === 'api/config.php'
        || str_starts_with($rel, 'api/cache/')
        || basename($rel) === '.DS_Store';
}

function changedFiles(string $root, string $stamp): array {
    $since = is_file($stamp) ? filemtime($stamp) : 0;
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (forbidden($rel)) continue;
        if ($f->getMTime() > $since) $out[] = $rel;
    }
    sort($out);
    return $out;
}

function ftp_mkdirp($c, string $base, string $relDir): void {
    if ($relDir === '' || $relDir === '.') return;
    $path = $base;
    foreach (explode('/', $relDir) as $p) {
        if ($p === '') continue;
        $path .= '/' . $p;
        if (!@ftp_chdir($c, $path)) { @ftp_mkdir($c, $path); }
    }
}

$args = array_slice($argv, 1);
$mode = array_shift($args) ?? '';

if ($mode === 'changed') { $mode = 'upload'; $args = changedFiles($ROOT, $STAMP); }
if (!in_array($mode, ['upload', 'delete', 'prune'], true)) {
    fwrite(STDERR, "Modo non valido. Usa: upload | delete | changed | prune\n"); exit(2);
}
if (!$args) { echo "Nessun file da elaborare.\n"; exit(0); }

if (!$host || !$user || !$pass) { fwrite(STDERR, "❌ Credenziali mancanti in .deploy.env\n"); exit(1); }

$c = ($proto === 'ftps') ? @ftp_ssl_connect($host, $port, 25) : @ftp_connect($host, $port, 25);
if (!$c) { fwrite(STDERR, "❌ Connessione FTP fallita ($host:$port)\n"); exit(1); }
if (!@ftp_login($c, $user, $pass)) { fwrite(STDERR, "❌ Login fallito\n"); ftp_close($c); exit(1); }
@ftp_pasv($c, true);
echo ($proto === 'ftps' ? "🔒 FTPS" : "FTP") . " connesso a $host\n\n";

// prune: elimina le proposte card/story con DATA nel nome (<slug>-YYYYMMDD.*) più vecchie
// di N giorni. Lascia stare i file SENZA data (card permanenti/curate). Evita l'accumulo.
if ($mode === 'prune') {
    $dir = trim($args[0] ?? '', '/');
    $keep = isset($args[1]) ? max(0, (int)$args[1]) : 7;
    if ($dir === '') { fwrite(STDERR, "prune: serve <dir relativa a www/> [giorni-da-tenere]\n"); ftp_close($c); exit(2); }
    $cutoff = gmdate('Ymd', time() - $keep * 86400);
    $items = @ftp_mlsd($c, "$base/$dir");
    if ($items === false) { fwrite(STDERR, "prune: dir non leggibile: $dir\n"); ftp_close($c); exit(1); }
    $del = 0; $kept = 0; $skip = 0;
    foreach ($items as $it) {
        if (($it['type'] ?? '') !== 'file') continue;
        $name = basename($it['name']);
        if (!preg_match('/-(\d{8})\.(jpe?g|png|mp4)$/i', $name, $m)) { $skip++; continue; }  // senza data → intoccabile
        if ($m[1] < $cutoff) {
            if (@ftp_delete($c, "$base/$dir/$name")) { echo "🗑️  $dir/$name\n"; $del++; }
        } else $kept++;
    }
    echo "\n🧹 prune $dir: eliminati $del · tenuti $kept (con data, < $keep gg) · ignorati $skip (senza data)\n";
    ftp_close($c);
    exit(0);
}

$ok = 0; $fail = 0;
foreach ($args as $rel) {
    $rel = ltrim($rel, '/');
    if (forbidden($rel)) { echo "⛔ salto (protetto): $rel\n"; continue; }

    if ($mode === 'delete') {
        if (@ftp_delete($c, "$base/$rel")) { echo "🗑️  eliminato: $rel\n"; $ok++; }
        else { echo "⚠️  non eliminato (forse già assente): $rel\n"; }
        continue;
    }

    // upload — con verifica dimensione remota e retry. Un FTP instabile può chiudere
    // la connessione a metà trasferimento lasciando un file TRONCATO: il publisher poi
    // passa l'URL a Meta che non riesce a processarlo (es. IG error 2207077). Qui, dopo
    // il put, confrontiamo la size remota con quella locale e ricarichiamo se non torna.
    $local = "$ROOT/$rel";
    if (!is_file($local)) { echo "⚠️  non trovato in locale: $rel\n"; continue; }
    ftp_mkdirp($c, $base, dirname($rel));
    $want = filesize($local);
    $done = false;
    for ($try = 1; $try <= 3; $try++) {
        if (@ftp_put($c, "$base/$rel", $local, FTP_BINARY)) {
            $remote = @ftp_size($c, "$base/$rel");          // -1 se il server non supporta SIZE
            if ($remote === $want) { echo "✅ $rel ($remote b)\n"; $ok++; $done = true; break; }
            if ($remote < 0)       { echo "✅ $rel (size non verificabile)\n"; $ok++; $done = true; break; }
            echo "⚠️  upload troncato: $rel (remoto $remote ≠ atteso $want) — ritento ($try/3)\n";
        } else {
            echo "⚠️  put fallito: $rel — ritento ($try/3)\n";
        }
        sleep(2);
    }
    if (!$done) { echo "❌ ERRORE upload (size non confermata): $rel\n"; $fail++; }
}

if ($mode === 'upload' && $fail === 0) { @touch($STAMP); }
ftp_close($c);
echo "\n── $ok ok, $fail errori ──\n";
exit($fail > 0 ? 1 : 0);
