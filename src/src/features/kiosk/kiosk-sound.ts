"use client";

import { useCallback, useEffect, useRef } from "react";

/**
 * What the shared kiosk says when someone punches. A person who types their
 * code and turns away does not see a refusal on the screen, and the gate or
 * workshop floor may be too loud, or the reader may not read, for the screen
 * alone. Sound is a second channel, never the only one: every cue has its
 * message on the screen too.
 *
 *   - `tones`: a short pattern per result, made in the browser, so there is
 *     nothing to download and it works offline;
 *   - `voice`: a few words in the kiosk's own display language. A tablet with
 *     no voice for that language plays the tones instead. It never falls back
 *     to another language: an Amharic kiosk does not speak English.
 *
 * No cue carries a name, or says which of the code or the PIN was wrong: the
 * queue hears everything the terminal says.
 */
export type KioskSoundMode = "off" | "tones" | "voice";

export type KioskCue =
  | "check_in"
  | "check_out"
  | "saved"
  | "duplicate"
  | "reported"
  | "error"
  | "locked";

/** The dictionary key each cue speaks in `voice` mode. */
export const CUE_PHRASE: Record<KioskCue, string> = {
  check_in: "kiosk_page.say_check_in",
  check_out: "kiosk_page.say_check_out",
  saved: "kiosk_page.say_saved",
  duplicate: "kiosk_page.say_duplicate",
  reported: "kiosk_page.say_reported",
  error: "kiosk_page.say_error",
  locked: "kiosk_page.say_locked",
};

interface Note {
  hz: number;
  ms: number;
  wave: OscillatorType;
}

const DONE: Note[] = [
  { hz: 660, ms: 90, wave: "sine" },
  { hz: 990, ms: 140, wave: "sine" },
];
const BUZZ: Note = { hz: 220, ms: 170, wave: "triangle" };

/**
 * One pattern per meaning, told apart without looking: a rising chirp is
 * done, a single tone is "nothing new", low buzzes are "not done", and three
 * of them mean the person needs HR rather than another try.
 */
export const CUE_TONES: Record<KioskCue, Note[]> = {
  check_in: DONE,
  check_out: DONE,
  saved: DONE,
  duplicate: [{ hz: 520, ms: 180, wave: "sine" }],
  reported: [{ hz: 440, ms: 140, wave: "sine" }],
  error: [BUZZ, BUZZ],
  locked: [BUZZ, BUZZ, BUZZ],
};

const GAP_MS = 70;

type AudioContextCtor = typeof AudioContext;

function audioContextCtor(): AudioContextCtor | null {
  if (typeof window === "undefined") return null;
  // Older Safari on iPad has only the prefixed name.
  const w = window as Window &
    typeof globalThis & { webkitAudioContext?: AudioContextCtor };
  return w.AudioContext ?? w.webkitAudioContext ?? null;
}

function playTones(ctx: AudioContext, notes: Note[]): void {
  let at = ctx.currentTime + 0.01;
  for (const note of notes) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    const length = note.ms / 1000;
    osc.type = note.wave;
    osc.frequency.setValueAtTime(note.hz, at);
    // A short ramp in and out: a note that starts or stops at full volume
    // clicks.
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(0.35, at + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(at);
    osc.stop(at + length + 0.02);
    at += length + GAP_MS / 1000;
  }
}

/**
 * The installed voice for `locale`, or null. "am" matches "am" and "am-ET";
 * nothing else, so a missing Amharic voice is never replaced by an English
 * one.
 */
export function voiceFor(
  voices: readonly SpeechSynthesisVoice[],
  locale: string,
): SpeechSynthesisVoice | null {
  const want = locale.toLowerCase();
  return (
    voices.find((v) => {
      const lang = v.lang.toLowerCase().replace("_", "-");
      return lang === want || lang.startsWith(`${want}-`);
    }) ?? null
  );
}

function speechSynthesisOrNull(): SpeechSynthesis | null {
  if (typeof window === "undefined" || !("speechSynthesis" in window)) {
    return null;
  }
  return typeof SpeechSynthesisUtterance === "function"
    ? window.speechSynthesis
    : null;
}

/**
 * `play(cue)` for the kiosk. Browsers keep sound off until the page has been
 * touched; every kiosk use starts with a tap, and the first one switches it
 * on.
 */
export function useKioskSound(
  mode: KioskSoundMode,
  locale: string,
  t: (key: string) => string,
): (cue: KioskCue) => void {
  const ctxRef = useRef<AudioContext | null>(null);
  const voiceRef = useRef<SpeechSynthesisVoice | null>(null);

  useEffect(() => {
    if (mode === "off") return;
    const unlock = () => {
      const Ctor = audioContextCtor();
      if (!Ctor) return;
      if (ctxRef.current === null) {
        try {
          ctxRef.current = new Ctor();
        } catch {
          return;
        }
      }
      if (ctxRef.current.state === "suspended") {
        void ctxRef.current.resume().catch(() => {});
      }
    };
    window.addEventListener("pointerdown", unlock, true);
    window.addEventListener("keydown", unlock, true);
    return () => {
      window.removeEventListener("pointerdown", unlock, true);
      window.removeEventListener("keydown", unlock, true);
    };
  }, [mode]);

  // Voices arrive late on some browsers: the list is empty until
  // `voiceschanged`.
  useEffect(() => {
    voiceRef.current = null;
    const synth = speechSynthesisOrNull();
    if (mode !== "voice" || synth === null) return;
    const pick = () => {
      voiceRef.current = voiceFor(synth.getVoices(), locale);
    };
    pick();
    synth.addEventListener?.("voiceschanged", pick);
    return () => synth.removeEventListener?.("voiceschanged", pick);
  }, [mode, locale]);

  useEffect(
    () => () => {
      void ctxRef.current?.close().catch(() => {});
      ctxRef.current = null;
    },
    [],
  );

  return useCallback(
    (cue: KioskCue) => {
      if (mode === "off") return;
      const synth = speechSynthesisOrNull();
      const voice = voiceRef.current;
      if (mode === "voice" && synth !== null && voice !== null) {
        synth.cancel();
        const utterance = new SpeechSynthesisUtterance(t(CUE_PHRASE[cue]));
        utterance.voice = voice;
        utterance.lang = voice.lang;
        synth.speak(utterance);
        return;
      }
      const ctx = ctxRef.current;
      if (ctx === null || ctx.state !== "running") return;
      try {
        playTones(ctx, CUE_TONES[cue]);
      } catch {
        // A sound card that fails is not a reason to fail the punch.
      }
    },
    [mode, t],
  );
}
