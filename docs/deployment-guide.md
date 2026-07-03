# ETHR Deployment Guide

**Ethiopian Workforce Operating System — v1.0**

---

## System Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| OS | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |
| RAM | 4 GB | 8 GB+ |
| CPU | 2 vCPU | 4 vCPU |
| Disk | 40 GB SSD | 100 GB SSD |
| Docker Engine | 24+ | 24+ |
| Docker Compose | v2+ | v2+ |

> All services run in Docker. No direct PHP, Node, or MariaDB installation needed on the host.

---

## 1. First-Time Server Setup

```bash
# Install Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker

# Install Docker Compose v2
sudo apt install docker-compose-plugin

# Clone the repository
git clone https://github.com/your-org/ethr.git /opt/ethr
cd /opt/ethr
```

---

## 2. Environment Configuration

```bash
# Copy the production environment template
cp api/.env.production.example api/.env

# Edit with your values
nano api/.env
```

### Required `.env` values

```env
APP_KEY=base64:...          # Generate: docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));"
APP_URL=https://ethr.yourdomain.et

DB_HOST=db
DB_DATABASE=ethr_production
DB_USERNAME=ethr
DB_PASSWORD=STRONG_PASSWORD_HERE

REDIS_HOST=redis

MINIO_ENDPOINT=minio
MINIO_KEY=your_minio_key
MINIO_SECRET=your_minio_secret
MINIO_BUCKET=ethr-files

REVERB_APP_KEY=your_reverb_key
REVERB_APP_SECRET=your_reverb_secret

MAIL_HOST=smtp.your-provider.com
MAIL_FROM_ADDRESS=noreply@yourdomain.et

CORS_ALLOWED_ORIGINS=https://yourdomain.et,https://demo.yourdomain.et

# Frontend
NEXT_PUBLIC_API_URL=https://yourdomain.et/api/v1
```

---

## 3. SSL Certificate

```bash
# Install Certbot
sudo apt install certbot

# Issue wildcard certificate (for *.yourdomain.et)
sudo certbot certonly --manual --preferred-challenges dns \
  -d yourdomain.et -d "*.yourdomain.et"

# Certificate paths (update nginx.conf)
# /etc/letsencrypt/live/yourdomain.et/fullchain.pem
# /etc/letsencrypt/live/yourdomain.et/privkey.pem
```

Update `infrastructure/nginx.conf` with your domain and certificate paths.

---

## 4. Deploy

```bash
cd /opt/ethr

# First deploy
./scripts/deploy.sh

# The script:
# 1. Backs up database
# 2. Builds Docker images
# 3. Runs migrations
# 4. Clears caches
# 5. Restarts services
# 6. Verifies health
```

### Seed the demo tenant (optional)

```bash
./scripts/seed.sh
# Creates: demo.yourdomain.et
# Login:   admin@demo.ethr.et / password
```

---

## 5. Subsequent Deployments

```bash
cd /opt/ethr
git pull origin main
./scripts/deploy.sh
```

---

## 6. Rollback

```bash
# List available backups
ls /backups/ethr/

# Rollback to a specific backup
./scripts/rollback.sh /backups/ethr/ethr_db_20260701_120000.sql.gz
```

---

## 7. Backup

```bash
# Manual backup
./scripts/backup.sh

# Automated: add to crontab
0 2 * * * /opt/ethr/scripts/backup.sh >> /var/log/ethr-backup.log 2>&1
```

---

## 8. Service Management

```bash
# Status of all services
docker compose -f docker-compose.prod.yml ps

# View logs
docker compose -f docker-compose.prod.yml logs -f api
docker compose -f docker-compose.prod.yml logs -f nginx

# Restart a service
docker compose -f docker-compose.prod.yml restart api

# Run artisan commands
docker compose -f docker-compose.prod.yml exec api php artisan ...

# Access database
docker compose -f docker-compose.prod.yml exec db mariadb -u ethr -p ethr_production
```

---

## 9. Health Check

```bash
curl https://yourdomain.et/api/v1/health
# Expected: {"status":"healthy","services":{"api":"healthy","database":"healthy","redis":"healthy","minio":"healthy"}}
```

---

## 10. Adding a New Tenant

Tenants self-register at `https://yourdomain.et/register`. The system automatically:
- Provisions a subdomain (`company.yourdomain.et`)
- Creates the tenant's MinIO bucket
- Sends email verification
- Starts a 6-month trial

No manual database work needed.

---

## 11. Troubleshooting

### Queue workers not processing

```bash
docker compose -f docker-compose.prod.yml restart horizon
# Check: https://yourdomain.et/horizon
```

### Migration failed

```bash
docker compose -f docker-compose.prod.yml exec api php artisan migrate:status
docker compose -f docker-compose.prod.yml exec api php artisan migrate --force
```

### MinIO unreachable

```bash
docker compose -f docker-compose.prod.yml restart minio
docker compose -f docker-compose.prod.yml exec api php artisan health:check
```

### Cache stale

```bash
docker compose -f docker-compose.prod.yml exec api php artisan cache:clear
docker compose -f docker-compose.prod.yml exec api php artisan config:cache
docker compose -f docker-compose.prod.yml exec api php artisan route:cache
docker compose -f docker-compose.prod.yml exec api php artisan view:cache
```

---

## 12. Updating

```bash
cd /opt/ethr
git pull origin main
./scripts/deploy.sh
```

For major version upgrades, read the `CHANGELOG.md` for migration notes before deploying.
