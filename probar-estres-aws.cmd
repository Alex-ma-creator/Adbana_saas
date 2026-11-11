@echo off
setlocal
set PETICIONES=%1
if "%PETICIONES%"=="" set PETICIONES=50000

set USUARIOS=%2
if "%USUARIOS%"=="" set USUARIOS=100

set ESCRITURAS=%3
if "%ESCRITURAS%"=="" set ESCRITURAS=%PETICIONES%

echo ====================================================================
echo   ADBNANA SaaS - PRUEBA DE ESTRES Y RESISTENCIA EN LA NUBE (AWS)
echo ====================================================================
echo   Servidor Destino : https://3.143.255.64.nip.io/api
echo   Base de Datos    : AWS RDS PostgreSQL (Multi-AZ)
echo   Total Operaciones: %PETICIONES%
echo   Usuarios VUs     : %USUARIOS% concurrentes
echo   Escrituras en BD : %ESCRITURAS% pedidos registrados
echo ====================================================================
echo.

docker run --rm -i -v "%~dp0k6:/scripts" grafana/k6 run ^
  -e BASE_URL=https://3.143.255.64.nip.io/api ^
  -e TOTAL_PETICIONES=%PETICIONES% ^
  -e VUS=%USUARIOS% ^
  -e REGISTRAR_EN_BD=%ESCRITURAS% ^
  /scripts/estres-masivo.js

endlocal
