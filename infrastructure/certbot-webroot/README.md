Webroot for Let's Encrypt http-01 challenges.

Bind-mounted read-only into the nginx container at /var/www/certbot by
docker-compose.prod.yml. certbot writes challenge files here from the host.
Only needed for non-wildcard certificates — the wildcard that ETHR normally
runs must use dns-01. See docs/DEPLOYMENT.md.
