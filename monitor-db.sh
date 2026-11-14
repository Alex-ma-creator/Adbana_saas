#!/bin/bash
export TERM=xterm
export PGPASSWORD='Hacker2077!'

while true; do
  clear
  echo -e "\033[1;32m==============================================================================\033[0m"
  echo -e "\033[1;32m   ADBNANA SaaS - MONITOR EN TIEMPO REAL (AWS RDS PostgreSQL)\033[0m"
  echo -e "\033[1;32m==============================================================================\033[0m"
  echo -e "   Host RDS   : adbana-db.cdki08iwy4vt.us-east-2.rds.amazonaws.com"
  echo -e "   Base Datos : adbana"
  echo -e "   Hora AWS   : $(date '+%Y-%m-%d %H:%M:%S')"
  echo -e "\033[1;32m==============================================================================\033[0m"
  echo ""
  
  psql -h adbana-db.cdki08iwy4vt.us-east-2.rds.amazonaws.com -U adbana -d adbana \
    -c 'SELECT count(*) as total_pedidos FROM pedidos;' \
    -c 'SELECT id, cliente_nombre, total, estado, created_at FROM pedidos ORDER BY id DESC LIMIT 5;'
    
  echo ""
  echo -e "\033[0;33m[Presione Ctrl + C para salir del monitor]\033[0m"
  sleep 2
done
