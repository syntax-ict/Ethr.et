# First setup: sign-in is chosen before anyone is invited

> **OWNER DECIDED, 2026-11-16.** In the guided setup (`/setup/guided`):
>
> 1. The steps are **Organisation, Sign-in, People, Go live**. How people sign in is chosen
>    before a list of people is added.
> 2. "Invite them to sign in as soon as they are added" **starts unticked** in the guided
>    setup only. On Employees → Import and on a device's roster it stays ticked.
> 3. Invitations are sent **in slices of 200**, one request after another, wherever they are
>    sent from a list.
>
> "Disabled" means exactly this: the person has **no login row** yet. No new account state and
> no organisation-wide switch were added. The 2026-11-10 decision that a list brings a login for
> everyone by default
> ([`OWNER-DECISION-MASS-ACTIVATION.md`](OWNER-DECISION-MASS-ACTIVATION.md)) stands everywhere
> outside first setup.

## What forced it

- **Invitations left before the organisation had chosen how employees sign in.** With People
  before Sign-in and the tick ticked, every person with an email was invited the moment the list
  was added. People without an email cannot be given a printed code or a starting password until
  employee-number sign-in is chosen, and that choice was the next step.
- **A first list is the least-checked data an organisation has.** A wrong address sends a
  72-hour activation link to a stranger, and a sent email cannot be recalled. An employee
  record can be corrected afterwards.
- **Activation mail is essential mail.** `MailBudget` never holds it
  (`App\Notifications\Contracts\EssentialMail`), and it counts against one daily cap shared by
  every organisation, `config/mail.php` `daily_cap` (`MAIL_DAILY_CAP`, default 500). One
  organisation's first list can use a large part of the day for all of them.
- **A list of more than 1,000 people got a 422.** `POST /api/v1/employees/logins` accepts at most
  1,000 ids (`CreateEmployeeLoginsRequest`), and the automatic send posted every id in one request.
  The controller creates every login inside the request and only claims that a few hundred fit
  the host's 30 seconds, so the browser now sends 200 at a time.

## What it costs

An organisation that skips the offer to invite has staff who cannot use QR, mobile or web
punching, or self-service, until HR sends invitations from Employees or from the card after the
commit. The kiosk, attendance terminals, payroll and leave recorded by HR do not need a login.

## Not done, on purpose

The per-organisation daily ceiling on invitations, first listed here, was added afterwards
(2026-11-16): see "A daily ceiling per organisation" in
[`../EMPLOYEE-INVITATIONS-PLAN.md`](../EMPLOYEE-INVITATIONS-PLAN.md#a-daily-ceiling-per-organisation).

- **A sign-in column in the employee workbook, and sign-in state on the Employees list.**
  Planned next; this record only moves the default and the order.

See [`../MIGRATION.md`](../MIGRATION.md), "What the whole list is brought in with".
