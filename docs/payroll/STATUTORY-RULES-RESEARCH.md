# Statutory rules — research

Researched 2026-10-14 to make the payroll's statutory rules data rather than code (see
[`STATUTORY-SCHEDULES.md`](STATUTORY-SCHEDULES.md) and the `pension_rules` table). Every
access date below is **2026-10-14**.

**How to read the confidence column**

| Confidence | Meaning |
|---|---|
| **High** | Read in the text of the law itself (a copy of the Federal Negarit Gazette), and consistent with at least one independent source |
| **Medium** | Several independent secondary sources agree; the law's own text was not read for this point |
| **UNCONFIRMED** | Sources disagree, or only one non-official source says it, or nothing official was found. Not presented as fact; the code either does not depend on it or says so |

The law texts were read from copies of the Gazette hosted by lawethiopia.com and ethiodata.et
(the Gazette is not published on a government website we could reach). The Ministry of
Justice's law database (justice.gov.et) is official and was used to confirm status and date.
Nothing here is legal advice; the accountant sign-off ([`ACCOUNTANT-SIGN-OFF.md`](ACCOUNTANT-SIGN-OFF.md))
and owner action O3 remain the way to settle what is open.

## Findings

| # | Rule | Finding | Source (accessed 2026-10-14) | Confidence |
|---|---|---|---|---|
| P1 | Private-sector pension rates | **Employer 11 %, employee 7 %**, "based on the salary of the employee". Proclamation No. **1268/2022**, Art. 10 | Federal Negarit Gazette No. 16, 18 March 2022, p. 14066, Art. 10 — [copy at lawethiopia.com](https://www.lawethiopia.com/images/federal_proclamation/proclamations_by_number/Private-Organization-Employees-Pension-Proclamation-No.-1268-2022.pdf); same split in [PwC Ethiopia — Other taxes](https://taxsummaries.pwc.com/ethiopia/individual/other-taxes) (last reviewed 15 July 2026) and [ISSA country profile 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf) | **High** |
| P2 | Private-sector effective date | In force on publication, **18 March 2022** (Art. 63); repeals Proclamations 715/2011 and 908/2015 (Art. 62) | Same Gazette, Arts. 62–63 | **High** |
| P3 | Public-sector (civil service) rates | **Public office 11 %, public servant 7 %** to the Civil Service Pension Fund. Proclamation No. **1267/2022**, Art. 10 | Federal Negarit Gazette No. 15, 18 March 2022, Art. 10 — [copy at ethiodata.et](https://ethiodata.et/wp-content/uploads/2023/01/Ethiopia-Public-Servants-Pension-Proclamation-No.-1267_2022.pdf); [ISSA 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf) | **High** |
| P4 | Military and police rates | **Public office 33 %, servant 7 %** to the Military and Police Service Pension Fund. Proclamation 1267/2022, Art. 11 | Same Gazette, Art. 11; [ISSA 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf) ("33% (military and police personnel)") | **High** |
| P5 | Public-sector effective date | In force on publication, **18 March 2022** (Art. 64). Ministry of Justice lists it **in force** | Same Gazette, Art. 64; [Ministry of Justice — Public Servants' Pension Proclamation](https://justice.gov.et/en/law/public-servants-pension-proclamation/) | **High** |
| P6 | Ceiling or floor on contributions | **None stated.** Neither proclamation's text contains a maximum or minimum contributory salary; ISSA lists none; PwC and commercial guides call the contributions uncapped | Texts of 1268/2022 and 1267/2022 (searched for "ceiling", "maximum", "minimum"); [ISSA 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf) | **Medium** — absence in the text is established; a directive setting one was not searched exhaustively |
| P7 | What "salary" is (the base) | 1268/2022 Art. 2(7): "monthly salary received … for services rendered during **regular working hours** without the deduction of any amounts in respect of income tax and any other matter". So overtime is out. Whether **fixed allowances** are in is **not settled by the text**: ISSA describes the base as "gross monthly salary (for work performed during regular hours)"; PwC and most payroll guides say **basic salary** | 1268/2022 Art. 2(7); [ISSA 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf); [PwC](https://taxsummaries.pwc.com/ethiopia/individual/other-taxes) | **UNCONFIRMED** (basic vs. basic + fixed allowances). ETHR keeps **basic** (its behaviour to date, and common practice); a rule can be switched to `regular_salary` without a deploy |
| P8 | Who is covered — nationality | Both proclamations apply to employees **who are Ethiopian nationals** (1268 Art. 3(1); 1267 Art. 3(1)), without prejudice to Proclamation 270/2002 on **foreign nationals of Ethiopian origin** and treaties. PwC: optional for foreign citizens of Ethiopian origin, not available to other foreigners | 1268/2022 Art. 3(1); 1267/2022 Art. 3(1); [PwC](https://taxsummaries.pwc.com/ethiopia/individual/other-taxes) | **High** for the rule; how an employer opts a person of Ethiopian origin in is **UNCONFIRMED** |
| P9 | Who is excluded — private sector | Domestic workers; employees of governmental international organisations and foreign diplomatic missions; sole owners and their managers/employees (1268 Art. 3(4)) | 1268/2022 Art. 3(4) | **High** |
| P10 | 45-day rule | 1268 defines a private-organisation employee as a salaried person employed "for not less than forty five days" (Art. 2). **How that applies** — no contribution in the first 45 days, or coverage from day one once 45 days are reached — is not stated in the text read | 1268/2022 Art. 2; [2015 reporting on the 45-day rule](https://ethiopianbusinessreview.net/pension-contributions/) | **UNCONFIRMED** (application). The data model has a `min_service_days` field; it is **seeded empty**, so ETHR does not apply a guess |
| P11 | Contract staff, probation | Neither text excludes fixed-term, piece-work or probationary employees; 1267's scope covers servants "employed permanently or for definite period or piece of work" | 1267/2022 Art. 3 (via [ethiodata summary](https://ethiodata.et/public-servants-pension-proclamation-no-1267-2022/)); 1268/2022 Art. 2 | **Medium** — no exclusion found; seasonal workers are excluded per [ISSA 2024](https://www.issa.int/sites/default/files/documents/2024-07/Ethiopia.pdf) |
| P12 | Payment deadline | Private: deduct monthly and pay **within 30 days** from the last day of the month the salary is paid; late payment bears bank interest **plus 5 % a month** (1268 Art. 12) | 1268/2022 Art. 12 | **High** |
| P13 | Who collects private-sector contributions | The **Federal Ministry of Revenue**, regional revenue authorities, or a legally established revenue authority; details by directive (1268 Art. 11) | 1268/2022 Art. 11 | **High** |
| T1 | Employment income tax brackets (monthly) | 0–2,000: 0 %; 2,001–4,000: 15 %; 4,001–7,000: 20 %; 7,001–10,000: 25 %; 10,001–14,000: 30 %; over 14,000: 35 %. Income Tax (Amendment) Proclamation No. **1395/2025** | [EY tax alert, 17 Dec 2025](https://www.ey.com/en_gl/technical/tax-alerts/ethiopia-issues-a-new-income-tax-proclamation); [PwC — Taxes on personal income](https://taxsummaries.pwc.com/ethiopia/individual/taxes-on-personal-income) (last reviewed 15 July 2026); [KPMG, 19 Sep 2025](https://kpmg.com/us/en/taxnewsflash/news/2025/09/ethiopia-tax-amendments-passed.html) | **Medium** — the Gazette text of 1395/2025 was not read; every source agrees on the bands |
| T2 | `tax_brackets` seed vs. T1 | **Matches.** `TaxCalculator::CURRENT_BRACKETS` has the same six bands and rates; its quick-method deductions (300, 500, 850, 1,350, 2,050 ETB) were re-derived by hand from the bands and agree | Code: `api/app/Services/Payroll/TaxCalculator.php` | **High** (arithmetic) |
| T3 | Effective date of 1395/2025 for employment income | **Sources disagree.** EY and KPMG: **8 July 2025** (withholding on domestic payments 7 August 2025). Afriwise and HST: **7 July 2025**. PaySpace: MoR instructed employers to apply it from **1 July 2025**. PwC (reviewed July 2026) said the gazetted text was not yet published. Parliament passed it on 17 July 2025 | [EY](https://www.ey.com/en_gl/technical/tax-alerts/ethiopia-issues-a-new-income-tax-proclamation); [KPMG](https://kpmg.com/us/en/taxnewsflash/news/2025/09/ethiopia-tax-amendments-passed.html); [Afriwise](https://www.afriwise.com/blog/update-on-ethiopias-new-income-tax-amendment-proclamation); [HST](https://www.hst-et.com/insights/insight-detail/income-tax-update); [PaySpace](https://www.payspace.com/blog/ethiopia-income-tax-amendments-key-payroll-changes-2025/); [PwC significant developments](https://taxsummaries.pwc.com/ethiopia/individual/significant-developments) | **UNCONFIRMED.** The code uses **7 July 2025** and is **not changed**. A monthly period starting 1 July 2025 would be taxed at the old ladder under the code; under the PaySpace reading it should use the new one. Owner/accountant to confirm |
| T4 | Superseded ladder | 0–600: 0 %; then 10/15/20/25/30/35 % to 10,900+, Proclamation **979/2016** — as in `SUPERSEDED_BRACKETS` | [Search summary of older guides](https://www.countrytaxcalc.com/tax-guides/ethiopia-paye-tax-guide-2026/) | **Medium** |
| F1 | MoR monthly employment-tax declaration | A form exists (copies circulate as "1103 Employment Income Tax Declaration", "Schedule A"), with employer TIN, period, and per employee TIN, name, salary, benefits, taxable income, tax withheld, totals. **No current official form or column list was found online**; erca.gov.et's declaration-forms page refused connections | [Scribd copy of a 1103 form](https://www.scribd.com/document/606109950/1103-Employment-Income-Tax-Dec-Updated) (user upload); [erca.gov.et declaration forms](http://www.erca.gov.et/index.php/declaration-forms) (unreachable) | **UNCONFIRMED** |
| F2 | MoR e-filing | An e-tax portal (etax.mor.gov.et) is referred to by tutorials; **not verified** from an official MoR page | Search results only | **UNCONFIRMED** |
| F3 | POESSA / PSSSA declaration formats and e-services | **Nothing official found** on the monthly employer schedule's format or an online submission service. PSSSA has a site (psssa.gov.et) publishing implementation documents for 1267/2022 | [PSSSA implementation document](https://www.psssa.gov.et/sites/default/files/resource/959_Public_Servants%E2%80%99_Pension_Proclamation_No_12672022_Implementation.pdf) (found, not read) | **UNCONFIRMED** |

## What this changed in ETHR (2026-10-14)

- Pension rates live in **`pension_rules`** (dated, per scheme, with the legal reference), seeded
  from P1–P4: `private` 7/11 and `public` 7/11 from 2022-03-18 (1268/2022, 1267/2022), and
  `public_military` 7/33 from 2022-03-18 (1267/2022 Art. 11). No ceiling (P6). Base `basic` (P7,
  open). Non-nationals exempt (P8). `min_service_days` empty (P10, open).
- Tax brackets gain a **legal reference** column; the platform ladders are labelled 979/2016 and
  1395/2025. Their values and dates are **unchanged** (T2, T3).
- Schedule layouts, agency names and the basis line move to dated configuration, and every
  schedule states the rules it was built on.

## Open questions for the accountant / O3

1. **T3** — from which date did MoR require the 1395/2025 ladder for employment income: 1, 7 or 8 July 2025?
2. **P7** — is the pension base basic salary only, or regular-hours salary including fixed allowances?
3. **P10** — does the 45-day rule mean no contribution in the first 45 days?
4. **P8** — how is a foreign national of Ethiopian origin opted in, and with which document?
5. **F1–F3** — the current official monthly declaration layouts for MoR, POESSA and PSSSA.
