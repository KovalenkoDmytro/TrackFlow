#!/bin/bash
# Підключення до продакшн-сервера і вхід у директорію проєкту
exec ssh -t my-server-cf 'cd ~/apps/trackflow && exec "$SHELL" -l'
