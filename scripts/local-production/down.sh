#!/usr/bin/env bash
#
# Stop the local production rehearsal: Apache and the FastCGI backend.
#
# Leaves the docroot, the application copy and the database in place so up.sh can
# restart in seconds. To remove everything:
#
#   scripts/local-production/down.sh && rm -rf .local-production
#
# The rehearsal's database survives that. Drop it by hand if you want it gone — and read
# the name first: until 2026-09-28 this block named the plain `ethr` database instead,
# which is the one api/.env uses, with every dev tenant and employee in it.
#   /c/xampp/mysql/bin/mysql.exe -u root -e "DROP DATABASE ethr_local_prod;
#       DROP USER 'ethr_localprod'@'127.0.0.1'; DROP USER 'ethr_localprod'@'localhost';"
#
# HOW IT FINDS THE PROCESSES, and why not by recorded PID. up.sh backgrounds httpd
# and php-cgi from Git Bash, where `$!` is an MSYS job pid — NOT the Windows pid that
# `taskkill /PID` needs. Recording `$!` and killing it silently succeeded while both
# servers kept running and holding their ports; measured 2026-09-27. Resolving the pid
# from the LISTENING socket is namespace-independent and kills exactly what is serving.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APACHE_DIR="$REPO_ROOT/.local-production/apache"
CONF="$APACHE_DIR/httpd.conf"

if [ ! -f "$CONF" ]; then
    echo "  nothing to stop — no $CONF"
    exit 0
fi

PORT="$(grep -m1 '^Listen ' "$CONF" | awk '{print $2}')"
FCGI_PORT="$(grep -m1 -oE 'fcgi://127\.0\.0\.1:[0-9]+' "$CONF" | grep -oE '[0-9]+$' || true)"

stop_port() {
    local port="$1" label="$2"

    [ -n "$port" ] || return 0

    # Only LISTENING sockets: an ESTABLISHED client connection to the same port
    # would otherwise offer up a browser's pid to be killed.
    local pids
    pids="$(netstat -ano 2>/dev/null \
            | grep 'LISTENING' \
            | grep -E "[:.]${port}[[:space:]]" \
            | awk '{print $NF}' | sort -u)"

    if [ -z "$pids" ]; then
        echo "  $label: nothing listening on $port"
        return 0
    fi

    for pid in $pids; do
        echo "  $label: stopping pid $pid (port $port)"
        # //T for the tree — Apache forks a child on Windows, and killing only the
        # parent leaves the child holding the socket.
        taskkill //PID "$pid" //T //F >/dev/null 2>&1 || true
    done
}

stop_port "$PORT" "apache"
stop_port "$FCGI_PORT" "php-cgi"

rm -f "$APACHE_DIR/httpd.bgpid" "$APACHE_DIR/phpcgi.bgpid"
sleep 1

still=0
for p in "$PORT" "$FCGI_PORT"; do
    [ -n "$p" ] || continue
    if netstat -ano 2>/dev/null | grep LISTENING | grep -qE "[:.]${p}[[:space:]]"; then
        echo "  WARNING: something is still listening on $p"
        still=1
    fi
done

if [ "$still" -eq 0 ]; then
    # taskkill //F is not a clean shutdown, so httpd never removes its own pid file and
    # the NEXT start logs `AH00098: pid file ... overwritten -- Unclean shutdown of
    # previous Apache run?` as a [core:warn]. Harmless in itself, and it still matters:
    # LOCAL-PRODUCTION-SETUP.md §4 and BASELINE.md §23a both claim zero Apache log lines
    # above notice, so a warning nobody removed makes a documented measurement false
    # after the first restart. Removing the file is only safe here — after the ports are
    # confirmed free, i.e. after nothing is running to own it.
    rm -f "$APACHE_DIR/httpd.pid"
    echo "  stopped; ports $PORT${FCGI_PORT:+ and $FCGI_PORT} are free"
fi
exit 0
