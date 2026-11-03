# Calendar

A four-week launch, then a monthly rhythm, then the dates in the Ethiopian year worth planning around. File names refer to [`assets/`](assets/); captions are in the [copy bank](copy-bank.md) under the same pillar.

## Launch: four weeks

Three posts a week on the priority channels (Telegram, Facebook, LinkedIn), the same image in the language each channel leads with. Instagram takes the posts and stories; X takes the link cards. Post on weekday mornings, 8:30 to 10:00 Addis Ababa time, when HR offices open their phones before the day starts.

Before day one: profiles complete ([`profiles.md`](profiles.md)), the trial post pinned on every channel, and one person named to answer comments within a working day.

| Week | Day | Pillar | Telegram, Facebook | LinkedIn | Instagram | X |
|---|---|---|---|---|---|---|
| 1 | Mon | Trial, as the introduction | `links/trial-am` | `posts/trial-en` | `posts/trial-am` + `stories/trial-am` | `links/trial-en` |
| 1 | Wed | Offline attendance | `posts/offline-am` | `posts/offline-en` | `posts/offline-am` + `stories/offline-am` | `links/offline-en` |
| 1 | Fri | Two languages | `posts/language-am` | `posts/language-en` | `posts/language-am` | `links/language-en` |
| 2 | Mon | The carousel | `carousel-am/` | `carousel-en/` as a PDF | `carousel-am/` | `links/trial-en` |
| 2 | Wed | Payroll | `posts/payroll-am` | `posts/payroll-en` | `posts/payroll-am` + `stories/payroll-am` | `links/payroll-en` |
| 2 | Fri | Video 1, offline check-in | Video, Amharic captions | Video, English captions | Reel | Video |
| 3 | Mon | Ethiopian calendar | `posts/calendar-am` | `posts/calendar-en` | `posts/calendar-am` + `stories/calendar-am` | `links/calendar-en` |
| 3 | Wed | Data in Ethiopia | `posts/trust-am` | `posts/trust-en` | `posts/trust-am` | `links/trust-en` |
| 3 | Fri | Video 2, payslip on a phone | Video | Video | Reel | Video |
| 4 | Mon | Leave | `posts/leave-am` | `posts/leave-en` | `posts/leave-am` | `links/leave-en` |
| 4 | Wed | Kiosk | `posts/kiosk-am` | `posts/kiosk-en` | `posts/kiosk-am` | `links/kiosk-en` |
| 4 | Fri | Trial, as the close | `posts/trial-am` | `carousel-en/08-trial` | `stories/trial-am` | `links/trial-en` |

On Facebook, post the Amharic image with the bilingual caption. On Telegram, post the link card for link posts (it previews better than a 4:5 image in a channel) and the 4:5 post otherwise.

## After launch: every month

| Week of the month | Post |
|---|---|
| 1 | One pillar post, rotating through the eight |
| 2 | One video from [`video-scripts.md`](video-scripts.md), or a walkthrough recorded from the demo organisation |
| 3 | **What changed in ETHR this month**: one feature that shipped, in one sentence each language, with a screenshot from the demo organisation. Take it from `CHANGELOG.md` and the merged pull requests, and only once it is live on www.ethr.et |
| 4 | **Payroll week.** Most organisations run payroll at the end of the month: repost the payroll pillar or a payslip video in the days before |

Keep the trial post pinned. Repost the carousel once a quarter.

**Example "what changed" posts**, from the kiosk and calendar work merged in late October and early November 2026. All three were live on 3 November 2026: `www.ethr.et/release.json` reported commit `2c4b253` (#542). For later features, read that file and confirm the feature's commit is at or before the one it reports:

> At the ETHR kiosk, staff now type only their number: 12 for EMP-0012. The kiosk finds the full code.

> The kiosk now answers every punch with a sound, in the kiosk's own language, and shows who punched.

> Every screen now follows the reader's own calendar and clock, Ethiopian time of day included.

## Dates in the Ethiopian year

Plan these a fortnight ahead. A holiday greeting is a plain image: navy background, the tibeb band, the greeting in Amharic and English, the logo small in a corner, and no product pitch. Gregorian dates move by a day after a six-day Pagume; check the year's calendar before scheduling.

| When | Moment | What to post |
|---|---|---|
| Late Nehase, before Pagume (end of August) | Pagume approaches | The calendar pillar: Pagume counts in payroll and leave |
| 11 September (12 after a six-day Pagume, as in 2027) | Ethiopian New Year, Enkutatash | Greeting; the calendar post again in the week after |
| 27 September (28 in those years) | Meskel | Greeting |
| 7 January | Genna | Greeting |
| 19 January (20 in those years) | Timket | Greeting |
| 2 March | Adwa Victory Day | Greeting |
| 1 May | International Labour Day | A leave or self-service post: what employees can do from their phone |
| Early July, Hamle 1 | The government fiscal year begins | The payroll pillar, aimed at public institutions; government is one of the sector templates |
| Moveable | Fasika, Siklet, Eid al-Fitr, Eid al-Adha, Mawlid | Greeting; check each year's date |

**Coming up after 2 November 2026:** Genna on 7 January 2027, Timket on 19 January 2027, Adwa on 2 March 2027.

## Measuring it

The site runs no web analytics, so measure on the platforms and in the product:

- **Per post**, from each platform's insights: reach, saves and shares, link clicks.
- **Per week**, from the platform console's tenant list: new trial organisations. A spike the day after a post is the only attribution there is; do not add tracking cookies to the site to get more.
- **Per month**, compare the pillars. Repeat what is saved and shared; retire what is not.
