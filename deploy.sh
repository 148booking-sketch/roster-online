#!/usr/bin/env bash
# ───────────────────────────────────────────────
# 148 BOOKING — deploy via FTPS cifrato (motore: deploy.php / OpenSSL)
#
#   ./deploy.sh <file...>          carica i file indicati (path relativi a www/)
#   ./deploy.sh --changed          carica i file modificati dall'ultimo deploy
#   ./deploy.sh --delete <file...> elimina i file indicati sul server
#   ./deploy.sh --prune <dir> [gg] elimina le proposte card/story vecchie (con data nel nome)
#
# Credenziali: lette da .deploy.env (ignorato da git).
# Protezioni: non tocca mai api/config.php, api/cache/*, .DS_Store.
# ───────────────────────────────────────────────
set -euo pipefail
cd "$(dirname "$0")"

[ -f .deploy.env ] || { echo "❌ Manca .deploy.env — copia .deploy.env.example e compilalo."; exit 1; }
set -a; source .deploy.env; set +a

PHP="$(command -v php || true)"
[ -x "$PHP" ] || PHP="$HOME/.local/bin/php"
[ -x "$PHP" ] || { echo "❌ php non trovato."; exit 1; }

case "${1:-}" in
  --delete) shift; exec "$PHP" deploy.php delete "$@" ;;
  --changed) exec "$PHP" deploy.php changed ;;
  --prune) shift; exec "$PHP" deploy.php prune "$@" ;;
  "" ) echo "Uso: ./deploy.sh <file...> | --changed | --delete <file...> | --prune <dir> [gg]"; exit 1 ;;
  *) exec "$PHP" deploy.php upload "$@" ;;
esac
