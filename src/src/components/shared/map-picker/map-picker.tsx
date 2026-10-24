"use client";

import dynamic from "next/dynamic";
import { useCallback, useState } from "react";
import { Crosshair, Loader2, MapPin, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { parseLocation, type LatLng } from "@/lib/geo/parse-location";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

// Both maps touch `window`, so they load in the browser only: the frontend
// is a static export and is never rendered on a server.
const LeafletMap = dynamic(() => import("./leaflet-map"), {
  ssr: false,
  loading: () => <Skeleton className="h-full w-full" />,
});
const GoogleMap = dynamic(() => import("./google-map"), {
  ssr: false,
  loading: () => <Skeleton className="h-full w-full" />,
});

export interface PlaceValue {
  latitude: number | null;
  longitude: number | null;
  radius: number;
}

interface SearchHit {
  display_name: string;
  lat: string;
  lon: string;
}

/**
 * Put a branch or a work site on the map, and draw the area a punch must fall
 * inside. Four ways in, so it works for whoever is setting it up:
 *
 *  - click or drag the pin on the map;
 *  - "Use my current location" while standing at the place;
 *  - paste a Google Maps or OpenStreetMap link, or "latitude, longitude";
 *  - search an address (OpenStreetMap's Nominatim, admin-only, on demand).
 *
 * OpenStreetMap is the map. Google Maps is offered beside it only when the
 * platform has a Maps key; nothing is sent to Google otherwise.
 */
export function MapPicker({
  value,
  onChange,
  googleMapsKey,
  minRadius = 10,
  maxRadius = 10000,
  className,
}: {
  value: PlaceValue;
  onChange: (next: PlaceValue) => void;
  googleMapsKey?: string | null;
  minRadius?: number;
  maxRadius?: number;
  className?: string;
}) {
  const { t } = useT();
  const [provider, setProvider] = useState<"osm" | "google">("osm");
  const [paste, setPaste] = useState("");
  const [pasteError, setPasteError] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [hits, setHits] = useState<SearchHit[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [locating, setLocating] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  const point: LatLng | null =
    value.latitude !== null && value.longitude !== null
      ? { lat: value.latitude, lng: value.longitude }
      : null;

  const place = useCallback(
    (p: LatLng) =>
      onChange({
        ...value,
        latitude: Math.round(p.lat * 1e7) / 1e7,
        longitude: Math.round(p.lng * 1e7) / 1e7,
      }),
    [onChange, value],
  );

  function placePasted() {
    const parsed = parseLocation(paste);
    if (!parsed) {
      setPasteError(
        t(
          "map_picker.paste_invalid",
          "No location found. Paste a Google Maps link (open short links first) or latitude, longitude.",
        ),
      );
      return;
    }
    setPasteError(null);
    setPaste("");
    place(parsed);
  }

  function useHere() {
    if (!("geolocation" in navigator)) {
      setNote(t("map_picker.no_gps", "This device cannot share its location."));
      return;
    }
    setLocating(true);
    setNote(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        place({ lat: pos.coords.latitude, lng: pos.coords.longitude });
        const accuracy = Math.round(pos.coords.accuracy);
        setNote(
          accuracy > 50
            ? t(
                "map_picker.weak_fix",
                "Placed within :m m. Move outside or wait a moment and try again for a closer fix.",
                { m: accuracy },
              )
            : t("map_picker.good_fix", "Placed within :m m.", { m: accuracy }),
        );
      },
      () => {
        setLocating(false);
        setNote(
          t(
            "map_picker.gps_denied",
            "Location was not shared. Allow location for this site and try again.",
          ),
        );
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  }

  async function search() {
    const q = query.trim();
    if (q.length < 3) return;
    setSearching(true);
    try {
      const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=et&q=${encodeURIComponent(q)}`;
      const res = await fetch(url, { headers: { Accept: "application/json" } });
      setHits(res.ok ? ((await res.json()) as SearchHit[]) : []);
    } catch {
      setHits([]);
    } finally {
      setSearching(false);
    }
  }

  const fallbackToOsm = useCallback(() => {
    setProvider("osm");
    setNote(
      t(
        "map_picker.google_failed",
        "Google Maps did not load, so OpenStreetMap is shown.",
      ),
    );
  }, [t]);

  return (
    <div className={cn("space-y-3", className)}>
      {googleMapsKey && (
        <div
          className="inline-flex rounded-md border border-border p-0.5 text-xs"
          role="group"
          aria-label={t("map_picker.provider", "Map")}
        >
          {(["osm", "google"] as const).map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => setProvider(p)}
              aria-pressed={provider === p}
              className={cn(
                "rounded px-2.5 py-1",
                provider === p
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground",
              )}
            >
              {p === "osm" ? "OpenStreetMap" : "Google Maps"}
            </button>
          ))}
        </div>
      )}

      <div className="h-72 overflow-hidden rounded-md border border-border">
        {provider === "google" && googleMapsKey ? (
          <GoogleMap
            point={point}
            radius={value.radius}
            onPick={place}
            apiKey={googleMapsKey}
            onUnavailable={fallbackToOsm}
          />
        ) : (
          <LeafletMap point={point} radius={value.radius} onPick={place} />
        )}
      </div>
      <p className="text-xs text-muted-foreground">
        {t(
          "map_picker.hint",
          "Click the map or drag the pin. The circle is the area a punch must be inside.",
        )}
      </p>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={useHere}
          disabled={locating}
        >
          {locating ? (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          ) : (
            <Crosshair className="mr-2 h-4 w-4" />
          )}
          {t("map_picker.use_here", "Use my current location")}
        </Button>
      </div>
      {note && <p className="text-xs text-muted-foreground">{note}</p>}

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <Label htmlFor="map-paste" className="text-xs">
            {t("map_picker.paste", "Paste a map link or coordinates")}
          </Label>
          <div className="mt-1 flex gap-2">
            <Input
              id="map-paste"
              value={paste}
              onChange={(e) => setPaste(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  placePasted();
                }
              }}
              placeholder="9.0108, 38.7613"
            />
            <Button
              type="button"
              variant="outline"
              size="icon"
              onClick={placePasted}
              aria-label={t("map_picker.place", "Place it")}
            >
              <MapPin className="h-4 w-4" />
            </Button>
          </div>
          {pasteError && (
            <p className="mt-1 text-xs text-destructive">{pasteError}</p>
          )}
        </div>
        <div>
          <Label htmlFor="map-search" className="text-xs">
            {t("map_picker.search", "Search an address")}
          </Label>
          <div className="mt-1 flex gap-2">
            <Input
              id="map-search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  void search();
                }
              }}
            />
            <Button
              type="button"
              variant="outline"
              size="icon"
              onClick={() => void search()}
              disabled={searching}
              aria-label={t("map_picker.search", "Search an address")}
            >
              {searching ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Search className="h-4 w-4" />
              )}
            </Button>
          </div>
          {hits !== null && (
            <ul className="mt-1 max-h-40 overflow-y-auto rounded-md border border-border text-xs">
              {hits.length === 0 ? (
                <li className="px-2 py-1.5 text-muted-foreground">
                  {t("map_picker.no_results", "Nothing found.")}
                </li>
              ) : (
                hits.map((hit) => (
                  <li key={`${hit.lat},${hit.lon}`}>
                    <button
                      type="button"
                      className="w-full px-2 py-1.5 text-left hover:bg-muted"
                      onClick={() => {
                        place({ lat: Number(hit.lat), lng: Number(hit.lon) });
                        setHits(null);
                      }}
                    >
                      {hit.display_name}
                    </button>
                  </li>
                ))
              )}
            </ul>
          )}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <div>
          <Label htmlFor="map-lat" className="text-xs">
            {t("map_picker.latitude", "Latitude")}
          </Label>
          <Input
            id="map-lat"
            type="number"
            step="any"
            value={value.latitude ?? ""}
            onChange={(e) =>
              onChange({
                ...value,
                latitude: e.target.value === "" ? null : Number(e.target.value),
              })
            }
          />
        </div>
        <div>
          <Label htmlFor="map-lng" className="text-xs">
            {t("map_picker.longitude", "Longitude")}
          </Label>
          <Input
            id="map-lng"
            type="number"
            step="any"
            value={value.longitude ?? ""}
            onChange={(e) =>
              onChange({
                ...value,
                longitude:
                  e.target.value === "" ? null : Number(e.target.value),
              })
            }
          />
        </div>
        <div>
          <Label htmlFor="map-radius" className="text-xs">
            {t("map_picker.radius", "Area radius (metres)")}
          </Label>
          <Input
            id="map-radius"
            type="number"
            min={minRadius}
            max={maxRadius}
            value={value.radius}
            onChange={(e) =>
              onChange({
                ...value,
                radius: Math.min(
                  maxRadius,
                  Math.max(minRadius, Number(e.target.value) || minRadius),
                ),
              })
            }
          />
        </div>
      </div>
    </div>
  );
}
