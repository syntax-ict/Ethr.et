# ETHR — Deployment Guide (v2.0)

## System Requirements

### Minimum (up to 50 tenants, 5,000 employees)

| Resource | Requirement |
|---|---|
| OS | Ubuntu Server 22.04+ |
| CPU | 4 vCPU |
| RAM | 8 GB |
| Storage | 100 GB SSD |
| Network | 10 Mbps (Ethiopian VPS) |

### Recommended (up to 200 tenants, 20,000 employees)

| Resource | Requirement |
|---|---|
| OS | Ubuntu Server 22.04+ |
| CPU | 8 vCPU |
| RAM | 16 GB |
| Storage | 500 GB SSD |
| Network | 50 Mbps |

### Software Prerequisites

| Software | Version | Purpose |
|---|---|---|
| Docker Engine | 24+ | Container runtime |
| Docker Compose | v2 | Service orchestration |
| Git | 2.30+ | Code deployment |
| Certbot | Latest | SSL certificate management |

---

## Quick Start (First-Time Setup)

```bash
# 1. Clone repository
git clone https://github.com/your-org/ethr.git /opt/ethr
cd /opt/ethr

# 2. Copy environment files
cp api/.env.production api/.env
cp src/.env.production src/.env.local

# 3. Edit environment files (see Environment Variables below)
nano api/.env
nano src/.env.local

# 4. Generate application key
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate

# 5. Start all services
docker compose -f docker-compose.prod.yml up -d

# 6. Run database migrations
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force

# 7. Seed system data (plans, permissions, templates)
docker compose -f docker-compose.prod.yml exec api php artisan db:seed --class=ProductionSeeder --force

# 8. Create super admin account
docker compose -f docker-compose.prod.yml exec api php artisan ethr:create-admin

# 9. Set up SSL (see SSL Setup below)
sudo certbot --nginx -d ethr.et -d '*.ethr.et'

# 10. Verify health
curl https://ethr.et/api/health
```

---

## Docker Compose Services

### Production Configuration (`docker-compose.prod.yml`)

```yaml
services:
  nginx:
    image: nginx:1.25-alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./infrastructure/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./infrastructure/ssl:/etc/nginx/ssl:ro
      - frontend-build:/var/www/frontend
    depends_on:
      api: { condition: service_healthy }
      frontend: { condition: service_healthy }
    deploy:
      resources:
        limits: { memory: 256M }
    restart: unless-stopped

  api:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    volumes:
      - api-storage:/var/www/html/storage
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
    healthcheck:
      test: ["CMD", "php", "artisan", "health:check"]
      interval: 30s
      timeout: 10s
      retries: 3
    deploy:
      resources:
        limits: { memory: 1G, cpus: "2.0" }
    restart: unless-stopped

  frontend:
    build:
      context: ./src
      dockerfile: Dockerfile.prod
    environment:
      - NODE_ENV=production
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://localhost:3000/api/healthz"]
      interval: 30s
      timeout: 10s
      retries: 3
    deploy:
      resources:
        limits: { memory: 512M }
    restart: unless-stopped

  worker:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    command: php artisan horizon
    volumes:
      - api-storage:/var/www/html/storage
    deploy:
      resources:
        limits: { memory: 1G, cpus: "2.0" }
    restart: unless-stopped

  scheduler:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    command: >
      sh -c "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"
    deploy:
      resources:
        limits: { memory: 256M }
    restart: unless-stopped

  reverb:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    command: php artisan reverb:start --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    deploy:
      resources:
        limits: { memory: 256M }
    restart: unless-stopped

  mariadb:
    image: mariadb:10.11
    volumes:
      - mariadb-data:/var/lib/mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--su-mysql", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
    deploy:
      resources:
        limits: { memory: 2G }
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes --maxmemory 512mb --maxmemory-policy allkeys-lru
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
    deploy:
      resources:
        limits: { memory: 512M }
    restart: unless-stopped

  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    volumes:
      - minio-data:/data
    environment:
      MINIO_ROOT_USER: ${MINIO_ACCESS_KEY}
      MINIO_ROOT_PASSWORD: ${MINIO_SECRET_KEY}
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
      interval: 30s
      timeout: 10s
      retries: 3
    deploy:
      resources:
        limits: { memory: 512M }
    restart: unless-stopped

volumes:
  mariadb-data:
  redis-data:
  minio-data:
  api-storage:
  frontend-build:
```

---

## Environment Variables

### API (`api/.env`)

