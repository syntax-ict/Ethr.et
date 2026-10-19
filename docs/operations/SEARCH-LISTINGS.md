# Search listings: getting ETHR found outside its own site

Written 2026-10-19, after the on-site work in PR #314 went live (v2026.10.19.1)
and ethr.et was verified in Google Search Console and imported into Bing
Webmaster Tools.

**Why this exists.** The site now tells search engines the right things, but a
new domain with no links pointing at it ranks slowly, and a search for the bare
name "ETHR" is filled by an exchange-traded fund with the same ticker. What moves
that is other trusted sites naming ETHR, with the same name, address and phone
every time. Each step below is one only the owner can take: it creates an
account in the company's name or states a fact only the company can confirm.

Do them in this order. Each is worth more than the one after it.

---

## 0. Fill in the platform's own contact details first (10 minutes)

Measured 2026-10-19: `GET /api/v1/site-content` returns `contact_email`,
`contact_phone`, `office_address` and all three social links as `null`. The
contact page and footer show none of them, and every listing below asks for
them. A directory entry that disagrees with the site is worth less than none.

Admin console → **Site content**, set:

| Field | Use |
|---|---|
| Contact email | One address you will answer, e.g. `info@ethr.et` or `sales@ethr.et` |
| Contact phone | `+251 …` in international format |
| Office address | Street, sub-city, Addis Ababa, Ethiopia (and the Amharic version) |
| LinkedIn, X, Facebook | Only profiles that exist; the footer hides empty ones |

Then copy **exactly** those strings into every listing below. Same spelling,
same phone format, same address order.

## 1. Google Business Profile (highest value, free)

<https://business.google.com/create>

- **Name:** ETHR
- **Category:** Software company (secondary: Business management consultant is
  *not* accurate; leave it at one category)
- **Location:** the office address from step 0. Google verifies it by video or
  postcard. If there is no office customers can visit, choose "I deliver goods
  and services to my customers" and hide the address, with service area
  Ethiopia.
- **Website:** `https://www.ethr.et/en`
- **Description:** the short description below.
- After verification, add the logo and two or three screenshots of the product.

## 2. Capterra, GetApp and Software Advice (one form, three listings)

<https://digitalmarkets.gartner.com/> (Gartner Digital Markets vendor portal).
Free basic listing. Categories: **HR Software**, **Payroll Software**,
**Time and Attendance Software**, **Leave Management Software**.

## 3. G2

<https://sell.g2.com/> → "Get listed". Free. Same categories. A listing with a
few genuine reviews from customer organisations ranks far above one with none;
ask the organisations already on ETHR, never write one yourself.

## 4. Ethiopian business directories

Ethiopian Yellow Pages (`ethyp.com`), 2merkato (`2merkato.com`), and the
Ethiopian ICT/startup directories you already belong to. Same name, address,
phone and website as step 0.

## 5. Outreach to "top HR software in Ethiopia" articles

The 2026-10-19 search showed list articles naming SeamlessHR, peopleHum,
WebHR, Lenvica and Demoz. Write to each author once, with the short
description, a link to `https://www.ethr.et/en/payroll-software`, and an offer
of a demo account. One accepted mention is worth more than ten directory
entries.

## 6. Optional: the Ethio Telecom DNS ticket

Plesk refuses DNS editing for this account (the zone is served by
`ns2.telecom.net.et`), so Search Console's **Domain** property for `ethr.et`
is unverified. The `https://www.ethr.et/` property is verified and enough for
the marketing site; the Domain property would also cover organisation
subdomains. Ticket text, ready to send from the client area:

> Subject: Add a DNS TXT record to ethr.et
>
> Please add the following TXT record to the DNS zone of ethr.et, at the root
> of the domain (host `@`), leaving the existing SPF record in place:
>
> `google-site-verification=X-dBdH4AEsnuaiTl_uYsCC29TyLaveViKjISFBbz57M`
>
> It is for Google Search Console domain verification. Please reply when it has
> been published. Thank you.

When they confirm, check with `nslookup -type=TXT ethr.et 8.8.8.8`, then open
Search Console → `ethr.et` → Verify.

---

## Copy to paste

Use these exact texts everywhere, so every listing says the same thing. Every
claim in them is checked against the code (see the 2026-10-17 capability
audit): no device brands, no biometric hardware, no performance or training
modules, no invented customer numbers.

**Name:** ETHR

**Website:** https://www.ethr.et/en

**Tagline (under 60 characters):**
HR, payroll and attendance software for Ethiopia

**Short description (under 160 characters):**
HR, payroll, attendance and leave software for Ethiopian organizations, with
Ethiopian income tax, pension and the Ethiopian calendar built in.

**Long description:**
ETHR is HR, payroll, attendance and leave software built for Ethiopian
organizations. Payroll applies employment income tax under Proclamation
1395/2025 and 7% employee and 11% employer pension, takes loan and
cost-sharing deductions, and puts each payslip on the employee's phone once the
run is approved, with the calculation behind every figure kept. Staff clock in
from a phone, a QR code, a shared kiosk or the web, with a location check and
offline sync for sites with unreliable internet. Leave balances accrue monthly
and carry forward under the organization's own rules, and public holidays are
not counted as leave days. Everything works in the 13-month Ethiopian calendar,
Pagume included, and in Amharic or English. Each organization gets its own
isolated workspace, and data is stored in Ethiopia. New organizations start
with a six-month free trial.

**Amharic short description:**
ለኢትዮጵያ ድርጅቶች የሰው ሃብት፣ የደመወዝ፣ የሰራተኛ መገኘትና የፈቃድ ሶፍትዌር፤ የኢትዮጵያ የገቢ ግብር፣ ጡረታና የቀን አቆጣጠር ተካተውበታል።

**Features list:** Payroll with Ethiopian income tax and pension · Payslips on
mobile · Bank transfer file export · Mobile, QR, kiosk and web attendance ·
Location check · Offline sync · Shifts and overtime rules · Leave requests,
approvals, accrual and carry-forward · Ethiopian calendar with Pagume ·
Amharic and English · Employee records with encrypted tax IDs and bank details ·
Audit trail · Role-based access and two-factor sign-in

**Deployment:** Cloud (web), installable on Android and iOS as a web app

**Languages:** Amharic, English

**Markets:** Ethiopia

**Pricing model:** Subscription; six-month free trial. Do not publish a price in
a listing that the pricing page does not show.

**Links worth giving where a listing allows more than one:**
- https://www.ethr.et/en/payroll-software
- https://www.ethr.et/en/attendance-system
- https://www.ethr.et/en/leave-management
- https://www.ethr.et/en/ethiopian-calendar-hr

---

## Checking progress

- **Google:** Search Console → Indexing → Pages, and Performance → Queries,
  after a week.
- **Bing:** Bing Webmaster Tools → Sitemaps and URL Inspection.
- Searches to try after two to four weeks: `ETHR payroll Ethiopia`,
  `HR software Ethiopia`, `Ethiopian payroll software`,
  `የደመወዝ ሶፍትዌር`.
