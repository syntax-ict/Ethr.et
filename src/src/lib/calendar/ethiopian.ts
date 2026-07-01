export interface EthiopianDate {
  year: number;
  month: number;
  day: number;
}

const ETHIOPIAN_MONTHS = [
  "Meskerem",
  "Tikimt",
  "Hidar",
  "Tahsas",
  "Tir",
  "Yekatit",
  "Megabit",
  "Miyazia",
  "Ginbot",
  "Sene",
  "Hamle",
  "Nehase",
  "Pagume",
] as const;

const ETHIOPIAN_MONTHS_AM = [
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
  "ጳጉሜ",
] as const;

const JD_EPOCH_OFFSET_AMETE_MIHRET = 1723856;

function isEthiopianLeapYear(year: number): boolean {
  return year % 4 === 3;
}

function isGregorianLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

function gregorianToJdn(year: number, month: number, day: number): number {
  const a = Math.floor((14 - month) / 12);
  const y = year + 4800 - a;
  const m = month + 12 * a - 3;

  return (
    day +
    Math.floor((153 * m + 2) / 5) +
    365 * y +
    Math.floor(y / 4) -
    Math.floor(y / 100) +
    Math.floor(y / 400) -
    32045
  );
}

function jdnToGregorian(jdn: number): {
  year: number;
  month: number;
  day: number;
} {
  const a = jdn + 32044;
  const b = Math.floor((4 * a + 3) / 146097);
  const c = a - Math.floor((146097 * b) / 4);
  const d = Math.floor((4 * c + 3) / 1461);
  const e = c - Math.floor((1461 * d) / 4);
  const m = Math.floor((5 * e + 2) / 153);

  return {
    day: e - Math.floor((153 * m + 2) / 5) + 1,
    month: m + 3 - 12 * Math.floor(m / 10),
    year: 100 * b + d - 4800 + Math.floor(m / 10),
  };
}

export function toEthiopian(
  year: number,
  month: number,
  day: number,
): EthiopianDate {
  const jdn = gregorianToJdn(year, month, day);
  const r = (jdn - JD_EPOCH_OFFSET_AMETE_MIHRET) % 1461;
  const n = (r % 365) + 365 * Math.floor(r / 1460);

  const ethYear =
    4 * Math.floor((jdn - JD_EPOCH_OFFSET_AMETE_MIHRET) / 1461) +
    Math.floor(r / 365) -
    Math.floor(r / 1460);
  const ethMonth = Math.floor(n / 30) + 1;
  const ethDay = (n % 30) + 1;

  return { year: ethYear, month: ethMonth, day: ethDay };
}

export function toGregorian(
  ethYear: number,
  ethMonth: number,
  ethDay: number,
): Date {
  const jdn =
    JD_EPOCH_OFFSET_AMETE_MIHRET +
    365 * ethYear +
    Math.floor(ethYear / 4) +
    30 * (ethMonth - 1) +
    ethDay -
    1;

  const greg = jdnToGregorian(jdn);
  return new Date(greg.year, greg.month - 1, greg.day);
}

export function formatEthiopian(
  date: EthiopianDate,
  locale: string = "en",
): string {
  const months = locale === "am" ? ETHIOPIAN_MONTHS_AM : ETHIOPIAN_MONTHS;
  const monthName = months[date.month - 1] ?? `Month ${date.month}`;
  return `${monthName} ${date.day}, ${date.year}`;
}

export function todayEthiopian(): EthiopianDate {
  const now = new Date();
  return toEthiopian(now.getFullYear(), now.getMonth() + 1, now.getDate());
}

export {
  ETHIOPIAN_MONTHS,
  ETHIOPIAN_MONTHS_AM,
  isEthiopianLeapYear,
  isGregorianLeapYear,
};
