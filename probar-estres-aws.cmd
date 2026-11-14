@echo off
setlocal

set PETICIONES=%1
if "%PETICIONES%"=="" set PETICIONES=5000

set USUARIOS=%2
if "%USUARIOS%"=="" set USUARIOS=100

set TIEMPO=%3
if "%TIEMPO%"=="" set TIEMPO=30s

echo ====================================================================
echo   ADBNANA SaaS - PRUEBA DE ESTRES Y RESISTENCIA EN LA NUBE (AWS)
echo ====================================================================
echo   Servidor Destino : https://3.143.255.64.nip.io/api
echo   Base de Datos    : AWS RDS PostgreSQL (Multi-AZ)
echo   Total Peticiones : %PETICIONES% objetivo
echo   Usuarios VUs     : %USUARIOS% concurrentes
echo   Tiempo Limite    : %TIEMPO%
echo ====================================================================
echo.

if exist "%~dp0tools\k6.exe" goto MODO_NATIVO
goto MODO_DOCKER

:MODO_NATIVO
echo [MODO NATIVO] Ejecutando con k6 standalone - Cero Docker requerido
echo.
"%~dp0tools\k6.exe" run -e BASE_URL=https://3.143.255.64.nip.io/api -e TOTAL_PETICIONES=%PETICIONES% -e VUS=%USUARIOS% -e DURACION=%TIEMPO% -e MODO=iteraciones "%~dp0k6\estres-masivo.js"
goto FIN

:MODO_DOCKER
echo [MODO DOCKER] k6 local no encontrado, usando imagen Docker...
echo.
docker run --rm -i -v "%~dp0k6:/scripts" grafana/k6 run -e BASE_URL=https://3.143.255.64.nip.io/api -e TOTAL_PETICIONES=%PETICIONES% -e VUS=%USUARIOS% -e DURACION=%TIEMPO% -e MODO=iteraciones /scripts/estres-masivo.js
goto FIN

:FIN
endlocal
