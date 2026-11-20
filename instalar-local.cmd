@echo off
setlocal
cd /d "%~dp0"
docker info >nul 2>&1
if errorlevel 1 (
  echo Abre Docker Desktop y espera a que el motor este listo.
  exit /b 1
)
docker compose -f docker-compose.yml -f docker-compose.fast.yml config --quiet
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml build app
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml up -d db redis
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml run --rm --no-deps app composer install --prefer-dist --no-interaction --optimize-autoloader
if errorlevel 1 exit /b 1
findstr /b /c:"APP_KEY=base64:" src\.env >nul
if errorlevel 1 (
  docker compose -f docker-compose.yml -f docker-compose.fast.yml run --rm --no-deps app php artisan key:generate
  if errorlevel 1 exit /b 1
)
set /a intentos=0
:esperar_db
docker compose -f docker-compose.yml -f docker-compose.fast.yml exec -T db pg_isready -U adbana -d adbana >nul 2>&1
if not errorlevel 1 goto migrar
set /a intentos+=1
if %intentos% geq 30 exit /b 1
timeout /t 2 /nobreak >nul
goto esperar_db
:migrar
docker compose -f docker-compose.yml -f docker-compose.fast.yml run --rm --no-deps app php artisan migrate --force
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml up -d
if errorlevel 1 exit /b 1
echo Adbana disponible en http://localhost:8080/panel
endlocal
