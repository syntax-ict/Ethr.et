/**
 * The privacy policy and terms of service, as content rather than UI strings.
 *
 * WHY NOT i18n. These run to a few thousand words each. Putting them in
 * `en.json`/`am.json` would roughly double those files, and `i18n-check.js`
 * enforces en/am key parity — so every paragraph would need an Amharic legal
 * translation before the gate went green. Producing that by machine is worse
 * than not having it: a mistranslated obligation is a wrong obligation. The
 * dictionaries stay what they are for everywhere else in this codebase —
 * interface chrome — and the prose lives here.
 *
 * The page chrome around the prose (headings, the language notice, the
 * effective-date label) does go through `useT`, so the frame is bilingual even
 * while the body is not.
 *
 * TRANSLATION. Amharic is this product's default rendered locale, so shipping
 * English-only legal text is a real gap, not a stylistic one. It is left open
 * rather than faked: add `privacyAm`/`termsAm` here and the renderer picks them
 * up. Until then the page says so in the reader's own language.
 *
 * WHAT IS ASSERTED HERE. Every factual claim below about what ETHR collects,
 * encrypts, logs or bills was read out of this repository — the migrations, the
 * casts, `BillingService`, `AuthService`, `instrumentation-client.ts`. Nothing
 * describes a control that does not exist; where a control is absent (automated
 * retention, an SLA) the text says so instead of implying one.
 *
 * WHAT IS NOT. This is a drafted starting point, not legal advice, and it has
 * not been reviewed by a lawyer. See `LEGAL_REVIEW_REQUIRED` below.
 */

/**
 * Renders an unmissable banner at the top of both documents.
 *
 * Set to `false` once a qualified adviser has reviewed and approved the text,
 * and confirmed the entries in `OWNER_SUPPLIED_FACTS` below. Deliberately a
 * visible banner rather than a code comment: the failure mode worth preventing
 * is unreviewed legal text going live silently because nobody remembered it was
 * a draft. A comment cannot prevent that. A banner on the page can.
 */
export const LEGAL_REVIEW_REQUIRED = true;

/**
 * Facts only the operator can supply. None are invented here.
 *
 * This repository has been bitten by plausible-looking placeholders before —
 * `platform_settings` exists because a made-up bank account number shipped in
 * the browser bundle. So where a legal document needs a fact nobody has given
 * me (registered entity name, business address, a data-protection contact),
 * the text refers the reader to the contact page rather than printing
 * something that reads like an answer and is not one.
 *
 * Fill these in — ideally as platform settings, per
 * `docs/PLATFORM_MANAGED_CONTENT_PLAN.md` — before the banner comes down.
 */
export const OWNER_SUPPLIED_FACTS = [
  "Registered legal entity name and company registration number",
  "Registered business address",
  "A contact point for data-protection requests",
  "Governing law and dispute-resolution venue (assumed Ethiopia below)",
  "Retention periods for each category of data (none are automated today)",
] as const;

export interface LegalBlock {
  /** A paragraph, or a bulleted list. */
  text?: string;
  list?: string[];
}

export interface LegalSection {
  id: string;
  heading: string;
  blocks: LegalBlock[];
}

export interface LegalDocument {
  slug: "privacy" | "terms";
  title: string;
  summary: string;
  /** ISO date. Rendered through the tenant-neutral date formatter. */
  effectiveDate: string;
  sections: LegalSection[];
}

const EFFECTIVE_DATE = "2026-09-16";

