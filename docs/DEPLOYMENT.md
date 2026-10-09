# Deployment

**ETHR deploys to Ethio Telecom Plesk shared hosting.** The route is
[`deployment/PLESK-GO-LIVE.md`](deployment/PLESK-GO-LIVE.md): GitHub `main` → the *Plesk
release* workflow → the `production` branch → Plesk Git → the Laravel Toolkit. What the
deployment may and may not depend on is [`deployment/SHARED-HOSTING-CONTRACT.md`](deployment/SHARED-HOSTING-CONTRACT.md),
and whether it can go live today is [`deployment/CUTOVER-CHECKLIST.md`](deployment/CUTOVER-CHECKLIST.md).

The guide that used to live at this path described the VPS stack (`docker-compose.prod.yml`,
nginx, Redis, MinIO, a worker and a Reverb daemon). Those assets were removed on 2026-09-26
([`deployment/VPS-DECOMMISSION.md`](deployment/VPS-DECOMMISSION.md)) and the Docker
development stack on 2026-09-30. The guide is kept as a record at
[`archive/vps/DEPLOYMENT.md`](archive/vps/DEPLOYMENT.md); nothing in it is a procedure any more.
