# Data breach response

What to do when personal data in ETHR may have been seen, changed, lost or
taken by someone who should not have it. Drafted 2026-10-17 for the owner to
adopt. **The legal points below follow the Personal Data Protection
Proclamation No. 1321/2024 as read for this product; have counsel confirm them
before relying on them.**

Related: [`ON-CALL.md`](ON-CALL.md) for outages, [`CUSTODY.md`](CUSTODY.md)
for who holds which credential, [`IMPERSONATION.md`](IMPERSONATION.md) for the
only sanctioned way to look inside an organisation.

## Who is responsible for what

| Data | Controller | ETHR's role | Who notifies the Authority and the people affected |
|---|---|---|---|
| An organisation's employee records: people, attendance and selfies, payroll, documents | The organisation, the employer | Processor | The organisation (Arts. 43 and 44). ETHR tells the organisation at once, with the facts it needs to do so |
| ETHR's own customer accounts: who signed up, billing, platform admins | ETHR | Controller | ETHR |

The clock is **72 hours from becoming aware** of a breach, for the
controller's notice to the Ethiopian Communications Authority (Art. 43). When a
breach is likely to put people at high risk, they are told too, without undue
delay (Art. 44). ETHR's aim is to give an affected organisation everything it
needs **within 24 hours**, so it has time to meet its own deadline.

Sensitive data held here, which raises the risk of any breach: attendance
selfies (biometric, Arts. 2(5) and 2(6)), national ID and TIN numbers (stored
encrypted), bank details, salaries and scanned identity documents.

## The first hour

1. **Write down the time you became aware.** Every deadline counts from it.
2. **Preserve evidence before changing anything.** Download the day's
   `~/ethr/api/storage/logs/laravel-YYYY-MM-DD.log` files from Plesk File
   Manager. **They are kept for 14 days only.** The audit log is append-only in
   the database and stays.
3. **Contain** with the narrowest tool that stops it:

   | If the cause is | Do this |
   |---|---|
   | One account misused | Deactivate it: Settings → Users. If it is an admin, also reset its MFA |
   | An API key leaked | Revoke it: Settings → API Keys (`DELETE /api-keys/{key}`) |
   | A webhook sending data to the wrong place | Delete it: Settings → Webhooks |
   | A whole organisation compromised | Suspend it from the platform console (`PUT /admin/tenants/{id}/status`) |
   | The maintenance or cron endpoint exposed | Empty `MAINTENANCE_TOKEN`. Rotate `CRON_TOKEN` together with the GitHub copies ([`ON-CALL.md`](ON-CALL.md), *Secrets*) |
   | Hosting credentials leaked (Plesk, database, mail) | Change them in Plesk and update `~/ethr/api/.env` |
   | A code defect exposing data | Roll back to the last good release ([`rollback.md`](../deployment/shared-hosting/rollback.md)), then fix forward |

   **Never rotate `APP_KEY`.** It makes every encrypted column, and every
   backup taken under the old key, unreadable. That would turn a breach into
   data loss.

## Within 24 hours: assess and tell the organisations

Work out, from the audit log and the saved logs:

- which organisations and how many people are affected;
- which kinds of data, especially the sensitive kinds above;
- what happened: seen, changed, deleted or copied out;
- whether it has stopped, and what was done to stop it.

Then tell each affected organisation's administrators, in writing, with:

> On [date and time, EAT] ETHR became aware that [what happened]. It affects
> [number] people in your organisation and these kinds of data: [list]. It has
> been contained by [action] at [time]. Under Proclamation No. 1321/2024 your
> organisation, as controller, may need to notify the Ethiopian Communications
> Authority within 72 hours of [awareness time], and the people affected if
> the risk to them is high. Contact [name, channel] for anything you need.

Send it through a channel you know reaches them, not only an in-app notice.

## Within 72 hours: ETHR's own notices

When ETHR is the controller (its own customer accounts), notify the Authority
within 72 hours of becoming aware, and the people affected when the risk to
them is high. Give what happened, the data and people involved, the likely
consequences, and what was done. If not everything is known yet, notify with
what is known and follow up.

## Afterwards

- **Keep the incident record outside this repository.** The repository is
  public. Record the timeline, decisions, notices sent and their times in the
  owner's private records.
- Fix the cause, with a test that fails without the fix.
- Rehearse anything that was slow. The restore path in particular has never
  been rehearsed on this host ([`ON-CALL.md`](ON-CALL.md), *Data is wrong or
  lost*).

## Not yet in place

- **ECA registration (Art. 33)** is an owner action, not yet done.
- **A named contact** for breach reports: [`SECURITY.md`](../../SECURITY.md)
  deliberately publishes no address until one is monitored.
- **Log retention** is 14 days, which can be short for an investigation that
  starts late. Lengthening it costs disk space against the 5 GB plan.
