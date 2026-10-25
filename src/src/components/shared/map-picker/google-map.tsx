"use client";

import { useEffect, useRef, useState } from "react";
import type { MapViewProps } from "./leaflet-map";
import { DEFAULT_CENTRE } from "./leaflet-map";

/*
 * Google Maps, used only when the platform has a Maps JavaScript API key
 * (platform console). The key is a browser key by Google's design, limited
 * by referrer on Google's side; it is not a secret. Loaded on demand, once.
 */

// The loader's minimal surface, typed here rather than pulling in
// @types/google.maps for four calls.
interface GLatLng {
  lat(): number;
  lng(): number;
}
interface GMapsApi {
  Map: new (
    el: HTMLElement,
    opts: object,
  ) => {
    setCenter(c: object): void;
    setZoom(z: number): void;
    getZoom(): number;
    addListener(e: string, cb: (ev: { latLng: GLatLng }) => void): void;
  };
  Marker: new (opts: object) => {
    setPosition(p: object | null): void;
    setMap(m: unknown): void;
    addListener(e: string, cb: (ev: { latLng: GLatLng }) => void): void;
  };
  Circle: new (opts: object) => {
    setCenter(p: object | null): void;
    setRadius(r: number): void;
    setMap(m: unknown): void;
  };
}

let loading: Promise<GMapsApi> | null = null;

function loadGoogle(key: string): Promise<GMapsApi> {
  const w = window as unknown as { google?: { maps?: GMapsApi } };
  if (w.google?.maps) return Promise.resolve(w.google.maps);
  loading ??= new Promise<GMapsApi>((resolve, reject) => {
    const script = document.createElement("script");
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly`;
    script.async = true;
    script.onload = () =>
      w.google?.maps ? resolve(w.google.maps) : reject(new Error("maps"));
    script.onerror = () => {
      loading = null;
      reject(new Error("maps"));
    };
    document.head.appendChild(script);
  });
  return loading;
}

export default function GoogleMap({
  point,
  radius,
  onPick,
  className,
  apiKey,
  onUnavailable,
}: MapViewProps & { apiKey: string; onUnavailable: () => void }) {
  const el = useRef<HTMLDivElement>(null);
  const [api, setApi] = useState<GMapsApi | null>(null);
  const map = useRef<InstanceType<GMapsApi["Map"]> | null>(null);
  const marker = useRef<InstanceType<GMapsApi["Marker"]> | null>(null);
  const circle = useRef<InstanceType<GMapsApi["Circle"]> | null>(null);
  const pick = useRef(onPick);
  useEffect(() => {
    pick.current = onPick;
  }, [onPick]);

  useEffect(() => {
    let alive = true;
    loadGoogle(apiKey)
      .then((g) => alive && setApi(g))
      .catch(() => alive && onUnavailable());
    return () => {
      alive = false;
    };
  }, [apiKey, onUnavailable]);

  useEffect(() => {
    if (!api || !el.current || map.current) return;
    const centre = point ?? DEFAULT_CENTRE;
    map.current = new api.Map(el.current, {
      center: centre,
      zoom: point ? 16 : 6,
      mapTypeControl: true,
      streetViewControl: false,
      gestureHandling: "cooperative",
    });
    marker.current = new api.Marker({ map: map.current, draggable: true });
    circle.current = new api.Circle({
      map: map.current,
      strokeWeight: 2,
      fillOpacity: 0.15,
    });
    const report = (ev: { latLng: GLatLng }) =>
      pick.current?.({ lat: ev.latLng.lat(), lng: ev.latLng.lng() });
    map.current.addListener("click", report);
    marker.current.addListener("dragend", report);
  }, [api, point]);

  useEffect(() => {
    if (!map.current || !marker.current || !circle.current) return;
    const p = point ? { lat: point.lat, lng: point.lng } : null;
    marker.current.setPosition(p);
    circle.current.setCenter(p);
    circle.current.setRadius(radius ?? 0);
    if (p) {
      map.current.setCenter(p);
      map.current.setZoom(Math.max(map.current.getZoom(), 16));
    }
  }, [point, radius, api]);

  return (
    <div
      ref={el}
      className={className}
      style={{ height: "100%", width: "100%" }}
    />
  );
}
