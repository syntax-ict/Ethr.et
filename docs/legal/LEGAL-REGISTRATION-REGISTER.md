# `LEGALLY READY TO TRADE = NO`

**As of 2026-11-25.** The one register that decides whether ETHR is registered, protected and contracted to sell to Ethiopian organisations. It sits beside [`../deployment/CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md) and works like it, but decides something else: that register says whether the site can go live, this one whether the business may take money and personal data. Passing one does not pass the other.

> **DRAFT — NOT LEGAL ADVICE.** The legal points below come from reading the Proclamations, mostly in their English text, for this product. Counsel has not confirmed them. Each carries a confidence class:
> - **1**: read in the Proclamation's own text.
> - **2**: from a secondary source, or a text not fully read.
> - **3**: needs counsel.
>
> Fees, forms and processing times change, and none are quoted. Ask the authority on the day.

## The owner's starting position (2026-11-25)

| Fact | Value | What follows |
|---|---|---|
| Who owns ETHR | Syntax Technology, the owner's business; ETHR is its product and brand. No separate company | Agreed. A second company would add filings and buy nothing yet |
| Legal form of Syntax Technology | **Sole proprietorship** | The owner is **personally** liable for everything ETHR does, including a breach of payroll, identity or biometric data. See L1 |
| Who wrote the code | The owner, **plus contractors or staff** | Each contributor's work must be assigned in writing. Until it is, that part is not demonstrably the business's. See L4 |
| First customers | Government bodies and private employers, equally | Two lawful-basis routes for biometrics, and two contract instruments. See L9 and L13 |

## States

| State | Means |
|---|---|
| **PASS** | Done, with the document, certificate or receipt held. The evidence column says where (outside the repository: certificates are not committed) |
| **OWNER ACTION** | Only the owner can do it, at an authority or with a signature |
| **OWNER DECISION** | Needs a choice, not a filing |
| **COUNSEL REVIEW** | A draft exists in this folder; a lawyer must read it before it is used |
| **BLOCKED** | Waiting on another row, named |
| **FAIL** | Checked, and the answer was no |

**No row moves to PASS on the strength of a draft, a merged pull request or a green CI run.** PASS needs the signed or issued document.

## The register, in the order to do it

The order is a dependency chain, not a ranking of importance. Why it differs from the first proposal is set out under [*Why this order*](#why-this-order).

### Stage 1: the entity

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L1 | **Decide whether to convert Syntax Technology to a PLC before signing the first paid customer's DPA** | OWNER DECISION | — | A dated decision. **Recommendation: convert.** A sole proprietor answers for a breach with personal assets. 1321/2024 provides administrative fines, reported at up to 4% of turnover (Art. 60, class 2), and imprisonment (Art. 64, class 2). A private limited company confines exposure to the company, and government buyers generally expect one. If the owner decides not to, record why here | Ministry of Trade and Regional Integration, or the regional trade bureau | Owner |
| L2 | TIN for the entity (and a new one if L1 converts) | OWNER ACTION | — | TIN certificate. **A TIN comes before the trade licence, not after**: it is part of registering the business | Ministry of Revenues | Owner |
| L3 | Trade licence covering the activities ETHR actually performs | OWNER ACTION | — | Licence whose listed activities cover: software development; software as a service / hosted application services; IT consultancy and systems integration (Syntax Technology installs attendance terminals on site); and, if offered, data processing services. Check the activity codes with the trade bureau rather than this list | Trade bureau | Owner |
| L4 | Domain `ethr.et` registered to the entity, not to a person | OWNER ACTION | — | Registrant record naming Syntax Technology (and, after L1, the PLC). A domain held by an individual is the most common way a business loses its own name | Ethio Telecom (`.et` registry) | Owner |
| L5 | Bank account in the entity's name, the one printed on invoices | OWNER ACTION | — | Account confirmation. ETHR is paid by manual bank transfer only (`BillingService`; the account details come from `platform_settings`), so the account on every invoice must be the entity's | Bank | Owner |

### Stage 2: owning what is sold

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L6 | Founder's assignment of ETHR's code, design and documentation to the entity | BLOCKED on L1 | Template: [`IP-OWNERSHIP-AND-ASSIGNMENT.md`](IP-OWNERSHIP-AND-ASSIGNMENT.md) §A | Signed deed. **While Syntax Technology is a sole proprietorship, the business and the owner are the same person**, so this deed matters at conversion: the owner assigns to the PLC | — | Owner |
| L7 | Assignment from **each** contractor or member of staff who contributed code, design or content | OWNER ACTION | Template: [`IP-OWNERSHIP-AND-ASSIGNMENT.md`](IP-OWNERSHIP-AND-ASSIGNMENT.md) §B; contributor checklist §C | One signed assignment per contributor, covering past work and future work, held outside the repository. The checklist in §C lists them by role. **A contributor who will not sign is a finding**: record what they wrote, so it can be rewritten | — | Owner |
| L8 | Trade-secret measures | OWNER ACTION | Private repository | Signed NDAs with everyone who has seen the code; access removed when someone leaves ([`../operations/CUSTODY.md`](../operations/CUSTODY.md) already reviews credential holders quarterly; extend it to repository access) | — | Owner |
| L9 | Third-party licence obligations | COUNSEL REVIEW | [`THIRD-PARTY-LICENSES.md`](THIRD-PARTY-LICENSES.md) | Counsel reads the three unusual licences (LGPL `dompdf`, Hippocratic-2.1 `react-leaflet`, FSL `@sentry/cli`) and confirms the notice is sufficient | — | Owner, counsel |

### Stage 3: the brand

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L10 | Trademark search for **ETHR** | OWNER ACTION | Brief: [`TRADEMARK-FILING-BRIEF.md`](TRADEMARK-FILING-BRIEF.md) | Search result from EIPA. Do this **before** spending more on the brand: "ETHR" may be read as short for "Ethiopia HR", which is descriptive, and an objection is a real risk | Ethiopian Intellectual Property Authority (EIPA) | Owner or agent |
| L11 | Trademark application: ETHR word mark, and the logo, Class 42 | BLOCKED on L10 | Brief, as above | Filing receipt, then registration certificate. File in the name of the entity that will own the brand long-term: if L1 converts, file after conversion or plan an assignment | EIPA | Owner or agent |

### Stage 4: personal data

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L12 | Record of processing | COUNSEL REVIEW | [`RECORD-OF-PROCESSING.md`](RECORD-OF-PROCESSING.md) | Counsel confirms. Art. 46 requires a record (class 1); it is also what registration asks you to describe | — | Owner, counsel |
| L13 | Data protection impact assessment for attendance monitoring | COUNSEL REVIEW | [`DPIA-ATTENDANCE-MONITORING.md`](DPIA-ATTENDANCE-MONITORING.md) | Counsel confirms; the owner signs off the residual risk. Art. 47 requires one before high-risk processing, including large-scale sensitive data (class 2) | — | Owner, counsel |
| L14 | Data Processing Agreement template, both versions | COUNSEL REVIEW | [`DATA-PROCESSING-AGREEMENT.md`](DATA-PROCESSING-AGREEMENT.md) | Counsel approves. **Needed before the first customer processes real employee data, not at the end**: Art. 16(3) requires the processor to act under a written contract (class 1) | — | Owner, counsel |
| L15 | Sub-processor list | COUNSEL REVIEW | [`SUB-PROCESSORS.md`](SUB-PROCESSORS.md) | Counsel approves. The SMTP provider and the backup storage provider are `[confirm]` | — | Owner |
| L16 | **Keep backups in Ethiopia** | PASS (code), 2026-11-25. OWNER ACTION remains | Decided 2026-11-25: production refuses uploads to Google Drive and keeps it restorable. Built in `App\Support\BackupResidency`; pinned by `api/tests/Feature/BackupResidencyTest.php`; [`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md) → *Storage in Ethiopia* | Art. 22(1) requires personal data collected in Ethiopia to be stored on a server or data centre in Ethiopia (class 1). In production a backup now goes only to storage someone has **stated** to be in Ethiopia, and never to Drive. **What remains is the statement itself:** the owner confirms, with the provider's contract, that the configured storage is in Ethiopia before ticking the box. A false tick defeats the rule | ECA | Owner |
| L17 | Registration with the Ethiopian Communications Authority, **as processor** (customers' employee data) and **as controller** (ETHR's own customers, billing and enquiries) | BLOCKED on L1, L12 | — | Registration certificate. Registration is required before processing (Art. 33, class 2); certificates last two years and must be renewed (Art. 35, class 2). The registration directive's requirements and fees were not found online: ask the Authority. **Register the entity that will hold the contracts**, so decide L1 first | Ethiopian Communications Authority (ECA), the Authority under Art. 2(36) (class 1) | Owner |
| L18 | Designate a Data Protection Officer and give the Authority the contact | OWNER DECISION | — | A named role and a contact published in the Privacy Policy. Art. 40 makes a DPO mandatory for government bodies and for large-scale processing of sensitive data ([`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md) class 1). ETHR processes biometric selfies and identity numbers for many organisations, so assume it applies. The owner can hold the role at first; record the conflict, since the DPO reviews the owner's own decisions | ECA | Owner |
| L19 | Renewal of L17 | BLOCKED on L17 | — | A calendar entry two months before the certificate expires | ECA | Owner |
| L20a | Erasure at the end of a contract | PASS (code), 2026-11-25 | `App\Services\Tenancy\OrganisationErasure`, `ethr:erase-organisation` and the `erase-organisation` maintenance task; pinned by `api/tests/Feature/Tenancy/OrganisationErasureTest.php`; [`../operations/TENANT-DELETION.md`](../operations/TENANT-DELETION.md) | DPA §10 can now be kept as written (Art. 50, class 2). Run `./scripts/gates.sh mysql` before the first real erasure; the suite proves it on SQLite | — | Owner |
| L20 | Breach response adopted | OWNER ACTION | [`../operations/DATA-BREACH-RESPONSE.md`](../operations/DATA-BREACH-RESPONSE.md), drafted 2026-10-17 | The owner adopts it in writing and names who acts | — | Owner |

### Stage 5: customer contracts and public text

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L21 | Privacy Policy (public site) | COUNSEL REVIEW | `src/src/lib/legal/documents.ts` (`privacyEn`), banner on | Counsel approves; `OWNER_SUPPLIED_FACTS` filled in; `LEGAL_REVIEW_REQUIRED` set to `false` | — | Owner, counsel |
| L22 | Terms of Service (self-serve trial) | COUNSEL REVIEW | `documents.ts` (`termsEn`) | As L21. The liability section is deliberately unfinished and must be written by counsel | — | Owner, counsel |
| L23 | Master Subscription Agreement and order form (government and business buyers) | COUNSEL REVIEW | [`MASTER-SUBSCRIPTION-AGREEMENT.md`](MASTER-SUBSCRIPTION-AGREEMENT.md) | Counsel approves. Government buyers contract on their own procurement forms, so the MSA is written to sit inside one as an annex as well as to stand alone | — | Owner, counsel |
| L24 | Service Level Agreement | COUNSEL REVIEW | [`SERVICE-LEVEL-AGREEMENT.md`](SERVICE-LEVEL-AGREEMENT.md) | Counsel approves. Commits to support and restore times, not uptime, until `CUTOVER READY = YES` and the restore rehearsal on the real host has passed | — | Owner, counsel |
| L25 | Biometric data policy and consent form | COUNSEL REVIEW | [`BIOMETRIC-DATA-POLICY.md`](BIOMETRIC-DATA-POLICY.md) | Counsel approves; Amharic translation by a human (L27) | — | Owner, counsel |
| L26 | Acceptance of the terms recorded at sign-up | PASS, 2026-11-25 | Sign-up requires `accept_terms` (`RegisterTenantRequest`). `tenants.terms_accepted_version`, `_at` and `_by` record what was accepted, and a `tenant.terms_accepted` audit row records the IP and browser. Pinned by `api/tests/Feature/Auth/RegisterTest.php`; the version is shared with the pages by `src/src/test/legal-version.test.ts` | Done for new sign-ups. **Open:** organisations that registered earlier show *not recorded*, and a new owner after an ownership transfer accepts nothing. Asking either to accept is an owner decision. Bump `LegalDocuments::VERSION` and `LEGAL_VERSION` together when the documents change | — | Owner |
| L27 | Amharic versions of L21–L25 | OWNER ACTION | The page frame is translated; the text is English only, by design (`documents.ts` header) | Human translation, checked by counsel. Government buyers work in Amharic, and an employee's notice they cannot read informs no one | — | Owner, translator |

### Stage 6: copyright, tax, procurement

| # | Item | State | Evidence held | What closes it | Authority | Who |
|---|---|---|---|---|---|---|
| L28 | Copyright deposit of the ETHR computer program | BLOCKED on L6, L7 | — | EIPA certificate. Copyright exists without registration; the certificate is evidence of ownership. **Register only what the entity demonstrably owns**, so after the assignments. Whether the certificate expires (a five-year figure was suggested) is unverified (class 3). See [`IP-OWNERSHIP-AND-ASSIGNMENT.md`](IP-OWNERSHIP-AND-ASSIGNMENT.md) §D on AI-assisted code | EIPA | Owner |
| L29 | VAT or turnover-tax registration, whichever applies at ETHR's turnover | OWNER ACTION | — | Certificate. The threshold and which regime applies to a software service were not checked (class 3) | Ministry of Revenues | Owner, accountant |
| L30 | Invoicing that meets the tax rules | OWNER ACTION | `BillingService::generateMonthlyInvoice` issues monthly invoices in ETB | Confirm with the accountant whether ETHR's invoices must come from an approved system or sales register machine, and what they must state (TIN, VAT). Until confirmed, treat the in-app invoice as a statement, not a tax invoice | Ministry of Revenues | Owner, accountant |
| L31 | Withholding on payments from government buyers | OWNER ACTION | — | Confirm the withholding rate government bodies deduct from service payments, and price for it. The rate was not checked (class 3) | Ministry of Revenues | Owner, accountant |
| L32 | Employer obligations for Syntax Technology's own staff (pension, employment income tax) | OWNER ACTION | — | Registration with the private pension agency and monthly filings, if Syntax Technology employs anyone. ETHR itself computes these for customers; that is not Syntax Technology's own compliance | POESSA; Ministry of Revenues | Owner |
| L33 | Supplier registration for public procurement | BLOCKED on L2, L3 | — | Registration on the government e-procurement system. Expect to be asked for the trade licence, TIN, VAT certificate and tax clearance | Public Procurement and Property Authority | Owner |
| L34 | INSA security assessment | OWNER DECISION | INSA publishes web-application testing requirements | **Unconfirmed whether a government buyer must have one before buying.** Ask the first government buyer in writing. If yes, prepare for INSA's web-application test; [`INFORMATION-SECURITY-POLICY.md`](INFORMATION-SECURITY-POLICY.md) is the starting pack | Information Network Security Administration | Owner |

### Not pursued, on purpose

| Item | Why not |
|---|---|
| A separate company for ETHR | Adds filings and costs; a brand of the owning entity is enough until ETHR has its own investors or a sale is in view |
| A patent | HR software, its features and its interface are not a patentable invention under the usual tests. Copyright, trademark, trade secrets and contracts protect it |
| Trademark "ETHR.et" | A domain ending adds no distinctiveness. File the word ETHR and the logo; the domain is protected by holding it (L4) |
| Class 9 trademark | Class 9 covers downloadable software. ETHR is a hosted service and a PWA. Add Class 9 if a downloadable app is distributed |

## Why this order

The first proposal put tax fifth, the legal documents last, and the trademark before the IP assignments. Four corrections:

1. **A TIN is a precondition of the trade licence**, not a later compliance step.
2. **The documents cannot come last.** Registration with the Authority asks what is processed, why and how it is protected, which is the record of processing. A customer cannot lawfully put employee data in ETHR without a written processor contract (Art. 16(3), class 1). So the record, the DPIA and the DPA come before registration and before the first real customer.
3. **Ownership comes before its registration.** A copyright certificate in the business's name over code a contractor still owns is evidence of the wrong thing. Assign first, register second.
4. **The entity's form comes before everything signed in its name.** Converting after signing customers means re-papering every contract, the Authority registration and the trademark.

Three claims in the first proposal were also wrong, and correcting them changes what is filed:

- **ETHR does not hold fingerprint or face templates.** The terminals keep them. ETHR's ADMS code counts the template lines a device sends and stores only the counts (`api/app/Services/Device/AdmsProtocol.php`; `device_enrollment_snapshots` holds `fingerprint_count` and `face_registered`). Declaring fingerprint processing to the Authority would over-declare. **ETHR does hold attendance selfies**, which are biometric data (Art. 2(6), class 1), and GPS, IP and device details on punches. The record of processing says exactly that.
- **One lawful basis does not fit every customer.** Sensitive data, biometrics included, may be processed with the person's prior written consent specific to the purpose (Art. 9(2)(a), class 1), or by a public body for a lawful non-commercial objective (Art. 9(2)(c), class 1). Art. 9 has no ground for "the employer needs it". A government office can rely on 9(2)(c); a private employer has only consent. Consent must be free (Art. 8(2), class 1), and an employee's consent is free only if refusing costs them nothing, so **private customers must offer a non-biometric way to clock in**. ETHR already supports badge and PIN punching, and selfie consent can be withdrawn (`PhotoConsent`).
- **An uptime SLA cannot be offered yet.** The current Terms already say there is no uptime guarantee, cutover is not complete, and the restore rehearsal on the real host has not been done. Promising 99.9% would be a promise the evidence does not support.
