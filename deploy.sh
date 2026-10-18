#!/bin/bash
# Production deployment script for ERP application

set -e

echo "🚀 Starting ERP production deployment..."

# Check if .env.production exists
if [ ! -f .env.production ]; then
    echo "❌ Error: .env.production not found!"
    echo "   Copy .env.production.example to .env.production and configure it"
    exit 1
fi

# Load environment variables
export $(grep -v '^#' .env.production | xargs)

# Validate required variables
if [ -z "$DB_PASS" ] || [ "$DB_PASS" = "your_very_secure_random_password_here" ]; then
    echo "❌ Error: DB_PASS not set or using default value in .env.production"
    exit 1
fi

if [ -z "$SITE_URL" ] || [ "$SITE_URL" = "http://your-server-ip:8080/erp/" ]; then
    echo "❌ Error: SITE_URL not configured in .env.production"
    exit 1
fi

echo "✅ Environment validated"

# Build and start services
echo "🔨 Building production images..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production build --no-cache

echo "🚀 Starting services..."
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production up -d

# Wait for health checks
echo "⏳ Waiting for services to be healthy..."
sleep 10

# Check service status
echo "📊 Service status:"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production ps

# Show logs
echo "📋 Recent app logs:"
docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production logs --tail=20 app

echo ""
echo "✅ Deployment complete!"
echo "   Application: $SITE_URL"
echo "   Database: Internal (db:3306)"
echo ""
echo "📝 Useful commands:"
echo "   View logs:    docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production logs -f app"
echo "   Stop:         docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production down"
echo "   Restart:      docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production restart"
echo "   Backup DB:    docker exec \$(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.production ps -q db) mysqldump -u root -p\"\$DB_PASS\" \"\$DB_NAME\" > backup_\$(date +%F).sql"