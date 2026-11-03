import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import {
  CUE_PHRASE,
  CUE_TONES,
  useKioskSound,
  voiceFor,
  type KioskSoundMode,
} from "@/features/kiosk/kiosk-sound";

/**
 * The kiosk's sound: tones by default, words in the kiosk's own language
 * when the tablet has a voice for it, and never a voice in another language.
 */

const voice = (lang: string) => ({ lang, name: lang }) as SpeechSynthesisVoice;

let oscillators = 0;
let spoken: { text: string; lang: string }[] = [];
let voices: SpeechSynthesisVoice[] = [];

class FakeAudioContext {
  state: AudioContextState = "suspended";
  currentTime = 0;
  destination = {};
  resume() {
    this.state = "running";
    return Promise.resolve();
  }
  close() {
    return Promise.resolve();
  }
  createOscillator() {
    oscillators += 1;
    return {
      type: "sine",
      frequency: { setValueAtTime: () => {} },
      connect: (node: unknown) => node,
      start: () => {},
      stop: () => {},
    };
  }
  createGain() {
    const gain = {
      gain: {
        setValueAtTime: () => {},
        exponentialRampToValueAtTime: () => {},
      },
      connect: (node: unknown) => node,
    };
    return gain;
  }
}

class FakeUtterance {
  voice: SpeechSynthesisVoice | null = null;
  lang = "";
  constructor(public text: string) {}
}

beforeEach(() => {
  oscillators = 0;
  spoken = [];
  voices = [];
  vi.stubGlobal("AudioContext", FakeAudioContext);
  vi.stubGlobal("SpeechSynthesisUtterance", FakeUtterance);
  Object.defineProperty(window, "speechSynthesis", {
    configurable: true,
    value: {
      getVoices: () => voices,
      cancel: () => {},
      speak: (u: FakeUtterance) => spoken.push({ text: u.text, lang: u.lang }),
      addEventListener: () => {},
      removeEventListener: () => {},
    },
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const t = (key: string) => `said:${key}`;

async function hook(mode: KioskSoundMode, locale: string) {
  const { result } = renderHook(() => useKioskSound(mode, locale, t));
  // Browsers keep sound off until the page is touched.
  await act(async () => {
    window.dispatchEvent(new Event("pointerdown"));
  });
  return result.current;
}

describe("voiceFor", () => {
  it("matches the language with or without a region, and nothing else", () => {
    expect(voiceFor([voice("en-US"), voice("am-ET")], "am")?.lang).toBe(
      "am-ET",
    );
    expect(voiceFor([voice("am")], "am")?.lang).toBe("am");
    expect(voiceFor([voice("en-US")], "am")).toBeNull();
    // "amh" is not "am", and an English voice is never a stand-in.
    expect(voiceFor([voice("amh-ET")], "am")).toBeNull();
  });
});

describe("useKioskSound", () => {
  it("plays nothing when the organisation turned sound off", async () => {
    const play = await hook("off", "en");
    play("error");
    expect(oscillators).toBe(0);
    expect(spoken).toEqual([]);
  });

  it("plays a different pattern for done, refused and locked", async () => {
    const play = await hook("tones", "en");
    play("check_in");
    expect(oscillators).toBe(CUE_TONES.check_in.length);
    play("error");
    expect(oscillators).toBe(
      CUE_TONES.check_in.length + CUE_TONES.error.length,
    );
    expect(CUE_TONES.locked.length).toBeGreaterThan(CUE_TONES.error.length);
    expect(spoken).toEqual([]);
  });

  it("speaks Amharic on an Amharic kiosk that has an Amharic voice", async () => {
    voices = [voice("en-US"), voice("am-ET")];
    const play = await hook("voice", "am");
    play("check_out");
    expect(spoken).toEqual([
      { text: `said:${CUE_PHRASE.check_out}`, lang: "am-ET" },
    ]);
    expect(oscillators).toBe(0);
  });

  it("plays tones, not English, on an Amharic kiosk with no Amharic voice", async () => {
    voices = [voice("en-US")];
    const play = await hook("voice", "am");
    play("locked");
    expect(spoken).toEqual([]);
    expect(oscillators).toBe(CUE_TONES.locked.length);
  });

  it("has a phrase for every cue, and none of them names a person", () => {
    expect(Object.keys(CUE_PHRASE).sort()).toEqual(
      Object.keys(CUE_TONES).sort(),
    );
    for (const key of Object.values(CUE_PHRASE)) {
      expect(key).toMatch(/^kiosk_page\.say_/);
    }
  });
});
