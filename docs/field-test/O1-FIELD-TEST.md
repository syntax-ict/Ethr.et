# Field test O1 — real terminals against ETHR

The gap-closing plan's owner action O1. ETHR's device code was written from the vendors'
published protocols and has **never run against a real terminal**
([`DEVICE_INTEGRATION.md`](../DEVICE_INTEGRATION.md)). This test changes that, and the
[ETHR Bridge](../BRIDGE-DESIGN.md) build waits on what it captures.

It takes about **two hours on site**:

- **one ZKTeco terminal with ADMS / "Cloud Server"** in its menu;
- **one Hikvision access-control terminal** (DS-K1T…) on the same office network.

One person runs it. That person needs:

- admin rights in an ETHR organisation;
- the terminals' admin passwords;
- a laptop with Python 3.8 or newer on the office network.

Use one organisation for the whole test. A pilot customer's is best. Your own works too.

## Before the day

- [ ] Note the office's **public IP address**: open `https://ifconfig.me` from the office
      network. If it changes from day to day, ask the provider for its range
      (for example `196.188.0.0/16`).
- [ ] Pick **one test employee** in ETHR, and give them a **Badge number** (Employees →
      the person → Edit). The badge is how a terminal names a person, so it must match
      the user number on the terminal.
- [ ] Copy [`scripts/field-test/hikvision_capture.py`](../../scripts/field-test/hikvision_capture.py)
      to the laptop.

## Part A — ZKTeco over ADMS (about 45 minutes)

The terminal calls ETHR, so this works from any office network to the live site.

1. In ETHR: **Devices → Register Device**. Set *Adapter Type* to
   **ZKTeco (ADMS: the device connects to ETHR)**, enter the *Serial Number* exactly as
   the terminal shows it, and choose the branch.
2. Open the device and choose **Edit**. Under *Webhook allowed IP addresses*, enter the
   office's public address or range from the checklist above. With an empty list ETHR
   refuses the terminal; the device page says so.
3. On the terminal: **COMM → Cloud Server Setting**.
   - Server address: the host shown on the device page.
   - Port 443, HTTPS on. Use port 80 only on firmware without HTTPS.
   - Enable domain name: on.
4. Wait a minute. The device should show **Online**.
5. Enrol the test employee **on the terminal**, using their badge number as the user
   number, and punch in.
6. **Check:** within a minute the punch appears under Attendance for that person, with
   source *biometric* and the right time.
7. Turn on **Send commands to this device** (Edit), then press **Set clock now**.
   **Check:** the *Commands* tab shows *Done* within a few minutes, and the terminal's
   clock is right.
8. On the test employee's page, press **Enrol on devices**. **Check:** the command reaches
   *Done*, and the person appears in the terminal's user list under their name.
9. Change the test employee's status to *resigned*, then back to *active*. **Check:**
   *Remove a person*, then *Add or update a person*, both reach *Done*.

If a step fails, the device page shows why: *last rejected* with the address it came
from, or the failed command with the terminal's answer. Take a photo of the screen.

## Part B — Hikvision (about 60 minutes)

ETHR's server cannot reach a terminal inside the office network, so this part runs from
the laptop. The script records exactly what the terminal answers to the calls ETHR makes.

1. Find the terminal's **address on the office network**. It is on the terminal's screen
   or in the router's device list.
2. On the laptop, in the folder holding the script:

   ```bash
   python hikvision_capture.py --host <terminal address>
   ```

   Type the terminal's admin password when asked. This part **only reads**: device info,
   clock, the last 24 hours of access events and the user list.
3. If that worked, run it again with the write tests:

   ```bash
   python hikvision_capture.py --host <terminal address> --write-tests
   ```

   This adds a throwaway user `ETHRTEST01`, reads it back and removes it. It also sets
   the clock to the laptop's time (East Africa Time unless you pass `--utc-offset`).
   The terminal's real users and events are untouched.
4. **Push to the live site (optional).** It works only if the terminal can reach the
   internet.
   - In ETHR, register the terminal as **Hikvision**: the address doesn't matter for
     push.
   - Open its page and **Copy webhook URL**.
   - On the terminal's web page, under **Network → Advanced → HTTP Listening** (the
     name varies by firmware), add that URL as the event target.
   - Punch with the test employee. **Check:** the punch appears under Attendance.

Each run prints what the terminal asked for when signing in. **Digest** is the expected
answer, and ETHR currently signs in with **Basic**: if the terminal refuses Basic, every
call from ETHR fails, and this is the first thing the capture will settle.

## What to send back

- [ ] The `.zip` the script printed at the end (one per run). It holds no names, employee
      numbers, pictures or password: they are replaced or removed before anything is
      written.
- [ ] The results table below, filled in, with photos of any error screens.

| Step | Terminal | What happened | Pass / fail |
|---|---|---|---|
| A4 Online | ZKTeco | | |
| A6 Punch appears | ZKTeco | | |
| A7 Clock set | ZKTeco | | |
| A8 Enrol | ZKTeco | | |
| A9 Remove and re-add | ZKTeco | | |
| B2 Read-only capture | Hikvision | | |
| B3 Write tests | Hikvision | | |
| B4 Push punch (optional) | Hikvision | | |

Note the terminal **model and firmware version** for each one. The capture records them
for the Hikvision.

## What happens next

1. The captured answers become test fixtures in `api/tests/fixtures/devices/`.
2. The Hikvision adapter is confirmed or corrected against them. Basic versus Digest
   sign-in comes first.
3. Then the Bridge is built ([`BRIDGE-DESIGN.md`](../BRIDGE-DESIGN.md), *Build order*).
4. A ZKTeco failure in Part A is a defect to fix directly: that path runs on the live site
   today.
