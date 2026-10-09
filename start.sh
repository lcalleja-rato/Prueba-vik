#!/bin/bash
# Arranca la web de Viky en Codespaces: bash start.sh
cd "$(dirname "$0")"
if ! command -v php >/dev/null 2>&1; then
  echo "Instalando PHP (1 minuto)..."
  sudo apt-get update -qq && sudo apt-get install -y -qq php-cli php-curl
fi
pkill -f "php -S" 2>/dev/null
nohup php -S 0.0.0.0:8000 router.php > /tmp/php.log 2>&1 &
sleep 2
if gh codespace ports visibility 8000:public -c "$CODESPACE_NAME" >/dev/null 2>&1; then
  echo "Puerto 8000 publico: OK"
else
  echo "Falta un paso: pestaña PORTS > clic derecho en 8000 > Port Visibility > Public"
fi
echo ""
echo "WEB:   https://${CODESPACE_NAME}-8000.app.github.dev/"
echo "PANEL: https://${CODESPACE_NAME}-8000.app.github.dev/admin.html"
