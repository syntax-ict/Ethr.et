# Data Processing Agreement (template)

> **DRAFT — NOT LEGAL ADVICE.** A starting text for counsel, drafted 2026-11-25. Do not sign it as written. Register row L14. Every `[confirm]` is a fact only the owner or the customer can supply.

**Between**

- **The Customer** `[name, legal form, registration number, address]`, the **controller**; and
- **Syntax Technology** `[confirm legal name, legal form, registration number, TIN, address]`, trading as ETHR, the **processor**.

This agreement forms part of the subscription agreement between the parties (the Master Subscription Agreement or the Terms of Service, whichever applies: the **Main Agreement**). Where they conflict on personal data, this agreement prevails.

It is made to meet Art. 16(3) of the Personal Data Protection Proclamation No. 1321/2024: processing by a processor must be under a contract made or evidenced in writing, on the controller's instructions only, carrying obligations equivalent to the controller's own duties of integrity and confidentiality (class 1).

## 1. Definitions

Terms defined in Proclamation No. 1321/2024 have the same meaning here: personal data, sensitive personal data, biometric data, processing, controller, processor, data subject, personal data breach, the Authority (the Ethiopian Communications Authority, Art. 2(36)).

## 2. Scope

ETHR processes the personal data described in **Annex 1** to provide the service described in the Main Agreement, for the term of the Main Agreement and the period after it set out in §10.

## 3. The Customer's instructions

3.1 ETHR processes the Customer's personal data **only on the Customer's documented instructions**. The Main Agreement, this agreement, and the Customer's configuration of the service (the features it turns on, the users and permissions it grants, the retention periods it sets) are those instructions.

3.2 If ETHR is required by Ethiopian law to process the data otherwise, it tells the Customer first, unless the law forbids that.

3.3 ETHR tells the Customer if it believes an instruction breaks Proclamation No. 1321/2024.

## 4. The Customer's responsibilities

4.1 The Customer is responsible for having a lawful basis for every category of personal data it puts into ETHR, and for the information notice its employees and applicants receive (Art. 24, class 2).

4.2 **For sensitive personal data, including biometric data and medical documents**, the Customer relies on the basis set out in **Annex 2**: Annex 2A if it is a public body, Annex 2B otherwise.

4.3 The Customer decides who in its organisation has access, and removes access when someone leaves.

4.4 The Customer handles requests from its own employees and applicants to exercise their rights. ETHR helps as §8 says.

## 5. Confidentiality and security

5.1 ETHR ensures that everyone it authorises to process the Customer's personal data is bound to confidentiality, and acts only on instructions (Art. 16(4), class 1).

5.2 ETHR maintains the technical and organisational measures in **Annex 3** (Art. 17(4), class 1). It may change them, but not so as to lower the protection they give.

5.3 ETHR platform staff reach the Customer's data only through impersonation, which requires multi-factor authentication, is limited in time, and records in the audit log who acted (`impersonated_by`).

## 6. Sub-processors

6.1 The Customer authorises the sub-processors listed in [`SUB-PROCESSORS.md`](SUB-PROCESSORS.md) at the date of signature.

6.2 ETHR gives the Customer at least `[confirm: 30]` days' written notice before adding or replacing a sub-processor that receives personal data. The Customer may object on reasonable data-protection grounds. If the parties cannot resolve the objection, the Customer may end the Main Agreement for the affected service without penalty.

6.3 ETHR imposes on each sub-processor obligations no less protective than these, and remains responsible to the Customer for its sub-processors.

## 7. Where the data is held

7.1 ETHR stores the Customer's personal data on servers in Ethiopia (Art. 22(1), class 1).

7.2 ETHR does not transfer the Customer's personal data outside Ethiopia except as Arts. 18 to 22 permit and with the Customer's prior written instruction. Backups are held to the same rule: in production they are copied only to storage located in Ethiopia, and never to a service that stores outside it.

## 8. Helping the Customer

8.1 **Data subject requests.** ETHR provides the means in the service for the Customer to access, correct, export and erase its employees' data, and helps further on request, within a reasonable time. The tools that exist today: employee self-service views; HR correction; organisation-wide export ([`../operations/DATA-EXPORT.md`](../operations/DATA-EXPORT.md)); erasure of applicants. **There is no per-employee export or erasure of an active employee's record in the console**: such a request is handled by ETHR on the Customer's written instruction.

8.2 **Impact assessments and the Authority.** ETHR provides the information the Customer reasonably needs for its own impact assessment, including [`DPIA-ATTENDANCE-MONITORING.md`](DPIA-ATTENDANCE-MONITORING.md), and for any consultation with the Authority.

## 9. Personal data breaches

9.1 ETHR tells the Customer **without undue delay, and in any case within `[confirm: 24]` hours**, after becoming aware of a personal data breach affecting the Customer's data (Art. 43(3), class 2).

9.2 The notice says, as far as then known: what happened; the categories and approximate number of people and records affected; the likely consequences; what ETHR has done and proposes to do; and a contact. ETHR adds what it learns as it learns it.

