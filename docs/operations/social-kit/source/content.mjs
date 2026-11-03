// Every word drawn on a social image lives here, so copy can change without
// touching layout. English follows the live landing page; every Amharic line
// is copied from src/src/lib/i18n/locales/am.json or the landing snapshot
// (src/src/lib/marketing/content-snapshot.json). Do not translate afresh:
// take the term from the product's dictionary, or ask a native reviewer.
//
// `node check.mjs` runs every string in this file through the same refused
// claims list the marketing console enforces.

export const SITE = "www.ethr.et";

export const CTA = {
  en: { trial: "6-month free trial", card: "No credit card required" },
  am: { trial: "የ6 ወር ነጻ ሙከራ", card: "ክሬዲት ካርድ አያስፈልግም" },
};

// One feed post per pillar, in each language. `visual` names a drawing in
// render.mjs; `path` is the landing page the caption links to.
export const PILLARS = [
  {
    id: "offline",
    visual: "sync",
    theme: "dark",
    path: "/attendance-system",
    en: {
      over: "Attendance",
      h: "Keeps working offline",
      p: "Records queue on the device and upload themselves the moment the connection returns.",
    },
    am: {
      over: "መገኘት",
      h: "ከመስመር ውጭ መስራቱን ይቀጥላል",
      p: "መዝገቦቹ በመሣሪያው ላይ ተሰልፈው ይቆያሉ፤ ግንኙነቱ እንደተመለሰ በራሳቸው ይላካሉ።",
    },
  },
  {
    id: "payroll",
    visual: "payslip",
    theme: "light",
    path: "/payroll-software",
    en: {
      over: "Payroll",
      h: "Payroll under Ethiopian law",
      p: "Income tax under Proclamation 1395/2025 and pension, applied automatically, with a calculation trail on every payslip.",
    },
    am: {
      over: "ደመወዝ",
      h: "ደመወዝ በኢትዮጵያ ሕግ መሠረት",
      p: "የገቢ ግብርና ጡረታ በራስ-ሰር ይሰላሉ፤ በኢትዮጵያ የቀን አቆጣጠር።",
    },
  },
  {
    id: "calendar",
    visual: "calendar",
    theme: "dark",
    path: "/ethiopian-calendar-hr",
    en: {
      over: "Ethiopian calendar",
      h: "Pagume included",
      p: "The thirteenth month counts in payroll proration and in leave, not as an exception to fix by hand.",
    },
    am: {
      over: "የኢትዮጵያ የቀን አቆጣጠር",
      h: "ጳጉሜ ተካቷል",
      p: "አሥራ ሦስተኛው ወር በደመወዝ ተመጣጣኝ ክፍያና በፈቃድ ውስጥ ይቆጠራል፤ በእጅ የሚስተካከል ልዩ ሁኔታ አይደለም።",
    },
  },
  {
    id: "language",
    visual: "language",
    theme: "light",
    path: "/ethiopian-calendar-hr",
    en: {
      over: "Amharic and English",
      h: "One system, two languages",
      p: "Every screen, payslip and notification is in Amharic and English. Each person chooses their own language.",
    },
    am: {
      over: "አማርኛና እንግሊዝኛ",
      h: "አንድ ሥርዓት፣ ሁለት ቋንቋ",
      p: "እያንዳንዱ ገጽ፣ የደመወዝ ወረቀትና ማሳወቂያ በአማርኛና በእንግሊዝኛ ነው። እያንዳንዱ ሰው የራሱን ቋንቋ ይመርጣል።",
    },
  },
  {
    id: "trust",
    visual: "trust",
    theme: "dark",
    path: "/faq",
    en: {
      over: "Data residency",
      h: "Your data, stored in Ethiopia",
      p: "Stored on servers in Ethiopia, as Article 22 of Proclamation 1321/2024 requires for personal data collected here.",
    },
    am: {
      over: "የውሂብ መኖሪያ",
      h: "ውሂብዎ፣ በኢትዮጵያ ውስጥ",
      p: "በአዋጅ 1321/2024 አንቀጽ 22 መሠረት፣ እዚህ የተሰበሰበ የግል መረጃ በኢትዮጵያ ውስጥ ባሉ ሰርቨሮች ላይ ይቀመጣል።",
    },
  },
  {
    id: "leave",
    visual: "leave",
    theme: "light",
    path: "/leave-management",
    en: {
      over: "Leave",
      h: "Request from a phone, decide from one inbox",
      p: "Staff see their balance before they ask, and a manager approves or declines from the approvals inbox.",
    },
    am: {
      over: "ፈቃድ",
      h: "ለኢትዮጵያ ድርጅቶች የፈቃድ አስተዳደር",
      p: "ሠራተኞች ከስልካቸው ፈቃድ ይጠይቃሉ፣ ኃላፊዎች ከአንድ ሳጥን ይወስናሉ፣ ቀሪዎችም ያለ ተመን ሉህ ትክክል ሆነው ይቆያሉ።",
    },
  },
  {
    id: "kiosk",
    visual: "kiosk",
    theme: "dark",
    path: "/attendance-system",
    en: {
      over: "Shared kiosk",
      h: "Phone, QR code, kiosk and web",
      p: "Choose how each site checks in. A shared kiosk serves staff who do not carry a smartphone.",
    },
    am: {
      over: "ኪዮስክ",
      h: "ስልክ፣ QR ኮድ፣ ኪዮስክና ድር",
      p: "እያንዳንዱ ቦታ እንዴት እንደሚገባ ይምረጡ። የጋራ ኪዮስክ ስማርትፎን የሌላቸውን ሠራተኞች ያገለግላል።",
    },
  },
  {
    id: "trial",
    visual: "trial",
    theme: "dark",
    path: "/",
    en: {
      over: "Start today",
      h: "Start your 6-month free trial",
      p: "No credit card required. Full access to all features.",
    },
    am: {
      over: "ዛሬ ይጀምሩ",
      h: "የ6 ወር ነጻ ሙከራ ይጀምሩ",
      p: "ክሬዲት ካርድ አያስፈልግም። ሁሉም ባህሪያት ሙሉ ተደራሽነት።",
    },
  },
];

