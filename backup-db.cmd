@echo off
setlocal
if not exist "backups" mkdir backups

for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value') do set datetime=%%I
set FECHA=%datetime:~0,8%_%datetime:~8,6%
set ARCHIVO=backups\adbana_pgdump_%FECHA%.sql

echo === Generando respaldo SQL directo desde PostgreSQL (Docker) ===
docker compose exec -T db pg_dump -U adbana -d adbana --clean --if-exists > "%ARCHIVO%"
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] No se pudo generar el volcado SQL con pg_dump. Verifica que el contenedor db este corriendo.
    exit /b 1
)

echo === Generando respaldo logico JSON con SHA-256 via Artisan ===
docker compose exec -T app php artisan adbana:backup

echo [OK] Respaldo completado exitosamente en: %ARCHIVO%
endlocal