export const privacyEn: LegalDocument = {
  slug: "privacy",
  title: "Privacy Policy",
  summary:
    "What ETHR collects, why, and what we do not collect. Written against what the software actually does.",
  effectiveDate: EFFECTIVE_DATE,
  sections: [
    {
      id: "roles",
      heading: "Two different roles, and why it matters to you",
      blocks: [
        {
          text: "ETHR is human-resources software sold to organizations. That means personal data reaches us in two very different ways, and your rights differ depending on which applies.",
        },
        {
          list: [
            "When your employer uses ETHR to manage you, your employer decides what is collected and why. They are the controller of that data; we process it on their instructions. If you are an employee of an organization using ETHR, start with your employer's HR team.",
            "When you contact us, register an organization, or administer an account, we decide what is collected. For that data we are the controller, and this policy is our own commitment to you.",
          ],
        },
      ],
    },
    {
      id: "collect",
      heading: "What we collect",
      blocks: [
        { text: "When you send an enquiry through our contact form:" },
        {
          list: [
            "Your name, email address, and the message you write.",
            "Optionally your phone number and organization, if you choose to give them.",
            "We do not record your IP address or browser details with an enquiry.",
          ],
        },
        { text: "When you create an organization account:" },
        {
          list: [
            "The organization's name and chosen subdomain.",
            "The administrator's name, email address, and optionally an Ethiopian phone number.",
            "A password, which is stored only as a cryptographic hash and is never recoverable in readable form.",
          ],
        },
        {
          text: "When your organization uses the product, it enters the workforce data it needs — employee records, attendance, leave, payroll. That data belongs to your organization, and the first section above explains who is responsible for it.",
        },
        {
          text: "Automatically, for security rather than analytics, we record the IP address and browser user-agent attached to sign-ins, active sessions, API tokens, and entries in the audit log. This is how an administrator can see which devices are signed in and revoke one they do not recognise. It is not used to build a profile of you or to measure your behaviour.",
        },
      ],
    },
    {
      id: "not-collect",
      heading: "What we do not collect",
      blocks: [
        {
          text: "This section is as much a commitment as a description, and each item is a property of the software rather than a promise about intentions:",
        },
        {
          list: [
            "No web analytics. ETHR's public site loads no analytics or advertising script, and sets no tracking or advertising cookies.",
            "No session recording. Error monitoring is configured with session replay switched off entirely and with personal data excluded from reports by default.",
            "No selling or sharing of personal data for advertising. We do not do this and the product has no mechanism for it.",
            "No third-party fonts, trackers, or embeds loaded from the public site at run time.",
          ],
        },
      ],
    },
    {
      id: "cookies",
      heading: "Cookies and browser storage",
      blocks: [
        {
          text: "Signed-in use requires a session cookie. It is restricted to same-site requests, it carries no personal data itself, and the session it refers to is stored on the server.",
        },
        {
          text: "Your browser also stores two preferences locally — your chosen language and your light or dark theme. These never leave your device and are not sent to us.",
        },
      ],
    },
    {
      id: "security",
      heading: "How the data is protected",
      blocks: [
        {
          text: "Several fields are encrypted in the database rather than merely access-controlled, so that a copy of the database alone does not reveal them:",
        },
        {
          list: [
            "Taxpayer identification numbers and national identity numbers.",
            "Employee bank account numbers.",
            "Multi-factor authentication secrets and single sign-on certificates.",
            "The before-and-after values held in pending profile-change requests.",
          ],
        },
        {
          text: "Each organization's data is separated by a database-level rule that fails closed: where no organization context is established, queries return nothing rather than everything. Administrative actions are written to an append-only audit log.",
        },
        {
          text: "No system is perfectly secure, and we do not claim otherwise. We describe the mechanisms above so you can judge them, not to imply that they make a breach impossible.",
        },
      ],
    },
    {
      id: "location",
      heading: "Where your data is held",
      blocks: [
        {
          text: "ETHR is deployed on infrastructure chosen by the organization operating it, and the physical location of the data follows that deployment. If you are an employee, your employer can tell you where their instance runs. If you are evaluating ETHR, ask us before you commit — we will answer specifically rather than generally.",
        },
      ],
    },
    {
      id: "retention",
      heading: "How long we keep it",
      blocks: [
        {
          text: "Stated plainly, because the honest answer is not yet a reassuring one: ETHR does not currently delete any category of data automatically. Records persist until the organization that owns them removes them, or until an account is closed and its data is deleted on request.",
        },
        {
          text: "Defined retention periods for each category of data are being established and will be published here. Until then, if you want something deleted, ask — and we will act on it rather than point at a schedule that does not exist.",
        },
      ],
    },
    {
      id: "rights",
      heading: "Your rights",
      blocks: [
        {
          text: "Ethiopian data-protection law gives you rights over personal data about you, including access to it and correction of it. Where your employer is the controller, address those requests to them first; where we are, contact us and we will respond.",
        },
        {
          text: "You never need a reason to ask what we hold about you.",
        },
      ],
    },
    {
      id: "changes",
      heading: "Changes to this policy",
      blocks: [
        {
          text: "If this policy changes in a way that affects what we collect or what we do with it, we will change the effective date above and, for account holders, say so directly rather than relying on you to re-read the page.",
        },
      ],
    },
    {
      id: "contact",
      heading: "Contacting us",
      blocks: [
        {
          text: "Questions about this policy, or a request about your own data, can be sent through our contact page.",
        },
      ],
    },
  ],
};