// Labels drawn inside the illustrations, from the product's own dictionary.
export const LABELS = {
  en: {
    offline: "Offline",
    synced: "Synced",
    checkIn: "Check in",
    payslip: "Payslip",
    gross: "Gross",
    tax: "Income Tax",
    pension: "Pension 7%",
    net: "Net",
    approved: "Approved",
    pending: "Pending",
    proclamation: "Proclamation 1395/2025",
    pensionSplit: "Pension: 7% employee + 11% employer",
    leaveBalance: "Leave balance",
    kiosk: "ETHR Kiosk",
    kioskHint: "Type only your number: 12 for EMP-0012",
    today: "Today",
    withEthr: "With ETHR",
    trust: [
      ["Data residency", "Proclamation 1321/2024"],
      ["Encryption at rest", "AES-256"],
      ["Tenant isolation", ""],
      ["Audit trail", ""],
    ],
    cta: ["6-month free trial", "No credit card", "Cancel anytime"],
  },
  am: {
    offline: "ከመስመር ውጪ",
    synced: "ተመሳስሏል",
    checkIn: "መግቢያ",
    payslip: "የደመወዝ ወረቀት",
    gross: "ጠቅላላ",
    tax: "የገቢ ግብር",
    pension: "ጡረታ 7%",
    net: "ተጣሪ",
    approved: "ጸድቋል",
    pending: "በመጠባበቅ ላይ",
    proclamation: "አዋጅ 1395/2025",
    pensionSplit: "ጡረታ: 7% ሰራተኛ + 11% ቀጣሪ",
    leaveBalance: "የፈቃድ ቀሪ",
    kiosk: "ETHR ኪዮስክ",
    kioskHint: "ቁጥርዎን ብቻ ይጻፉ፤ ለ EMP-0012 12 ይጻፉ",
    today: "ዛሬ",
    withEthr: "በETHR",
    trust: [
      ["የውሂብ መኖሪያ", "አዋጅ 1321/2024"],
      ["በእረፍት ጊዜ ምስጠራ", "AES-256"],
      ["የተጠቃሚ ማግለል", ""],
      ["የኦዲት ዱካ", ""],
    ],
    cta: ["6-ወር ነፃ ሙከራ", "ክሬዲት ካርድ አያስፈልግም", "በማንኛውም ጊዜ ይሰርዙ"],
  },
};

// The two words the language drawing flips between.
export const WORD_PAIRS = [
  ["Attendance", "መገኘት"],
  ["Payroll", "ደመወዝ"],
  ["Leave", "ፈቃድ"],
  ["Employees", "ሰራተኞች"],
  ["Approvals", "ማጽደቂያዎች"],
];

export const MONTHS = [
  "መስከረም",
  "ጥቅምት",
  "ኅዳር",
  "ታኅሣሥ",
  "ጥር",
  "የካቲት",
  "መጋቢት",
  "ሚያዝያ",
  "ግንቦት",
  "ሰኔ",
  "ሐምሌ",
  "ነሐሴ",
  "ጳጉሜን",
];

