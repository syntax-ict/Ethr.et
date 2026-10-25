import type { MarketingItem, MarketingSection } from "@/lib/marketing/content";

/**
 * A stable landing-page payload for tests: the shape `GET /api/v1/marketing-content`
 * publishes, with the first-run copy the seeder carries. Tests seed it into the
 * query cache under `["marketing-content"]` rather than reading the committed
 * snapshot, whose values change whenever the platform operator edits the page
 * and the snapshot is regenerated from production.
 */
let counter = 0;
function item(
  kind: string,
  slug: string,
  fields: Partial<MarketingItem>,
): MarketingItem {
  counter += 1;
  return {
    public_id: `01TEST${String(counter).padStart(20, "0")}`,
    slug,
    icon: null,
    title: slug,
    title_am: null,
    body: null,
    body_am: null,
    detail: null,
    detail_am: null,
    link_path: null,
    image: null,
    ...fields,
    ...(kind ? {} : {}),
  };
}

export function marketingFixture(): { sections: MarketingSection[] } {
  counter = 0;
  return {
    sections: [
      {
        kind: "hero",
        heading:
          "Attendance, payroll and HR, built for Ethiopian organisations",
        heading_am: "ለኢትዮጵያ ድርጅቶች የተሠራ የመገኘት፣ የደመወዝና የሰው ሀብት መድረክ",
        subheading:
          "Employees check in from a phone, a kiosk or the web, even without a network. Payroll follows Proclamation 1395/2025 and the Ethiopian calendar. Everything is in Amharic and English.",
        subheading_am:
          "ሠራተኞች ከስልክ፣ ከኪዮስክ ወይም ከድር ይገባሉ፤ አውታረ መረብ ባይኖርም። ደመወዝ አዋጅ 1395/2025ንና የኢትዮጵያን የቀን አቆጣጠር ይከተላል። ሁሉም በአማርኛና በእንግሊዝኛ ነው።",
        image: null,
        items: [],
      },
      {
        kind: "problems",
        heading: "The work that fills an HR office, and what ETHR does with it",
        heading_am: "የሰው ሀብት ቢሮን የሚሞላው ሥራ፣ እና ETHR በእሱ ላይ የሚያደርገው",
        subheading:
          "Six things that go wrong today, each beside the way ETHR handles it.",
        subheading_am: "ዛሬ የሚበላሹ ስድስት ነገሮች፣ እያንዳንዳቸው ETHR ከሚይዛቸው መንገድ ጎን።",
        image: null,
        items: [
          item("problems", "paper_attendance", {
            icon: "clipboard-list",
            title: "Attendance on paper",
            title_am: "በወረቀት ላይ መገኘት",
            body: "A shift register is signed late, overtime is argued about at month end, and nobody can say who was on site at 8:02.",
            body_am:
              "የፈረቃ መዝገብ ዘግይቶ ይፈረማል፣ የትርፍ ሰዓት ክፍያ በወር መጨረሻ ይከራከርበታል፣ በ8:02 ማን በቦታው እንደነበር ማንም አይናገርም።",
            detail:
              "Staff check in from a phone, a shared kiosk, a QR code or the web, with the time and place recorded as it happens.",
            detail_am:
              "ሠራተኞች ከስልክ፣ ከጋራ ኪዮስክ፣ በQR ኮድ ወይም ከድር ይገባሉ፤ ሰዓቱና ቦታው ወዲያው ይመዘገባል።",
            link_path: "/attendance-system",
          }),
          item("problems", "manual_payroll", {
            icon: "calculator",
            title: "Payroll recomputed by hand",
            title_am: "በእጅ የሚሰላ ደመወዝ",
            body: "Every month someone works the income tax ladder in a spreadsheet.",
            detail:
              "Income tax under Proclamation 1395/2025, pension and Pagume proration are applied automatically.",
          }),
          item("problems", "leave_spreadsheets", {
            icon: "table",
            title: "Leave balances in spreadsheets",
            body: "Balances live in a file only one person can open.",
            detail:
              "Employees see their own balance and request leave from a phone.",
          }),
          item("problems", "branches_offline", {
            icon: "signal-zero",
            title: "Branches without a connection",
            body: "A regional branch loses the network for an afternoon.",
            detail:
              "Check-ins queue on the device and upload themselves the moment the connection returns.",
          }),
          item("problems", "bilingual_staff", {
            icon: "languages",
            title: "One system, two languages",
            body: "The finance team works in English, the floor supervisors in Amharic.",
            detail:
              "Every screen, payslip and notification is in Amharic and English.",
          }),
          item("problems", "contracts_expiring", {
            icon: "file-clock",
            title: "Contracts that expire unnoticed",
            body: "A fixed-term contract ends and nobody is told.",
            detail:
              "Every employee has a complete digital file, and the reminders arrive before an expiry date.",
          }),
        ],
      },
      {
        kind: "capabilities",
        heading: "What ETHR does for your organisation",
        heading_am: "ETHR ለድርጅትዎ የሚሠራው",
        subheading:
          "Five capabilities, each paired with the work it takes off your HR team.",
        subheading_am: "አምስት አቅሞች፤ እያንዳንዳቸው ከሰው ሀብት ቡድንዎ ከሚያቃልሉት ሥራ ጋር።",
        image: null,
        items: [
          item("capabilities", "records", {
            icon: "users",
            title: "Employee records and organisational structure",
            title_am: "የሠራተኛ መዝገብና የድርጅት መዋቅር",
            body: "A complete digital file for every employee, with contracts, documents and expiry reminders.",
            detail: "Establishes reliable workforce data",
            detail_am: "አስተማማኝ የሠራተኛ መረጃ ይገነባል",
          }),
          item("capabilities", "time", {
            icon: "clock",
            title: "Attendance, shifts and leave",
            body: "Clock in from the web, a phone, a shared kiosk or a QR code, even without internet.",
            detail: "Reduces repetitive administration",
            link_path: "/attendance-system",
          }),
          item("capabilities", "payroll", {
            icon: "wallet",
            title: "Payroll and payslips",
            body: "Ethiopian income tax under Proclamation 1395/2025, pension, overtime and Pagume proration, with a calculation trail for every payslip.",
            detail: "Brings care to a high-impact, sensitive workflow",
            link_path: "/payroll-software",
          }),
          item("capabilities", "self_service", {
            icon: "smartphone",
            title: "Employee self-service and approvals",
            body: "Staff check their shift, request leave, download payslips and update their details from any phone.",
            detail: "Reduces routine HR requests",
          }),
          item("capabilities", "analytics", {
            icon: "bar-chart-3",
            title: "Workforce analytics",
            body: "Headcount, attendance, payroll cost and overtime on one dashboard.",
            detail: "Supports better management decisions",
          }),
        ],
      },
      {
        kind: "how_it_works",
        heading: "Up and running in minutes",
        heading_am: "በደቂቃዎች ውስጥ ይጀምሩ",
        subheading: null,
        subheading_am: null,
        image: null,
        items: [
          item("how_it_works", "register", {
            title: "Create your account",
            body: "Sign up in two minutes.",
          }),
          item("how_it_works", "configure", {
            title: "Configure your workspace",
            body: "Set up departments, shifts, leave policies and payroll rules.",
          }),
          item("how_it_works", "go_live", {
            title: "Go live",
            body: "Start tracking attendance, processing payroll and managing your workforce.",
          }),
        ],
      },
      {
        kind: "industries",
        heading: "Built for Ethiopian industries",
        heading_am: "ለኢትዮጵያ ኢንዱስትሪዎች የተዘጋጀ",
        subheading: "Pre-configured templates for your sector",
        subheading_am: "ለዘርፍዎ አስቀድሞ የተዋቀሩ ቅጦች",
        image: null,
        items: [
          item("industries", "government", {
            icon: "landmark",
            title: "Government",
            title_am: "መንግሥት",
          }),
          item("industries", "banking", {
            icon: "landmark",
            title: "Banking",
            title_am: "ባንክ",
          }),
          item("industries", "healthcare", {
            icon: "hospital",
            title: "Healthcare",
          }),
          item("industries", "manufacturing", {
            icon: "factory",
            title: "Manufacturing",
          }),
          item("industries", "ngo", { icon: "heart", title: "NGO" }),
          item("industries", "hospitality", {
            icon: "hotel",
            title: "Hospitality",
          }),
          item("industries", "education", {
            icon: "graduation-cap",
            title: "Education",
          }),
          item("industries", "general", {
            icon: "briefcase",
            title: "General",
          }),
        ],
      },
      {
        kind: "trust",
        heading: "Your data, stored in Ethiopia",
        heading_am: "ውሂብዎ፣ በኢትዮጵያ ውስጥ",
        subheading:
          "ETHR keeps your organisation's data on servers in Ethiopia, on Ethio Telecom hosting or your own, as the Personal Data Protection Proclamation No. 1321/2024 requires. Your organisation stays in control of its data; ETHR supplies the safeguards below.",
        subheading_am: null,
        image: null,
        items: [
          item("trust", "data_residency", {
            icon: "map-pin",
            title: "Data residency",
            body: "Stored on servers in Ethiopia, as Article 22 of Proclamation 1321/2024 requires for personal data collected here.",
          }),
          item("trust", "encryption", {
            icon: "lock",
            title: "Encryption at rest",
            body: "Bank account, TIN and national ID numbers are encrypted at rest with AES-256.",
          }),
          item("trust", "isolation", {
            icon: "shield",
            title: "Tenant isolation",
            body: "Every query is scoped to one organization by a guard that fails closed: with no organization resolved, it returns no rows rather than all of them.",
          }),
          item("trust", "audit", {
            icon: "scroll-text",
            title: "Audit trail",
            body: "Sensitive actions are recorded in an audit trail that cannot be edited or deleted.",
          }),
        ],
      },
      {
        kind: "cta",
        heading: "Start your 6-month free trial",
        heading_am: "የ6 ወር ነጻ ሙከራ ይጀምሩ",
        subheading: "No credit card required. Full access to all features.",
        subheading_am: "ክሬዲት ካርድ አያስፈልግም። ሁሉም ባህሪያት ሙሉ ተደራሽነት።",
        image: null,
        items: [
          item("cta", "trial", { title: "6-month free trial" }),
          item("cta", "no_card", { title: "No credit card" }),
          item("cta", "cancel", { title: "Cancel anytime" }),
        ],
      },
    ],
  };
}

/** An uploaded picture as the API publishes one. */
export function fixtureImage(owner = "01TEST00000000000000000001") {
  return {
    url: `/api/v1/marketing-images/${owner}/image/0123456789abcdef`,
    srcset: `/api/v1/marketing-images/${owner}/image-640/0123456789abcdef 640w, /api/v1/marketing-images/${owner}/image/0123456789abcdef 1600w`,
    width: 1600,
    height: 1000,
    alt: "An HR officer with a complete employee file",
    alt_am: "ሙሉ የሠራተኛ ፋይል የያዘች የሰው ሀብት ባለሙያ",
  };
}
