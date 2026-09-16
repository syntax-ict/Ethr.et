#!/usr/bin/env bash
set -euo pipefail

# Sets up MariaDB primary→replica replication inside Docker Compose.
# Run once after initial deployment or when re-initializing the replica.
#
# Usage: ./scripts/setup-replication.sh
#
# Prerequisites:
#   - docker-compose.prod.yml stack is running
#   - Both mariadb and mariadb-replica are healthy

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
PRIMARY="mariadb"
REPLICA="mariadb-replica"
REPL_USER="${DB_REPL_USER:-replicator}"
REPL_PASS="${DB_REPL_PASSWORD:-$(openssl rand -base64 24)}"
# Must match DB_DATABASE in the root .env that docker-compose.prod.yml reads.
APP_DB="${DB_DATABASE:-ethr}"

echo "=== MariaDB Replication Setup ==="

echo "1. Creating replication user on primary..."
docker compose -f "$COMPOSE_FILE" exec -T "$PRIMARY" mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e "
  CREATE USER IF NOT EXISTS '${REPL_USER}'@'%' IDENTIFIED BY '${REPL_PASS}';
  GRANT REPLICATION SLAVE ON *.* TO '${REPL_USER}'@'%';
  FLUSH PRIVILEGES;
"

echo "2. Getting primary binary log position..."
MASTER_STATUS=$(docker compose -f "$COMPOSE_FILE" exec -T "$PRIMARY" \
  mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e "SHOW MASTER STATUS\G")

LOG_FILE=$(echo "$MASTER_STATUS" | grep "File:" | awk '{print $2}')
LOG_POS=$(echo "$MASTER_STATUS" | grep "Position:" | awk '{print $2}')

echo "   Log file: $LOG_FILE"
echo "   Position: $LOG_POS"

echo "3. Dumping primary database (application schema only)..."
# --databases "$APP_DB", never --all-databases. The latter carries the primary's
# mysql schema across and overwrites the replica's own accounts — including
# `healthcheck@localhost`, whose password this container generated for itself at
# first start and keeps in a local .my-healthcheck.cnf. After that the replica's
# healthcheck fails permanently ("Access denied for user 'healthcheck'@
# 'localhost'") even though replication is running fine, and because api
# declares `mariadb-replica: condition: service_healthy` the api service then
# refuses to start on every subsequent deploy and host reboot.
#
# The replica does not need the primary's user table: docker-compose.prod.yml
# provisions MARIADB_USER/MARIADB_PASSWORD (DB_READ_USERNAME/DB_READ_PASSWORD)
# on it at first start, with privileges on MARIADB_DATABASE, and that is the
# account api/.env.production points the read connection at.
docker compose -f "$COMPOSE_FILE" exec -T "$PRIMARY" \
  mariadb-dump -uroot -p"${DB_ROOT_PASSWORD}" \
  --databases "$APP_DB" --master-data=2 --single-transaction --routines --triggers \
  | docker compose -f "$COMPOSE_FILE" exec -T "$REPLICA" \
  mariadb -uroot -p"${DB_ROOT_PASSWORD}"

echo "4. Configuring replica..."
docker compose -f "$COMPOSE_FILE" exec -T "$REPLICA" mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e "
  STOP SLAVE;
  CHANGE MASTER TO
    MASTER_HOST='${PRIMARY}',
    MASTER_USER='${REPL_USER}',
    MASTER_PASSWORD='${REPL_PASS}',
    MASTER_LOG_FILE='${LOG_FILE}',
    MASTER_LOG_POS=${LOG_POS};
  START SLAVE;
"

echo "5. Verifying replication status..."
sleep 2
docker compose -f "$COMPOSE_FILE" exec -T "$REPLICA" \
  mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e "SHOW SLAVE STATUS\G" \
  | grep -E "(Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_Error)"

echo ""
echo "=== Replication setup complete ==="
echo "Replication user: ${REPL_USER}"
echo "Replication password: ${REPL_PASS}"
echo ""
echo "Save these credentials securely. Add to .env.production:"
echo "  DB_REPL_USER=${REPL_USER}"
echo "  DB_REPL_PASSWORD=${REPL_PASS}"