```bash
# Application
APP_NAME=ETHR
APP_ENV=production
APP_DEBUG=false
APP_KEY=                          # CHANGE: php artisan key:generate
APP_URL=https://ethr.et
APP_TIMEZONE=UTC

# Database
DB_CONNECTION=mysql
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=ethr
DB_USERNAME=ethr
DB_PASSWORD=                      # CHANGE: strong random password
DB_ROOT_PASSWORD=                 # CHANGE: strong random password

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

# MinIO (S3-compatible)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=                # CHANGE
AWS_SECRET_ACCESS_KEY=            # CHANGE
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=ethr
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

# Mail
MAIL_MAILER=smtp
MAIL_HOST=                        # CHANGE: SMTP server
MAIL_PORT=587
MAIL_USERNAME=                    # CHANGE
MAIL_PASSWORD=                    # CHANGE
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@ethr.et
MAIL_FROM_NAME=ETHR

# Queue
QUEUE_CONNECTION=redis
HORIZON_PREFIX=ethr_horizon:

# Session
SESSION_DRIVER=redis
SESSION_LIFETIME=120

# Reverb (WebSocket)
REVERB_APP_ID=ethr
REVERB_APP_KEY=                   # CHANGE: random string
REVERB_APP_SECRET=                # CHANGE: random string
REVERB_HOST=reverb
REVERB_PORT=8080

# Sanctum
SANCTUM_STATEFUL_DOMAINS=*.ethr.et

# SMS
SMS_DRIVER=ethiotelecom           # or 'log' for testing
SMS_API_KEY=                      # CHANGE (production only)

# Error Alerting
ERROR_ALERT_EMAIL=admin@ethr.et   # Receives 500 error notifications

# Slow Query Log
DB_SLOW_QUERY_TIME=1000           # Log queries > 1 second
```

### Frontend (`src/.env.local`)

```bash
NEXT_PUBLIC_API_URL=https://ethr.et/api/v1
NEXT_PUBLIC_WS_URL=wss://ethr.et/ws
NEXT_PUBLIC_APP_NAME=ETHR
NEXT_PUBLIC_DEFAULT_LOCALE=en
```

---

## SSL Setup (Let's Encrypt)

```bash
# Install certbot
sudo apt install certbot python3-certbot-nginx

# Obtain wildcard certificate (requires DNS validation)
sudo certbot certonly --manual --preferred-challenges dns \
  -d ethr.et -d '*.ethr.et'

# Or for non-wildcard (simpler, HTTP validation)
sudo certbot --nginx -d ethr.et -d admin.ethr.et

# Auto-renewal (add to crontab)
echo "0 0 * * * certbot renew --quiet && docker compose -f /opt/ethr/docker-compose.prod.yml restart nginx" | sudo tee /etc/cron.d/certbot-renew
```

---

## Operational Scripts

### Deploy (`scripts/deploy.sh`)

```bash
#!/bin/bash
set -e

cd /opt/ethr
echo "$(date): Starting deployment..."

# Pull latest code
git pull origin main

# Build and restart services
docker compose -f docker-compose.prod.yml build --no-cache api frontend
docker compose -f docker-compose.prod.yml up -d

# Run migrations
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force

# Clear and rebuild caches
docker compose -f docker-compose.prod.yml exec api php artisan config:cache
docker compose -f docker-compose.prod.yml exec api php artisan route:cache
docker compose -f docker-compose.prod.yml exec api php artisan view:cache
docker compose -f docker-compose.prod.yml exec api php artisan event:cache

# Restart queue workers (pick up new code)
docker compose -f docker-compose.prod.yml exec api php artisan horizon:terminate

# Health check
sleep 10
curl -sf https://ethr.et/api/health || { echo "HEALTH CHECK FAILED"; exit 1; }

echo "$(date): Deployment complete."
```

### Backup (`scripts/backup.sh`)

```bash
#!/bin/bash
set -e

BACKUP_DIR="/opt/backups/ethr"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Database backup
docker compose -f /opt/ethr/docker-compose.prod.yml exec -T mariadb \
  mysqldump -u root -p"$DB_ROOT_PASSWORD" ethr | gzip > "$BACKUP_DIR/db_$TIMESTAMP.sql.gz"

# MinIO backup (sync to local)
docker compose -f /opt/ethr/docker-compose.prod.yml exec -T minio \
  mc mirror /data "$BACKUP_DIR/minio_$TIMESTAMP/" --quiet

# Keep last 30 days of backups
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete
find $BACKUP_DIR -name "minio_*" -type d -mtime +30 -exec rm -rf {} +

echo "$(date): Backup complete: $BACKUP_DIR"
```

