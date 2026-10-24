/**
 * A place someone pasted: a Google Maps link, an OpenStreetMap link, or a
 * plain "latitude, longitude" pair. Parsed locally, with no network call,
 * so an admin can copy a pin from the Maps app on their phone and paste it
 * into the branch form without any map key.
 *
 * Returns null when nothing in the text is a usable coordinate pair.
 */
export interface LatLng {
  lat: number;
  lng: number;
}

const PATTERNS: RegExp[] = [
  // Google: the place pin, which is more exact than the view centre.
  /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/,
  // Google: ?q=9.01,38.76 / ?query= / ?ll= / ?center= / ?destination=
  /[?&](?:q|query|ll|center|destination|daddr)=(-?\d+(?:\.\d+)?)(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i,
  // Google: /@9.01,38.76,17z (the view centre)
  /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/,
  // OpenStreetMap: ?mlat=9.01&mlon=38.76
  /[?&]mlat=(-?\d+(?:\.\d+)?)&mlon=(-?\d+(?:\.\d+)?)/,
  // OpenStreetMap: #map=17/9.01/38.76
  /#map=\d+\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/,
  // geo:9.01,38.76
  /^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i,
  // A bare pair: "9.0108, 38.7613" or "9.0108 38.7613"
  /^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/,
];

export function parseLocation(text: string): LatLng | null {
  const input = text.trim();
  if (input === "") return null;

  for (const pattern of PATTERNS) {
    const match = input.match(pattern);
    if (!match) continue;

    const lat = Number(match[1]);
    const lng = Number(match[2]);
    if (isValid(lat, lng)) {
      return { lat: round(lat), lng: round(lng) };
    }
  }

  return null;
}

function isValid(lat: number, lng: number): boolean {
  return (
    Number.isFinite(lat) &&
    Number.isFinite(lng) &&
    Math.abs(lat) <= 90 &&
    Math.abs(lng) <= 180 &&
    !(lat === 0 && lng === 0)
  );
}

/** Seven decimals is about 1 cm: the precision the columns store. */
function round(value: number): number {
  return Math.round(value * 1e7) / 1e7;
}
