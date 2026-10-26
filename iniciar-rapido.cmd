@echo off
setlocal
cd /d "%~dp0"
docker compose -f docker-compose.yml -f docker-compose.fast.yml config --quiet
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml up -d db redis
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml run --rm --no-deps app composer install --prefer-dist --no-interaction --optimize-autoloader
if errorlevel 1 exit /b 1
docker compose -f docker-compose.yml -f docker-compose.fast.yml up -d
if errorlevel 1 exit /b 1
echo Adbana disponible en http://localhost:8080/panel
endlocal