// The carousel: the landing page's six problems, each beside what ETHR does.
export const CAROUSEL = {
  en: {
    cover: {
      over: "For HR teams",
      h: "The work that fills an HR office, and what ETHR does with it",
      p: "Six things that go wrong today, each beside the way ETHR handles it.",
    },
    slides: [
      [
        "Attendance on paper",
        "A shift register is signed late, overtime is argued about at month end, and nobody can say who was on site at 8:02.",
        "Staff check in from a phone, a shared kiosk, a QR code or the web, with the time and place recorded as it happens. Overtime is counted from the record, not from memory.",
      ],
      [
        "Payroll recomputed by hand",
        "Every month someone works the income tax ladder and the pension split in a spreadsheet, and one wrong cell reaches a payslip.",
        "Income tax under Proclamation 1395/2025, pension and Pagume proration are applied automatically, with a calculation trail on every payslip and an approval before anyone sees it.",
      ],
      [
        "Leave balances in spreadsheets",
        "Balances live in a file only one person can open. A request waits for that person, and the Ethiopian calendar is counted by hand.",
        "Employees see their own balance and request leave from a phone. Managers decide from one inbox, and the days are counted on the Ethiopian calendar, Pagume and holidays included.",
      ],
      [
        "Branches without a connection",
        "A regional branch loses the network for an afternoon, and its records for the day go with it.",
        "Check-ins queue on the device and upload themselves the moment the connection returns. Nothing is typed twice.",
      ],
      [
        "One system, two languages",
        "The finance team works in English, the floor supervisors in Amharic, and a system built for one of them leaves the other guessing.",
        "Every screen, payslip and notification is in Amharic and English, with the Ethiopian calendar beside the Gregorian one. Each person chooses their own language.",
      ],
      [
        "Contracts that expire unnoticed",
        "A fixed-term contract ends, nobody is told, and the renewal is signed weeks late.",
        "Every employee has a complete digital file with contracts and documents, and reminders arrive before the expiry date, not after it.",
      ],
    ],
  },
  am: {
    cover: {
      over: "ለሰው ሀብት ቡድኖች",
      h: "የሰው ሀብት ቢሮን የሚሞላው ሥራ፣ እና ETHR በእሱ ላይ የሚያደርገው",
      p: "ዛሬ የሚበላሹ ስድስት ነገሮች፣ እያንዳንዳቸው ETHR ከሚይዛቸው መንገድ ጎን።",
    },
    slides: [
      [
        "በወረቀት ላይ መገኘት",
        "የፈረቃ መዝገብ ዘግይቶ ይፈረማል፣ የትርፍ ሰዓት ክፍያ በወር መጨረሻ ይከራከርበታል፣ በ8:02 ማን በቦታው እንደነበር ማንም አይናገርም።",
        "ሠራተኞች ከስልክ፣ ከጋራ ኪዮስክ፣ በQR ኮድ ወይም ከድር ይገባሉ፤ ሰዓቱና ቦታው ወዲያው ይመዘገባል። የትርፍ ሰዓት ከመዝገቡ ይቆጠራል እንጂ ከትውስታ አይደለም።",
      ],
      [
        "በእጅ የሚሰላ ደመወዝ",
        "በየወሩ አንድ ሰው የገቢ ግብር ደረጃዎችንና የጡረታ ክፍፍልን በስፕሬድሺት ይሠራል፤ አንድ የተሳሳተ ሕዋስ የደመወዝ ወረቀት ላይ ይደርሳል።",
        "በአዋጅ 1395/2025 መሠረት የገቢ ግብር፣ ጡረታና የጳጉሜን ተመጣጣኝ ክፍያ በራስ ሰር ይተገበራሉ፤ በእያንዳንዱ የደመወዝ ወረቀት ላይ የስሌት ዱካ አለ፣ ማንም ከማየቱ በፊትም ይጸድቃል።",
      ],
      [
        "በስፕሬድሺት ውስጥ የፈቃድ ቀሪ",
        "ቀሪዎቹ አንድ ሰው ብቻ በሚከፍተው ፋይል ውስጥ ናቸው። ጥያቄ ያንን ሰው ይጠብቃል፤ የኢትዮጵያ የቀን አቆጣጠርም በእጅ ይቆጠራል።",
        "ሠራተኞች የራሳቸውን ቀሪ ያያሉ፣ ፈቃድን ከስልክ ይጠይቃሉ። ኃላፊዎች ከአንድ ሳጥን ይወስናሉ፤ ቀናቱ ጳጉሜንና በዓላትን ጨምሮ በኢትዮጵያ የቀን አቆጣጠር ይቆጠራሉ።",
      ],
      [
        "ግንኙነት የሌላቸው ቅርንጫፎች",
        "አንድ የክልል ቅርንጫፍ ለአንድ ከሰዓት አውታረ መረብ ያጣል፤ ከእሱም ጋር የዕለቱን መዝገቦች።",
        "መግቢያዎች በመሣሪያው ላይ ይሰለፋሉ፣ ግንኙነቱ እንደተመለሰ ራሳቸው ይላካሉ። ምንም ሁለት ጊዜ አይተየብም።",
      ],
      [
        "አንድ ሥርዓት፣ ሁለት ቋንቋ",
        "የፋይናንስ ቡድኑ በእንግሊዝኛ፣ የወለል ተቆጣጣሪዎች በአማርኛ ይሠራሉ፤ ለአንዱ ብቻ የተሠራ ሥርዓት ሌላውን በግምት ይተዋል።",
        "እያንዳንዱ ገጽ፣ የደመወዝ ወረቀትና ማሳወቂያ በአማርኛና በእንግሊዝኛ ነው፤ የኢትዮጵያ የቀን አቆጣጠር ከጎርጎሪዮሱ ጎን። እያንዳንዱ ሰው የራሱን ቋንቋ ይመርጣል።",
      ],
      [
        "ሳይታወቅ የሚያልቁ ውሎች",
        "የተወሰነ ጊዜ ውል ያበቃል፣ ማንም አይነገረውም፣ እድሳቱም ከሳምንታት በኋላ ይፈረማል።",
        "እያንዳንዱ ሠራተኛ ውሎችንና ሰነዶችን የያዘ ሙሉ ዲጂታል ፋይል አለው፤ ማስታወሻዎቹ ከማብቂያ ቀን በፊት ይደርሳሉ እንጂ በኋላ አይደለም።",
      ],
    ],
  },
};