9.3 The Customer, as controller, notifies the Authority and the people affected, within the 72 hours Arts. 43 and 44 allow (class 2). ETHR does not notify them on the Customer's behalf unless the Customer instructs it to.

9.4 ETHR's procedure is [`../operations/DATA-BREACH-RESPONSE.md`](../operations/DATA-BREACH-RESPONSE.md).

## 10. End of the agreement

10.1 For `[confirm: 30]` days after the Main Agreement ends, the Customer may download a full export of its data.

10.2 After that, ETHR **deletes** the Customer's personal data, including from backups as they rotate out, unless Ethiopian law requires it to keep some, and confirms in writing when it is done (Art. 50, class 2).

10.3 `[confirm before signature: closing an organisation in ETHR today suspends it and keeps its records ([`../operations/TENANT-DELETION.md`](../operations/TENANT-DELETION.md)). Permanent erasure is not in the console. Until a documented erasure procedure exists, §10.2 is performed by ETHR by hand on the Customer's written instruction, and the time it takes must be stated here.]`

## 11. Records and audit

11.1 ETHR keeps a record of the processing it does for the Customer (Art. 46, class 1): [`RECORD-OF-PROCESSING.md`](RECORD-OF-PROCESSING.md).

11.2 ETHR makes available the information needed to show it meets this agreement. The Customer, or an auditor it appoints bound to confidentiality, may audit once a year on `[confirm: 30]` days' notice, at the Customer's cost, without access to other customers' data. A security assessment required by law, including one by INSA, is accepted on the same terms.

## 12. Liability

As in the Main Agreement `[confirm: counsel]`.

## 13. Law and disputes

Ethiopian law. Disputes as in the Main Agreement.

---

## Annex 1: Description of the processing

| | |
|---|---|
| **Data subjects** | The Customer's employees, former employees, job applicants, and the emergency contacts they name |
| **Categories of data** | As [`RECORD-OF-PROCESSING.md`](RECORD-OF-PROCESSING.md) Part 1 lists, limited to the features the Customer turns on |
| **Sensitive data** | Attendance selfies (biometric), if the Customer turns them on; medical documents and sick-leave evidence, if the Customer uploads them. **ETHR does not receive or store fingerprint or face templates**: they stay on the terminals |
| **Purposes** | Providing ETHR's HR, attendance, leave, payroll and recruitment functions to the Customer |
| **Duration** | The term of the Main Agreement, plus §10 |
| **Retention** | As [`RETENTION-SCHEDULE.md`](RETENTION-SCHEDULE.md), and the periods the Customer sets |
| **Location** | Ethiopia (§7) |

## Annex 2: Lawful basis for sensitive data

The Customer completes **one** of these.

### Annex 2A: The Customer is a public body

The Customer is `[federal / regional / city / woreda]` body `[name]`, established by `[instrument]`. It processes biometric data (attendance selfies) and medical documents in ETHR to carry out its lawful, non-commercial objectives of managing its public servants' attendance and leave, relying on **Art. 9(2)(c)** (class 1). It applies the safeguards in Art. 9(4) (class 1), including those in Annex 3.

The Customer remains responsible for informing its servants and for designating a Data Protection Officer, which Art. 40 makes mandatory for government bodies ([`../EMPLOYMENT-LAW-REGISTER.md`](../EMPLOYMENT-LAW-REGISTER.md), class 1). `[confirm: counsel, whether Art. 9(2)(c) covers a municipal or woreda administration as well as a federal body.]`

### Annex 2B: The Customer is not a public body

The Customer relies on the **prior written consent of each employee, specific to the purpose**, under **Art. 9(2)(a)** (class 1).

The Customer undertakes that:

1. It obtains that consent in writing before any biometric data is captured, including on a terminal, and keeps it. The form in [`BIOMETRIC-DATA-POLICY.md`](BIOMETRIC-DATA-POLICY.md) §4 may be used.
2. **It offers every employee a way to record attendance that uses no biometric data** (card, PIN, QR, or a punch without a selfie), and does not disadvantage anyone who uses it. Consent must be free (Art. 8(2), class 1), and it is not free if refusing costs the person.
3. An employee may withdraw consent at any time (Art. 8(3), class 1). In ETHR, an employee withdraws selfie consent at `/profile/photo-consent`. For a terminal, the Customer deletes the person's templates from the device.

## Annex 3: Security measures

As in [`INFORMATION-SECURITY-POLICY.md`](INFORMATION-SECURITY-POLICY.md), at the date of signature.

## Annex 4: On-site installation and enrolment

Where Syntax Technology installs or configures attendance terminals at the Customer's premises:

1. Its technicians act on the Customer's instructions and under §5.1.
2. They enrol people on a terminal only after the Customer confirms that consent (Annex 2B) or its public-body basis (Annex 2A) is in place.
3. They do not copy templates, photos or user lists off a terminal except into the Customer's own ETHR organisation.
4. They do not keep any personal data after the visit. Any working list the Customer gives them is returned or destroyed.
