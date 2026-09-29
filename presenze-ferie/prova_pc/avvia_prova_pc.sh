#!/usr/bin/env bash
# Prova su PC (Mac/Linux): app ferie + dashboard con dati dimostrativi. Ctrl+C per chiudere.
cd "$(dirname "$0")" || exit 1
python3 prepara_prova.py "$@" || exit 1
export FERIE_DATI_DIR="$PWD/dati_prova"
php -d display_errors=0 -S 0.0.0.0:8080 -t ../ferie > php.log 2>&1 &
PHP_PID=$!
trap 'kill $PHP_PID 2>/dev/null' EXIT
echo; echo "App ferie:  http://localhost:8080/index.html"; echo "Dashboard:  http://localhost:8501  (scheda Ferie, password 0)"; echo
cd dati_prova && python3 -m streamlit run APP.py --server.port 8501
