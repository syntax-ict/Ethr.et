# ETHR legal pack

> **DRAFT — NOT LEGAL ADVICE.** Everything in this folder was drafted on 2026-11-25 from this repository and from the English text of the Proclamations it cites. No lawyer has read it. Each document says what counsel must confirm. Nothing here is to be signed, filed or published as final until counsel has reviewed it and the register row for it says `PASS`.

The registrations ETHR needs to trade lawfully in Ethiopia, and the documents customers will ask to sign, kept next to the code they describe so the two can be checked against each other.

**Start with [`LEGAL-REGISTRATION-REGISTER.md`](LEGAL-REGISTRATION-REGISTER.md).** It decides what is done. The other documents are drafts the register points to.

## The documents

| Document | What it is | Who it is for |
|---|---|---|
| [`LEGAL-REGISTRATION-REGISTER.md`](LEGAL-REGISTRATION-REGISTER.md) | **The single register**: every registration, filing and signature ETHR needs, in the order to do them, each with a state, the evidence held and what closes it. Also why the order is what it is | Owner |
| [`RECORD-OF-PROCESSING.md`](RECORD-OF-PROCESSING.md) | The Art. 46 record of processing, read out of the code: what personal data ETHR holds, why, for how long, and how it is protected. Doubles as the answer sheet for registration with the Ethiopian Communications Authority | Owner, counsel, the Authority |
| [`DPIA-ATTENDANCE-MONITORING.md`](DPIA-ATTENDANCE-MONITORING.md) | Impact assessment for the attendance features that monitor people: selfies, GPS check-in, liveness, device enrolment | Owner, counsel, customers' DPOs |
| [`DATA-PROCESSING-AGREEMENT.md`](DATA-PROCESSING-AGREEMENT.md) | The contract Art. 16(3) requires between each customer (controller) and ETHR (processor), with a public-body and a private-employer version of the lawful-basis annex | Customers |
| [`SUB-PROCESSORS.md`](SUB-PROCESSORS.md) | Every third party that receives personal data, and every external call that carries none | Customers |
| [`BIOMETRIC-DATA-POLICY.md`](BIOMETRIC-DATA-POLICY.md) | What biometric data ETHR does and does not hold, and a written-consent form for customers | Customers, employees |
| [`RETENTION-SCHEDULE.md`](RETENTION-SCHEDULE.md) | What is deleted automatically and when, what is never deleted, and the gaps | Customers, owner |
| [`INFORMATION-SECURITY-POLICY.md`](INFORMATION-SECURITY-POLICY.md) | The customer-facing summary of the controls in [`../SECURITY.md`](../SECURITY.md) | Customers, auditors |
| [`SERVICE-LEVEL-AGREEMENT.md`](SERVICE-LEVEL-AGREEMENT.md) | Support response and restore commitments. Uptime is a target, not a guarantee, until cutover | Customers |
| [`MASTER-SUBSCRIPTION-AGREEMENT.md`](MASTER-SUBSCRIPTION-AGREEMENT.md) | The contract for government and business buyers who will not click through terms, with an order form | Government and B2B buyers |
| [`IP-OWNERSHIP-AND-ASSIGNMENT.md`](IP-OWNERSHIP-AND-ASSIGNMENT.md) | How ownership of the code reaches the entity: assignment deeds for the founder and for contractors and staff | Owner, contributors |
| [`TRADEMARK-FILING-BRIEF.md`](TRADEMARK-FILING-BRIEF.md) | What to file at EIPA, in which classes, and why not "ETHR.et" | Owner, trademark agent |
| [`THIRD-PARTY-LICENSES.md`](THIRD-PARTY-LICENSES.md) | The open-source licences ETHR ships, and the obligations the unusual ones carry | Owner, counsel |

The click-through Privacy Policy and Terms of Service shown on the public site are not here: they live in `src/src/lib/legal/documents.ts`, rendered at `/privacy` and `/terms`, and carry their own draft banner (`LEGAL_REVIEW_REQUIRED`).

## Rules for this folder

1. **Describe the code, not an intention.** Every claim about what ETHR collects, deletes or protects cites the file it was read from. When the code changes, the document changes in the same pull request. A legal document describing a control that does not exist is worse than none.
2. **Every legal point carries a confidence class**, as [`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md) does:
   - **1**: read in the Proclamation's own text.
   - **2**: from a secondary source, or a text not fully read.
   - **3**: needs counsel.
3. **Never invent a fact only the owner has.** The entity's legal name, registration number, address, data-protection contact, prices and venue are `[confirm]` until the owner supplies them. A plausible placeholder is how a made-up bank account once shipped in this product.
4. **No names of people.** Contributors, the DPO and contacts appear by role. Personal details belong in the signed originals, kept outside the repository.
5. **No machine translation of legal text.** Amharic versions are made by a human translator and checked by counsel. A mistranslated obligation is a wrong obligation.
