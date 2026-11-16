#!/bin/bash
export TERM=xterm-256color
export PGPASSWORD='Hacker2077!'

RDS_HOST="adbana-db.cdki08iwy4vt.us-east-2.rds.amazonaws.com"
RDS_USER="adbana"
RDS_DB="adbana"

psql_q() {
  psql -h "$RDS_HOST" -U "$RDS_USER" -d "$RDS_DB" -t -A -F '|' -c "$1" 2>/dev/null
}

clear
echo -e "\033[1;32m========================================================================================\033[0m"
echo -e "\033[1;32m   ADBANA SaaS - MONITOR DE PEDIDOS EN TIEMPO REAL (FILA INDIA - AWS RDS PostgreSQL)\033[0m"
echo -e "\033[1;32m========================================================================================\033[0m"
echo -e "   Host RDS   : $RDS_HOST"
echo -e "   Base Datos : $RDS_DB (Multi-AZ)"
echo -e "   Inicio     : $(date '+%Y-%m-%d %H:%M:%S') (UTC)"

INIT_INFO=$(psql_q "SELECT COALESCE(MAX(id), 0), COUNT(*) FROM pedidos;")
LAST_ID=$(echo "$INIT_INFO" | cut -d'|' -f1)
TOTAL_HIST=$(echo "$INIT_INFO" | cut -d'|' -f2)

if [ -z "$LAST_ID" ]; then
  LAST_ID=0
  TOTAL_HIST=0
fi

echo -e "   Histórico  : $TOTAL_HIST pedidos registrados hasta este momento (Último ID_BD: $LAST_ID)"
echo -e "\033[1;32m========================================================================================\033[0m"
echo -e "\033[1;36m  # ORDEN  |   HORA   |  ID_BD  | CLIENTE            | UBICACIÓN / TIPO |   TOTAL    | ESTADO\033[0m"
echo -e "\033[1;32m----------------------------------------------------------------------------------------\033[0m"
echo -e "\033[0;33m>>> Esperando nuevos pedidos en vivo... (Haz un pedido en la carta o lanza k6) <<<\033[0m"

SEQ=0

while true; do
  ROWS=$(psql_q "SELECT id, to_char(created_at, 'HH24:MI:SS'), left(regexp_replace(COALESCE(cliente_nombre,'Cliente'), '[|\r\n]+', ' ', 'g'), 18), left(CASE WHEN tipo = 'mesa' AND mesa IS NOT NULL AND mesa <> '' THEN mesa ELSE upper(COALESCE(tipo,'MESA')) END, 16), to_char(total, 'FM999990.00'), estado FROM pedidos WHERE id > $LAST_ID ORDER BY id ASC LIMIT 1000;")

  if [ -n "$ROWS" ]; then
    while IFS='|' read -r id hora cliente ubicacion total estado; do
      [ -z "$id" ] && continue
      SEQ=$((SEQ + 1))
      LAST_ID="$id"
      printf "\033[1;32m[#%05d]\033[0m  | %s | %-7s | %-18s | %-16s | \033[1;33mS/ %-7s\033[0m | %s\n" \
        "$SEQ" "$hora" "$id" "$cliente" "$ubicacion" "$total" "$estado"
    done <<< "$ROWS"
  fi

  sleep 0.4
done