// Profile banners. One headline, one line under it.
export const BANNER = {
  h: "Attendance, payroll and HR, built for Ethiopian organisations",
  am: "ለኢትዮጵያ ድርጅቶች የተሠራ የመገኘት፣ የደመወዝና የሰው ሀብት መድረክ",
  line: "Works offline · Amharic and English · Data stored in Ethiopia",
};

// Posts built on real screens from the demo organisation (source/screens/).
// `shots` name files in assets/screenshots/; `{lang}` becomes en or am.
// `layout` is one of browser, browsers, phone, phones (render.mjs).
export const SCREEN_POSTS = [
  {
    id: "checkin",
    layout: "phone",
    shots: ["phone-checkin-{lang}"],
    path: "/attendance-system",
    en: { over: "Attendance", h: "Check in, anywhere" },
    am: { over: "መገኘት", h: "የትም ቦታ ይግቡ" },
  },
  {
    id: "payslips",
    layout: "phone",
    shots: ["phone-payslips-{lang}"],
    path: "/payroll-software",
    en: { over: "Payroll", h: "Employees download their payslips from any phone" },
    am: { over: "ደመወዝ", h: "ሠራተኞች የደመወዝ ወረቀታቸውን ከማንኛውም ስልክ ያወርዳሉ" },
  },
  {
    id: "leave",
    layout: "phone",
    shots: ["phone-leave-{lang}"],
    path: "/leave-management",
    en: { over: "Leave", h: "Staff see their balance before they ask" },
    am: { over: "ፈቃድ", h: "ሠራተኞች ከመጠየቃቸው በፊት ቀሪያቸውን ያያሉ" },
  },
  {
    id: "two-languages",
    layout: "phones",
    shots: ["phone-home-en", "phone-home-am"],
    path: "/ethiopian-calendar-hr",
    en: { over: "Amharic and English", h: "One system, two languages" },
    am: { over: "አማርኛና እንግሊዝኛ", h: "አንድ ሥርዓት፣ ሁለት ቋንቋ" },
  },
  {
    id: "employees",
    layout: "browsers",
    shots: ["employees-en", "employees-am"],
    path: "/ethiopian-calendar-hr",
    en: { over: "Amharic and English", h: "Every screen in Amharic or English, chosen by each user" },
    am: { over: "አማርኛና እንግሊዝኛ", h: "እያንዳንዱ ገጽ በአማርኛ ወይም በእንግሊዝኛ፣ እያንዳንዱ ተጠቃሚ በሚመርጠው" },
  },
];