### Restore (`scripts/restore.sh`)

```bash
#!/bin/bash
set -e

BACKUP_FILE=$1
if [ -z "$BACKUP_FILE" ]; then
  echo "Usage: ./restore.sh /path/to/db_TIMESTAMP.sql.gz"
  exit 1
fi

echo "WARNING: This will overwrite the current database. Continue? (yes/no)"
read CONFIRM
[ "$CONFIRM" != "yes" ] && exit 1

# Stop workers
docker compose -f /opt/ethr/docker-compose.prod.yml exec api php artisan horizon:terminate

# Restore database
gunzip -c "$BACKUP_FILE" | docker compose -f /opt/ethr/docker-compose.prod.yml exec -T mariadb \
  mysql -u root -p"$DB_ROOT_PASSWORD" ethr

# Restart services
docker compose -f /opt/ethr/docker-compose.prod.yml restart api worker scheduler

echo "$(date): Restore complete."
```

---

## Monitoring

### Health Endpoint

`GET /api/health` returns:

```json
{
  "status": "healthy",
  "checks": {
    "database": { "status": "up", "response_time_ms": 2 },
    "redis": { "status": "up", "response_time_ms": 1 },
    "minio": { "status": "up", "response_time_ms": 15 },
    "queue": { "status": "up", "depth": { "attendance": 0, "payroll": 0, "default": 3 } },
    "disk": { "status": "up", "used_percent": 45 },
    "memory": { "status": "up", "used_percent": 62 }
  },
  "version": "1.0.0",
  "timestamp": "2026-07-15T10:30:00Z"
}
```

### Horizon Dashboard

Access at: `https://admin.ethr.et/horizon` (super admin only)

Monitors: queue depth, job throughput, failed jobs, worker status.

### Slow Query Log

Enabled in MariaDB: queries > 1 second logged to `mariadb-data/slow-query.log`.

Review: `docker compose exec mariadb tail -f /var/lib/mysql/slow-query.log`

### Log Aggregation

All services log to Docker's JSON file driver with rotation:

```yaml
logging:
  driver: json-file
  options:
    max-size: "10m"
    max-file: "3"
```

View logs: `docker compose -f docker-compose.prod.yml logs -f api`

---

## Automated Backup Schedule

Add to server crontab:

```cron
# Daily backup at 2 AM EAT (23:00 UTC)
0 23 * * * /opt/ethr/scripts/backup.sh >> /var/log/ethr-backup.log 2>&1

# Weekly full MinIO sync on Sunday
0 1 * * 0 /opt/ethr/scripts/backup-minio-full.sh >> /var/log/ethr-backup.log 2>&1
```

---

## Troubleshooting

### Services won't start

```bash
# Check service logs
docker compose -f docker-compose.prod.yml logs api --tail=50
docker compose -f docker-compose.prod.yml logs mariadb --tail=50

# Check disk space
df -h

# Check memory
free -h
```

### Database connection refused

```bash
# Verify MariaDB is running
docker compose -f docker-compose.prod.yml exec mariadb mysql -u root -p -e "SELECT 1"

# Check credentials match .env
grep DB_ api/.env
```

### Queue jobs failing

```bash
# Check failed jobs
docker compose -f docker-compose.prod.yml exec api php artisan queue:failed

# Retry specific job
docker compose -f docker-compose.prod.yml exec api php artisan queue:retry {id}

# Retry all failed
docker compose -f docker-compose.prod.yml exec api php artisan queue:retry all

# Check Horizon status
docker compose -f docker-compose.prod.yml exec api php artisan horizon:status
```

### Slow performance

```bash
# Check slow query log
docker compose -f docker-compose.prod.yml exec mariadb tail -20 /var/lib/mysql/slow-query.log

# Check Redis memory
docker compose -f docker-compose.prod.yml exec redis redis-cli info memory

# Check PHP-FPM status
docker compose -f docker-compose.prod.yml exec api php-fpm-healthcheck

# Check queue depth
docker compose -f docker-compose.prod.yml exec api php artisan horizon:status
```

### SSL certificate renewal failed

```bash
# Manual renewal
sudo certbot renew --force-renewal

# Verify certificate
sudo certbot certificates

# Restart nginx
docker compose -f docker-compose.prod.yml restart nginx
```
