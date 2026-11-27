# IP ownership and assignment

> **DRAFT — NOT LEGAL ADVICE.** Drafted 2026-11-25. Counsel has not confirmed it, and the deeds below must be adapted by counsel to Ethiopian copyright law before anyone signs. Register rows L6, L7, L8, L28.

**What this solves.** `LICENSE` says ETHR belongs to Syntax Technology. That is a claim, and its evidence is weaker than it looks:

- Syntax Technology is a **sole proprietorship**, so in law the business and the owner are one person. Whatever the owner wrote belongs to the owner. That is fine until the business converts to a PLC (register row L1). Then it becomes the owner's property, not the company's, unless the owner assigns it.
- **Contractors and staff also contributed.** Under Ethiopian copyright law, who owns work made by an employee or commissioned from a contractor depends on the contract and on the law's rules for each case (class 3: counsel to confirm). **Where nothing was signed, do not assume the business owns it.**
- Git shows a single author account for every commit. That records who pushed, not who wrote. It is not evidence of ownership either way.

So: assign first, register after (register row L28).

## A. Founder assignment (to sign on conversion to a PLC)

> **Deed of assignment of intellectual property**
>
> This deed is made on `[date]` between `[owner's full name]`, of `[address]` (**the Assignor**), and `[Syntax Technology PLC]`, registration number `[ ]`, of `[address]` (**the Company**).
>
> 1. **Assignment.** In return for `[confirm: consideration, e.g. shares allotted or ETB 1]`, the Assignor assigns to the Company all copyright and other intellectual property rights, present and future, in:
>    (a) the computer program known as ETHR, in every version, in source and object form, including its database design, interfaces and documentation;
>    (b) the ETHR name, logo and brand assets, and any application to register them;
>    (c) the domain name `ethr.et`; and
>    (d) all works made by the Assignor for the business carried on as Syntax Technology before this deed.
> 2. **Moral rights.** To the extent the law allows, the Assignor will not assert moral rights against the Company's use, adaptation or modification of the works. `[counsel: Ethiopian law may not permit waiver; adapt.]`
> 3. **Third-party material.** The Assignor has disclosed the open-source components the works incorporate ([`THIRD-PARTY-LICENSES.md`](THIRD-PARTY-LICENSES.md)). The assignment covers only what the Assignor owns.
> 4. **Further acts.** The Assignor will sign whatever else is reasonably needed to record or register the Company's ownership, including at EIPA.
>
> Signed: Assignor ______________ For the Company ______________ Witnesses ______________

## B. Contributor assignment (contractors and staff)

One per person who wrote code, designed screens, drew the brand, or wrote documentation or content for ETHR. It must cover **work already done**, because that is the gap today, and **work to come**.

> **Assignment and confidentiality agreement**
>
> Between `[Syntax Technology / the PLC]` (**the Business**) and `[contributor's full name]`, of `[address]` (**the Contributor**), engaged as `[contractor / employee]` from `[date]`.
>
> 1. **Past work.** In return for `[confirm: consideration, e.g. ETB ___ paid on signature, or the fees already paid]`, the Contributor assigns to the Business all copyright and other intellectual property rights in everything they made for ETHR before today, including source code, designs, text and documentation (**the Work**).
> 2. **Future work.** Everything the Contributor makes for ETHR from today belongs to the Business as it is made, and this clause is its assignment.
> 3. **Own tools and earlier work.** If the Contributor uses something they made before or outside this engagement, they list it in Schedule 1 and grant the Business a permanent, royalty-free licence to use it as part of ETHR.
> 4. **No third-party code without saying so.** The Contributor has not put into the Work anything copied from another source except open-source components named in the project's lockfiles.
> 5. **Confidentiality.** The Contributor keeps ETHR's source code, credentials, customer data and business information confidential during the engagement and after it ends, and returns or deletes them when it ends.
> 6. **Moral rights, further acts**: as clauses 2 and 4 of deed A.
>
> Schedule 1: pre-existing material `[list, or "none"]`
>
> Signed: the Business ______________ the Contributor ______________ Date ______________

## C. Contributor checklist

Kept here **by role only**. The names, signed originals and contact details belong in the owner's records outside the repository.

| # | Role | Period | What they contributed | Assignment signed? | NDA signed? | Repository access removed when they left? |
|---|---|---|---|---|---|---|
| 1 | Founder (owner) | 2026– | All areas | Not needed until conversion (deed A) | — | — |
| 2 | `[contractor / staff: role]` | `[from–to]` | `[backend / frontend / design / content]` | ☐ | ☐ | ☐ |
| 3 | `[ ]` | | | ☐ | ☐ | ☐ |

**A contributor who cannot be reached, or will not sign**, is recorded here with what they wrote. The choice is then between a later assignment, a licence, or rewriting that part.

## D. AI-assisted code

ETHR is developed with AI coding agents working under the owner's direction and review. This repository carries their policy, [`../MODEL-ROUTING.md`](../MODEL-ROUTING.md). Two consequences:

1. **Copyright in material produced by a machine without human authorship is uncertain in many jurisdictions**, and Ethiopian law has not settled it (class 3). The owner's selection, direction, review and editing are human contributions; how far they carry is a question for counsel. **A copyright certificate does not settle it.**
2. **ETHR's protection therefore rests on more than copyright.** It also rests on:
   - **Trade secrecy:** a private repository, NDAs, access removed when someone leaves (register L8).
   - **Contract:** the MSA's no-copying, no-reverse-engineering clause.
   - **The trademark:** what customers actually buy by name.

   These hold whatever the answer to point 1.

## E. Trade-secret measures

1. The repository stays private. Access is granted per person and reviewed with the quarterly credential review in [`../operations/CUSTODY.md`](../operations/CUSTODY.md).
2. Everyone with access signs B (or an NDA if they contribute nothing).
3. Access is removed on the day someone leaves, and the review records it.
4. Customer contracts forbid copying and reverse-engineering (MSA §5.2).

## F. Copyright deposit (register row L28)

After A (if converting) and B are signed, deposit with EIPA:

- the program's title and version (for example the release tag);
- a representative extract of source code, as EIPA's form specifies; it need not be the whole repository, and should not include secrets;
- the user documentation;
- the screen designs, if registering them as artistic works.

Copyright exists without registration. The certificate is evidence of ownership, which is worth having in a dispute. Whether the certificate expires (a five-year figure was suggested) is unverified (class 3).
