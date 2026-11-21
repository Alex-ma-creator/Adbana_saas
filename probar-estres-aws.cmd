@echo off
setlocal

set PETICIONES=%1
if "%PETICIONES%"=="" set PETICIONES=10000

set USUARIOS=%2
if "%USUARIOS%"=="" set USUARIOS=1000

set TIEMPO=%3
if "%TIEMPO%"=="" set TIEMPO=30s

set BASE_URL=%4
if "%BASE_URL%"=="" set BASE_URL=https://3.143.255.64.nip.io/api

echo ====================================================================
echo   ADBANA SaaS - PRUEBA DE ESTRES Y RESILIENCIA (10K - 1,000,000)
echo ====================================================================
echo   Servidor Destino     : %BASE_URL%
echo   Base de Datos        : PostgreSQL (ACID + Semaforo Redis)
echo   Total Peticiones     : %PETICIONES% objetivo
echo   Usuarios/Concurrencia: %USUARIOS% concurrentes
echo   Tiempo Limite        : %TIEMPO%
echo ====================================================================
echo.

if exist "%~dp0tools\k6.exe" goto MODO_NATIVO
goto MODO_DOCKER

:MODO_NATIVO
echo [MODO NATIVO] Ejecutando con k6 standalone (%USUARIOS% VUs directos)
echo.
"%~dp0tools\k6.exe" run -e BASE_URL=%BASE_URL% -e TOTAL_PETICIONES=%PETICIONES% -e VUS=%USUARIOS% -e MAX_VUS_FISICOS=%USUARIOS% -e DURACION=%TIEMPO% -e MODO=iteraciones "%~dp0k6\estres-masivo.js"
goto FIN

:MODO_DOCKER
echo [MODO DOCKER] k6 local no encontrado, usando imagen Docker...
echo.
docker run --rm -i -v "%~dp0k6:/scripts" grafana/k6 run -e BASE_URL=%BASE_URL% -e TOTAL_PETICIONES=%PETICIONES% -e VUS=%USUARIOS% -e MAX_VUS_FISICOS=%USUARIOS% -e DURACION=%TIEMPO% -e MODO=iteraciones /scripts/estres-masivo.js
goto FIN

:FIN
endlocal
