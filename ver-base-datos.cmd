@echo off
setlocal
title Monitor en Tiempo Real - AWS RDS PostgreSQL (Adbana)
color 0A

echo Conectando con AWS RDS PostgreSQL (adbana-db) via sesion persistente...
echo.

ssh -t -i "%USERPROFILE%\Downloads\adbana-key.pem" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 ubuntu@3.143.255.64 "/home/ubuntu/monitor-db.sh"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ==============================================================================
    echo [AVISO] Si la conexion se cerro o tu red WiFi / Firewall bloqueo el puerto 22,
    echo puedes volver a ejecutar este comando o verificar que tengas conexion a Internet.
    echo ==============================================================================
    pause
)
