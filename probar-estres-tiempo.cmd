@echo off
setlocal

set TIEMPO=%1
if "%TIEMPO%"=="" set TIEMPO=30s

set USUARIOS=%2
if "%USUARIOS%"=="" set USUARIOS=100

set MODO=%3
if "%MODO%"=="" set MODO=tiempo

echo ====================================================================
echo   ADBNANA SaaS - PRUEBA DE ESTRES POR TIEMPO EN LA NUBE (AWS)
echo ====================================================================
echo   Servidor Destino : https://3.143.255.64.nip.io/api
echo   Base de Datos    : AWS RDS PostgreSQL (Multi-AZ)
echo   Duracion / Ventana: %TIEMPO%
echo   Usuarios VUs     : %USUARIOS% concurrentes
echo   Tipo de Escenario : %MODO% (constant-vus / rampa)
echo ====================================================================
echo.

if exist "%~dp0tools\k6.exe" (
    echo [MODO NATIVO] Ejecutando con k6 standalone - Cero Docker requerido
    echo.
    "%~dp0tools\k6.exe" run ^
      -e BASE_URL=https://3.143.255.64.nip.io/api ^
      -e VUS=%USUARIOS% ^
      -e DURACION=%TIEMPO% ^
      -e MODO=%MODO% ^
      -e TOTAL_PETICIONES=50000 ^
      "%~dp0k6\estres-masivo.js"
) else (
    echo [MODO DOCKER] k6 local no encontrado, usando imagen Docker...
    docker run --rm -i -v "%~dp0k6:/scripts" grafana/k6 run ^
      -e BASE_URL=https://3.143.255.64.nip.io/api ^
      -e VUS=%USUARIOS% ^
      -e DURACION=%TIEMPO% ^
      -e MODO=%MODO% ^
      -e TOTAL_PETICIONES=50000 ^
      /scripts/estres-masivo.js
)

endlocal
