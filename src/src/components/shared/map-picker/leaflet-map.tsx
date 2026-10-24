"use client";

import "leaflet/dist/leaflet.css";
import { useEffect, useMemo } from "react";
import L from "leaflet";
import {
  Circle,
  MapContainer,
  Marker,
  TileLayer,
  useMap,
  useMapEvents,
} from "react-leaflet";
import type { LatLng } from "@/lib/geo/parse-location";

/** Addis Ababa, for a map with nothing placed yet. */
export const DEFAULT_CENTRE: LatLng = { lat: 9.0301, lng: 38.7469 };

export interface MapViewProps {
  point: LatLng | null;
  radius: number | null;
  onPick?: (point: LatLng) => void;
  /** Extra circles drawn read-only, e.g. other sites. */
  others?: { point: LatLng; radius: number; label: string }[];
  className?: string;
}

/*
 * A drawn pin rather than Leaflet's default marker image: the default loads
 * its PNG by a CSS url the bundler does not rewrite, so it renders broken.
 */
const pin = L.divIcon({
  className: "",
  html: '<span style="display:block;width:18px;height:18px;border-radius:9999px;background:var(--color-interactive-primary,#0F4C75);border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></span>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
});

function Recentre({ point }: { point: LatLng | null }) {
  const map = useMap();
  useEffect(() => {
    if (point) map.setView([point.lat, point.lng], Math.max(map.getZoom(), 16));
  }, [map, point]);
  return null;
}

function ClickToPick({ onPick }: { onPick?: (point: LatLng) => void }) {
  useMapEvents({
    click(e) {
      onPick?.({ lat: e.latlng.lat, lng: e.latlng.lng });
    },
  });
  return null;
}

/** OpenStreetMap through Leaflet: free, no key, raster tiles as images. */
export default function LeafletMap({
  point,
  radius,
  onPick,
  others = [],
  className,
}: MapViewProps) {
  const centre = point ?? DEFAULT_CENTRE;
  const markerHandlers = useMemo(
    () => ({
      dragend(e: L.LeafletEvent) {
        const ll = (e.target as L.Marker).getLatLng();
        onPick?.({ lat: ll.lat, lng: ll.lng });
      },
    }),
    [onPick],
  );

  return (
    <MapContainer
      center={[centre.lat, centre.lng]}
      zoom={point ? 16 : 6}
      scrollWheelZoom={false}
      className={className}
      style={{ height: "100%", width: "100%" }}
    >
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
        maxZoom={19}
      />
      {others.map((o) => (
        <Circle
          key={`${o.label}-${o.point.lat}-${o.point.lng}`}
          center={[o.point.lat, o.point.lng]}
          radius={o.radius}
          pathOptions={{ weight: 1, dashArray: "4", fillOpacity: 0.05 }}
        />
      ))}
      {point && (
        <>
          <Marker
            position={[point.lat, point.lng]}
            icon={pin}
            draggable={Boolean(onPick)}
            eventHandlers={markerHandlers}
          />
          {radius !== null && radius > 0 && (
            <Circle
              center={[point.lat, point.lng]}
              radius={radius}
              pathOptions={{ weight: 2, fillOpacity: 0.15 }}
            />
          )}
        </>
      )}
      <Recentre point={point} />
      <ClickToPick onPick={onPick} />
    </MapContainer>
  );
}