export const termsEn: LegalDocument = {
  slug: "terms",
  title: "Terms of Service",
  summary:
    "The agreement for using ETHR: accounts, the trial, billing, availability, and what each side is responsible for.",
  effectiveDate: EFFECTIVE_DATE,
  sections: [
    {
      id: "agreement",
      heading: "This agreement",
      blocks: [
        {
          text: "These terms govern your organization's use of ETHR. Where an individual uses ETHR because their employer provides it, the agreement is with the employer, and the employer's own policies also apply.",
        },
      ],
    },
    {
      id: "accounts",
      heading: "Accounts",
      blocks: [
        {
          text: "Creating an organization reserves a subdomain and creates an administrator account. You are responsible for the accuracy of the details you give and for keeping the administrator's credentials secure. Multi-factor authentication is available and we recommend enabling it for every administrator.",
        },
      ],
    },
    {
      id: "trial",
      heading: "The trial",
      blocks: [
        {
          text: "New organizations begin on a six-month trial. During the trial, every feature is available and the plan limits on employees, branches and devices are not applied — the trial is not a restricted tier.",
        },
        {
          text: "We will tell you before the trial ends. Nothing is charged during it and no payment details are required to start.",
        },
      ],
    },
    {
      id: "billing",
      heading: "Plans and billing",
      blocks: [
        {
          text: "Paid plans are billed monthly in Ethiopian Birr. The current price and the limits of each plan are shown on the pricing page and are the same figures the system enforces and bills.",
        },
        {
          list: [
            "An invoice is issued at the start of each monthly period and is due within fifteen days.",
            "An invoice unpaid seven days after its due date is marked overdue and the account's administrators are notified.",
            "Continued non-payment escalates, and an account may be suspended if an invoice remains unpaid sixty days after it was issued. Suspension restricts access; it does not delete your data.",
          ],
        },
        {
          text: "Changing plan mid-period is prorated for the remainder of that period.",
        },
      ],
    },
    {
      id: "responsibilities",
      heading: "Your responsibilities",
      blocks: [
        {
          text: "ETHR holds information about your employees, and you decide what goes into it. You are responsible for having a lawful basis for the personal data you enter, for its accuracy, and for telling your employees what you hold and why.",
        },
        {
          text: "You are also responsible for who you grant access to, and for removing access when someone leaves.",
        },
      ],
    },
    {
      id: "acceptable-use",
      heading: "Acceptable use",
      blocks: [
        {
          text: "Do not use ETHR to break the law, to store data you have no right to hold, to attempt to reach another organization's data, or to disrupt the service for others. We may suspend an account that does.",
        },
      ],
    },
    {
      id: "availability",
      heading: "Availability",
      blocks: [
        {
          text: "We do not currently offer a contractual uptime guarantee, and we would rather say that than publish a number we cannot stand behind. We aim to keep ETHR available and to be candid when it is not.",
        },
        {
          text: "Much of ETHR is built to keep working without a network connection — attendance in particular records offline and synchronises when connectivity returns — but that is a property of the software, not a service commitment.",
        },
        {
          text: "Where a service level is agreed in writing as part of an enterprise arrangement, that agreement governs and takes precedence over this section.",
        },
      ],
    },
    {
      id: "your-data",
      heading: "Your data stays yours",
      blocks: [
        {
          text: "The workforce data your organization enters belongs to your organization. We use it to provide the service to you and for nothing else. We do not sell it, and we do not mine it to build other products.",
        },
        {
          text: "You can export your data while your account is active. If you close your account, ask us before you do so if you need an export, and we will help.",
        },
      ],
    },
    {
      id: "termination",
      heading: "Suspension and ending the agreement",
      blocks: [
        {
          text: "You may stop using ETHR at any time. We may suspend or end an account for non-payment, for a breach of the acceptable-use section, or where we are required to by law.",
        },
        {
          text: "Where we end an agreement for a reason other than your breach, we will give you reasonable notice and an opportunity to export your data.",
        },
      ],
    },
    {
      id: "liability",
      heading: "Warranties and liability",
      blocks: [
        {
          text: "ETHR is provided as it is. We work to make it correct and reliable, and this section does not limit any liability that cannot be limited under Ethiopian law — including for death or personal injury caused by negligence, or for fraud.",
        },
        {
          text: "The precise limits of liability, and the warranties given, are subject to the review noted at the top of this page and will be stated in full before these terms are relied upon commercially.",
        },
      ],
    },
    {
      id: "law",
      heading: "Governing law",
      blocks: [
        {
          text: "These terms are governed by the laws of the Federal Democratic Republic of Ethiopia, and disputes are subject to the jurisdiction of its courts.",
        },
      ],
    },
    {
      id: "changes",
      heading: "Changes to these terms",
      blocks: [
        {
          text: "We may update these terms. If a change materially affects your rights or what you pay, we will tell account administrators directly and give notice before it takes effect.",
        },
      ],
    },
  ],
};
