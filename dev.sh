#!/bin/bash
set -e
trap "echo ''; echo 'Stopping...'; kill 0" EXIT

cd "$(dirname "$0")"

# DB tunnel
echo "🔌 DB tunnel..."
ssh -L 3306:127.0.0.1:3306 my-server-cf -fN
sleep 1

# Laravel
echo "🚀 Laravel..."
php artisan config:clear --quiet
php artisan serve --port=8000 &
sleep 2

# Vite
echo "⚡ Vite..."
npm run dev &
sleep 2

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✅ http://localhost:8000"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Відкрити браузер автоматично
open http://localhost:8000

wait
