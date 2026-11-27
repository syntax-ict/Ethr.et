# Master Subscription Agreement and order form (template)

> **DRAFT — NOT LEGAL ADVICE.** Drafted 2026-11-25. Counsel has not confirmed it, and its liability clause is deliberately left to counsel. Register row L23. Do not sign as written.

**What this is for.** The click-through Terms of Service (`/terms`) suit an organisation that signs up for the trial by itself. Government bodies and larger businesses contract differently: a government office buys under its procurement rules, on its own forms, in Amharic, and a click-through page fits none of that. This agreement is written to work two ways:

- **On its own**, signed by both parties, for business buyers.
- **As the technical and data-protection annex to the buyer's own procurement contract**, for public bodies. The buyer's contract governs price, payment, warranty and dispute resolution, and clause 1.3 makes that explicit.

Commercial terms below repeat what the product does today (`BillingService`, the Terms of Service), so the contract and the system agree.

---

**Between** `[Customer: name, legal form, address, TIN]` (**the Customer**) and Syntax Technology `[confirm: legal name, legal form, registration number, TIN, address]`, trading as ETHR (**ETHR**).

## 1. The agreement

1.1 This agreement, its order forms, the Data Processing Agreement ([`DATA-PROCESSING-AGREEMENT.md`](DATA-PROCESSING-AGREEMENT.md)) and the Service Level Agreement ([`SERVICE-LEVEL-AGREEMENT.md`](SERVICE-LEVEL-AGREEMENT.md)) are the whole agreement for the service. They replace the Terms of Service for this Customer.

1.2 If they conflict: the DPA on personal data, then the order form, then this agreement, then the SLA.

1.3 **Where the Customer is a public body contracting under its own procurement contract**, that contract governs price, payment, warranties, penalties and disputes, and this agreement and its annexes govern the service, its security and personal data.

## 2. The service

2.1 ETHR provides the hosted ETHR service, in the modules and plan named in the order form, to the Customer's organisation at its ETHR address (`ethr.et/{address}`, or the Customer's own domain once verified).

2.2 ETHR may improve the service. It will not remove a function the Customer is using in its plan during a paid term without `[confirm: 90]` days' notice.

2.3 **Implementation services** (set-up, data migration, training) and **device services** (supply, installation and configuration of attendance terminals at the Customer's premises) are provided only if listed in the order form, on the terms stated there.

## 3. The Customer's obligations

3.1 The Customer is responsible for the lawful basis for the personal data it enters and for informing its employees (DPA §4), for the accuracy of what it enters, and for whom it grants access.

3.2 The Customer will not attempt to reach another organisation's data, disrupt the service, or use it unlawfully.

3.3 **Payroll results depend on the Customer's configuration and data.** ETHR computes income tax and pension from the rules it publishes as current. The Customer reviews each payroll before approving it and remains responsible for its statutory filings. ETHR files nothing with any authority.

## 4. Fees and payment

4.1 Fees are as in the order form, in Ethiopian Birr. `[confirm: VAT treatment, register row L29.]`

4.2 Subscription fees are invoiced monthly in advance and due within **fifteen days** of the invoice. An invoice unpaid **seven days** after it is due is overdue, and the Customer's administrators are notified. If an invoice remains unpaid **sixty days** after it was issued, ETHR may suspend access. Suspension restricts access; it does not delete data (as `BillingService` and `HandleOverdueInvoicesJob` do).

4.3 Payment is by bank transfer to the account printed on the invoice, which is in ETHR's name (register row L5).

4.4 `[confirm: annual prepayment, discount, and withholding on payments by public bodies, register row L31.]`

## 5. Data and intellectual property

5.1 **The Customer's data belongs to the Customer.** ETHR uses it only to provide the service, as the DPA sets out, and does not sell it or use it to build other products.

5.2 ETHR and its licensors own the service, its software, design and documentation. This agreement gives the Customer a right to use the service for the term; it transfers no ownership. The Customer will not copy, reverse-engineer or resell it.

5.3 Feedback the Customer gives may be used by ETHR freely.

## 6. Confidentiality

Each party keeps the other's non-public information confidential, uses it only for this agreement, and returns or destroys it at the end, except as law requires.

## 7. Warranties

7.1 ETHR will provide the service with reasonable skill and care, in line with the SLA, and maintain the security measures in the DPA Annex 3.

7.2 `[counsel: any further warranty, and the disclaimer of implied warranties permitted under Ethiopian law.]`

## 8. Liability

`[counsel to draft. Points to settle: a cap, typically fees paid in the preceding twelve months; exclusion of indirect loss; what is never capped (personal injury, fraud, and whether breach of the DPA or confidentiality is capped separately); what Ethiopian law prevents from being limited.]`

## 9. Term and ending

9.1 The term is as in the order form, renewing for the same period unless either party gives `[confirm: 60]` days' notice.

9.2 Either party may end the agreement if the other materially breaches it and does not cure the breach within `[confirm: 30]` days of notice.

9.3 On ending, the Customer may export its data for 30 days, after which ETHR deletes it, as the DPA §10 says.

## 10. General

10.1 Ethiopian law governs. `[confirm: disputes to the Federal First Instance Court, Addis Ababa, or to arbitration; for a public body, as its procurement contract provides.]`

10.2 Neither party is liable for a failure caused by events beyond its reasonable control, including a national network or power outage. It tells the other promptly and resumes as soon as it can.

10.3 Notices in writing to the addresses in the order form. Email counts if acknowledged.

10.4 This agreement is made in `[English and Amharic]`. `[confirm: which version prevails.]`

---

## Order form

| | |
|---|---|
| Customer | `[name, legal form, address, TIN]` |
| Customer's ETHR address | `ethr.et/[address]` or `[own domain]` |
| Plan | `[plan name]`, as published at `/pricing` on the date of this order |
| Employees included | `[number]` |
| Modules | `[employees, attendance, leave, payroll, recruitment, …]` |
| Subscription fee | `[ETB]` per month `[confirm: VAT]` |
| Implementation services | `[scope, fee]` or none |
| Device services | `[terminals, sites, installation, fee]` or none |
| Start date | `[date, Ethiopian and Gregorian]` |
| Term | `[12 months]` |
| DPA lawful-basis annex | ☐ 2A public body  ☐ 2B private (written consent) |
| Customer contacts | Administrator: `[role]` · Billing: `[role]` · DPO: `[role]` |

Signed for the Customer: ______________  Signed for ETHR: ______________  Date: ______________
