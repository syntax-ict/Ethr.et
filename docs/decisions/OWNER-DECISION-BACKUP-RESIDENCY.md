# Off-host backups stay in Ethiopia: an Ethiopian cloud provider, not a foreign one

> **OWNER DECIDED, 2026-11-27.** Off-host backup copies go to an **Ethiopian** cloud provider,
> which ETHR will subscribe to. Foreign object storage is **not** the primary destination:
> Cloudflare R2 and Backblaze B2 were offered and declined. Google Drive is **no longer a
> production destination at all**: it stores outside Ethiopia, so a copy to it is refused in
> production. It stays available off production, where the rule is not enforced.

## The instruction, as given

Recorded verbatim from the session of 2026-11-27, after an agent proposed R2 or B2:

> ETHR follow ethiopian cloud provider backup. ethr will subscribe from ethiopian server provider.

## Why it holds without further argument

A backup archive of this product contains every salary figure and every identity document of
every organisation on it. The product is sold to Ethiopian organisations, including a government
pitch, and is hosted in Ethiopia. Sending the one complete copy of all of it to a foreign
jurisdiction is a different product with a different risk profile, whatever it costs.

## What the decision requires technically

The off-host copy is wired through `league/flysystem-aws-s3-v3`
([`api/config/backup.php`](../../api/config/backup.php)), so the destination must expose an
**S3-compatible API**. An Ethiopian server is not automatically enough.

- **Needed from the provider:** an HTTPS endpoint URL, a region string, a bucket, and an access
  key pair scoped to that one bucket.
- **Path-style addressing is already switched on** in the platform console, which is correct for
  every non-AWS provider.
- **If the provider sells servers but no S3 API,** run MinIO on a VPS there. It speaks S3
  natively, so it plugs into the existing console with no code change and no new dependency, and
  the data stays in Ethiopia.

### The trap worth naming before anyone subscribes

ETHR is hosted on Ethio Telecom. A backup placed on the same provider's infrastructure, or worse
in the same building, is off-*host* but not off-*site*: one facility-level loss takes the
application and its only backup together. `api/config/backup.php` already says the smaller
version of this about the local disk, that a backup stored on the disk it is protecting survives
an application fault and nothing else. The same logic applies one level up.

**So ask which data centre, and prefer one that is not the data centre serving the application.**

### Candidates, each needing confirmation before money changes hands

None of these is verified by the provider's own documentation; all three came from a search on
2026-11-27 and are starting points for a phone call, not findings.

| Provider | What was found | What is unconfirmed |
|---|---|---|
| Ethio Telecom Telecloud | Object storage is listed among its cloud storage options, in its own data centres | S3 compatibility is claimed only by a third-party field guide, which cites an `et-global-3` region and a checksum workaround. The official pages do not mention S3. It is also the provider already hosting the application |
| AletCloud | Described as Ethiopia's self-service sovereign cloud with S3-compatible object storage, billed in birr | A secondary source only, and its hardware is described as running in Ethio Telecom's Bole data centre, which raises the same-site question above |
| WebSprix | A sovereign cloud built on OpenStack | No object storage product is mentioned anywhere. OpenStack's Swift can expose an S3 API, but nothing confirms WebSprix offers it |

Raxio ET1 is an independent Tier III facility in Ethiopia, so a provider colocated there would be
genuinely separate from Ethio Telecom.

### What to put to a provider, in writing

1. Do you offer an S3-compatible object storage API? Give the endpoint URL, the region string,
   and whether path-style addressing is required.
2. Which physical data centre holds the data?
3. Can an access key be scoped to a single bucket, with read and write only?
4. Price for roughly fourteen nightly archives retained, plus the egress cost of one full
   restore.
5. Is the endpoint served over TLS with a certificate that validates?

## The encryption key is the part that gets skipped

The console generates the key itself and it is never typed. It is revealed once, through
`BackupStorageController::revealKey()`, and **it has to be stored somewhere that is not this
server**. The archives cannot be opened when the host is the thing that was lost, which makes an
off-host copy whose only key sat on the dead server a comforting file rather than a backup.

Two related facts, both read from the code rather than assumed:

- **With no key, nothing is copied at all.** The blocker becomes `off_host_key_missing` and the
  upload does not happen, so there is no silent unencrypted copy sitting in a bucket.
- **`key:generate` on production would stop every copy silently.** The stored key is encrypted at
  rest with `APP_KEY`, so changing `APP_KEY` makes `cipher()` return null. This is a second and
  independent reason for a rule the project already has.

## Status

**Not yet subscribed, and the gap is live.** As measured on 2026-11-27 at 11:51Z, the last backup
of any kind was 2026-11-23 and no off-host copy has ever been made, because `ethr:backup` is a
`Schedule::command` and those never ran on production until 2026-11-27. See
[`BASELINE.md`](../audit/BASELINE.md) for the measurement log.

## It is enforced in code, not only written here

This decision stopped being a document on 2026-11-27, when the residency rule landed in
`App\Support\BackupResidency`:

- `enforced()` is true **in production only**, so development and the test suite are unaffected.
- A destination that has not been stated to be in Ethiopia **refuses copies** in production, for
  the console storage and the `.env` disk alike.
- Google Drive is never copied to in production, because it stores outside Ethiopia. A restore
  from an archive Drive already holds still works, which is the point of a fallback that was
  written to before the rule existed.
- The console demands the location statement **with every save**, and restates it whenever the
  storage moves. Who stated it and when are recorded on `backup_storage_settings` and audited.
- The refusal is reported as a warning in the operations centre rather than failing silently.

So a bucket outside Ethiopia cannot quietly become the destination: it is refused, and the refusal
is visible. What remains the owner's is the subscription itself.
