# Sub-processors

> **DRAFT — NOT LEGAL ADVICE.** As configured on 2026-11-25, from the code and the deployment documents. Counsel has not confirmed it. Register row L15. Rows marked `[confirm]` depend on what the owner has configured in production, which the repository cannot see.

The third parties that receive personal data held in ETHR, and the external calls that carry none. The DPA ([`DATA-PROCESSING-AGREEMENT.md`](DATA-PROCESSING-AGREEMENT.md) §6) promises customers notice before this list changes, so **change it before the configuration changes, not after**.

## A. Sub-processors: receive personal data

| Sub-processor | What they do | Personal data they receive | Location | Source |
|---|---|---|---|---|
| **Ethio Telecom** (Plesk shared hosting) | Hosts the application, database, uploaded files and on-host backups | All of it | Ethiopia | `docs/deployment/host-evidence/HOST-EVIDENCE.json`; [`../DEPLOYMENT.md`](../DEPLOYMENT.md) |
| Email provider (SMTP) `[confirm: provider]` | Sends sign-in, reset, activation and notification emails | Recipient's name and email; the message | `[confirm]` | `api/config/mail.php` |
| SMS gateway: Ethio Telecom, or the gateway configured in `/admin/sms` `[confirm]` | Sends sign-in codes and notifications by SMS | Phone number; the message | Ethiopia, for Ethio Telecom | [`../operations/SMS-DELIVERY.md`](../operations/SMS-DELIVERY.md) |
| Off-host backup storage, S3-compatible `[confirm: provider]` | Holds the nightly encrypted backup | All of it, encrypted on the server before upload | **Must be in Ethiopia** (Art. 22(1), class 1). Since 2026-11-25 production copies only to storage stated to be in Ethiopia (`BackupResidency`, register row L16) | [`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) |
| **Google Drive**, the backup fallback, if configured | Holds the encrypted backup when the primary storage fails | All of it, encrypted | **Outside Ethiopia.** So production never copies to it (since 2026-11-25, register row L16). Off production only; archives it already holds can still be restored | `BACKUP-RESTORE.md`, *Art. 22 note* |
| Error tracking (Sentry), only if a DSN is set | Receives error reports | Personal data is excluded by default and scrubbed (`SentryScrubber`); session replay is off | The deployment documents call for a self-hosted instance `[confirm]` | `src/src/instrumentation-client.ts` |
| Browser push services (the browser vendor's), only when push keys are set | Delivers push notifications | A notification title and body | Outside Ethiopia (the browser vendor's service) | VAPID configuration |

**Not sub-processors:** destinations an organisation chooses itself, such as its own webhooks ([`../WEBHOOKS.md`](../WEBHOOKS.md)). Those are the organisation's recipients, not ETHR's. ETHR uses no hosted AI model ([`../decisions/OWNER-DECISION-AI-PROVIDER.md`](../decisions/OWNER-DECISION-AI-PROVIDER.md)) and no analytics service.

## B. External calls that carry no personal data

| Call | When | What leaves |
|---|---|---|
| MediaPipe face model from `storage.googleapis.com` | When an organisation turns the liveness check on | A download request from the phone. No image is sent |
| Map tiles and address search (OpenStreetMap, `nominatim.openstreetmap.org`) | When someone uses the map picker to place an attendance site | The area being viewed or the address typed. A site address is an organisation's, not a person's |
| Google Maps (`maps.googleapis.com`) | Only if a key is configured | As above |
| GitHub Actions (`.github/workflows/cron.yml`) | Every few minutes, to drain the queue and run the schedule | A token-authenticated call to ETHR's own endpoints. The response it prints is job status, not personal data |
