@echo off
setlocal
title Monitor en Tiempo Real - AWS RDS PostgreSQL (Adbana)
color 0A

echo Conectando con AWS RDS PostgreSQL (adbana-db)...
:loop
cls
echo ==============================================================================
echo    ADBNANA SaaS - MONITOR EN TIEMPO REAL (AWS RDS PostgreSQL)
echo ==============================================================================
echo    Host RDS   : adbana-db.cdki08iwy4vt.us-east-2.rds.amazonaws.com
echo    Base Datos : adbana
echo    Hora       : %time%
echo ==============================================================================
echo.

ssh -i "%USERPROFILE%\Downloads\adbana-key.pem" -o StrictHostKeyChecking=accept-new ubuntu@3.143.255.64 "PGPASSWORD='Hacker2077!' psql -h adbana-db.cdki08iwy4vt.us-east-2.rds.amazonaws.com -U adbana -d adbana -c 'SELECT count(*) as total_pedidos FROM pedidos;' -c 'SELECT id, cliente_nombre, total, estado, created_at FROM pedidos ORDER BY id DESC LIMIT 5;'"

timeout /t 1 >nul
goto loop
