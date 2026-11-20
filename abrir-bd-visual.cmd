@echo off
setlocal
title Adbana - Visor Visual de Base de Datos (AWS RDS PostgreSQL)
color 0B

echo ==============================================================================
echo       ADBANA - VISOR GRAFICO DE BASE DE DATOS (AWS RDS POSTGRESQL)
echo ==============================================================================
echo.
echo [1/3] Verificando credenciales de acceso seguro...
set "KEY_PATH=%USERPROFILE%\Downloads\adbana-key.pem"
if not exist "%KEY_PATH%" (
    if exist "%~dp0adbana-key.pem" (
        set "KEY_PATH=%~dp0adbana-key.pem"
    ) else (
        echo [ERROR] No se encontro la llave adbana-key.pem en Descargas ni en el proyecto.
        pause
        exit /b 1
    )
)

echo [2/3] Abriendo visor en tu navegador (http://localhost:8085)...
timeout /t 2 /nobreak >nul
start http://localhost:8085

echo [3/3] Tunel cifrado ACTIVO con AWS RDS PostgreSQL.
echo.
echo ==============================================================================
echo  El panel visual ya esta disponible en tu navegador:
echo  URL: http://localhost:8085
echo.
echo  Aqui podras ver todas las tablas en vivo (pedidos, mesas, platos, usuarios, etc.)
echo  hacer clic en cada una, ver sus columnas, filtros y registros sin usar comandos.
echo.
echo  IMPORTANTE: Manten esta ventana abierta mientras uses el visor.
echo  (Para cerrarlo cuando termines, simplemente cierra esta ventana).
echo ==============================================================================
echo.

ssh -N -L 8085:127.0.0.1:8085 -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -i "%KEY_PATH%" ubuntu@3.143.255.64

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo [AVISO] El tunel se ha cerrado o el puerto 8085 estaba ocupado.
    pause
)
