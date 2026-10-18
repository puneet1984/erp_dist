@echo off
REM Production deployment script for ERP application (Windows)

setlocal enabledelayedexpansion

echo 🚀 Starting ERP production deployment...

REM Check if .env.production exists
if not exist .env.production (
    echo ❌ Error: .env.production not found!
    echo    Copy .env.production.example to .env.production and configure it
    exit /b 1
)

REM Load environment variables
for /f "usebackq tokens=1,2 delims==" %%a in (`.env.production`) do (
    if not "%%a"=="" if not "%%a"=="#" set "%%a=%%b"
)

REM Validate required variables
if "%DB_PASS%"=="" (
    echo ❌ Error: DB_PASS not set in .env.production
    exit /b 1
)

if "%DB_PASS%"=="your_very_secure_random_password_here" (
    echo ❌ Error: DB_PASS still using default value in .env.production
    exit /b 1
)

if "%SITE_URL%"=="" (
    echo ❌ Error: SITE_URL not configured in .env.production
    exit /b 1
)

if "%SITE_URL%"=="http://your-server-ip:8080/erp/" (
    echo ❌ Error: SITE_URL still using default value in .env.production
    exit /b 1
)

echo ✅ Environment validated

REM Build and start services
echo 🔨 Building production images...
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production build --no-cache

echo 🚀 Starting services...
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production up -d

REM Wait for health checks
echo ⏳ Waiting for services to be healthy...
timeout /t 10 >nul

REM Check service status
echo 📊 Service status:
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production ps

REM Show logs
echo 📋 Recent app logs:
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production logs --tail=20 app

echo.
echo ✅ Deployment complete!
echo    Application: %SITE_URL%
echo    Database: Internal (db:3306)
echo.
echo 📝 Useful commands:
echo    View logs:    docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production logs -f app
echo    Stop:         docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production down
echo    Restart:      docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production restart
echo    Backup DB:    docker exec ^(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production ps -q db^) mysqldump -u root -p"%DB_PASS%" "%DB_NAME%" ^> backup_%date:~-4,4%%date:~-10,2%%date:~-7,2%.sql