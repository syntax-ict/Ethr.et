import { describe, expect, it } from "vitest";
import { parseLocation } from "@/lib/geo/parse-location";

describe("parseLocation", () => {
  it("reads the pin from a Google Maps place link before the view centre", () => {
    expect(
      parseLocation(
        "https://www.google.com/maps/place/Meskel+Square/@9.0105,38.7610,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d9.0108!4d38.7613",
      ),
    ).toEqual({ lat: 9.0108, lng: 38.7613 });
  });

  it("reads a Google Maps link by its view centre or query", () => {
    expect(
      parseLocation("https://www.google.com/maps/@9.0301,38.7409,15z"),
    ).toEqual({
      lat: 9.0301,
      lng: 38.7409,
    });
    expect(parseLocation("https://maps.google.com/?q=8.9806,38.7578")).toEqual({
      lat: 8.9806,
      lng: 38.7578,
    });
    expect(
      parseLocation(
        "https://www.google.com/maps/search/?api=1&query=9.6,41.86",
      ),
    ).toEqual({ lat: 9.6, lng: 41.86 });
  });

  it("reads OpenStreetMap links and geo: URIs", () => {
    expect(
      parseLocation(
        "https://www.openstreetmap.org/?mlat=9.0108&mlon=38.7613#map=17/9.0108/38.7613",
      ),
    ).toEqual({ lat: 9.0108, lng: 38.7613 });
    expect(
      parseLocation("https://www.openstreetmap.org/#map=16/11.5936/37.3908"),
    ).toEqual({ lat: 11.5936, lng: 37.3908 });
    expect(parseLocation("geo:13.4967,39.4753")).toEqual({
      lat: 13.4967,
      lng: 39.4753,
    });
  });

  it("reads a bare coordinate pair", () => {
    expect(parseLocation(" 9.0108, 38.7613 ")).toEqual({
      lat: 9.0108,
      lng: 38.7613,
    });
    expect(parseLocation("7.0621 38.4764")).toEqual({
      lat: 7.0621,
      lng: 38.4764,
    });
  });

  it("refuses text that is not a place", () => {
    expect(parseLocation("")).toBeNull();
    expect(parseLocation("Bole, Addis Ababa")).toBeNull();
    expect(parseLocation("95, 38")).toBeNull();
    expect(parseLocation("0,0")).toBeNull();
    // A short link must be opened first: it carries no coordinates.
    expect(parseLocation("https://maps.app.goo.gl/AbCdEf")).toBeNull();
  });
});
