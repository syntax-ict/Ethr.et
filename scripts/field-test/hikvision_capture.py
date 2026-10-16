#!/usr/bin/env python3
"""Record what a real Hikvision terminal answers to the calls ETHR makes.

Field test O1 (docs/field-test/O1-FIELD-TEST.md). Run it on a laptop on the
same network as the terminal. It needs Python 3.8+ and nothing else.

    python hikvision_capture.py --host 192.0.2.10
    python hikvision_capture.py --host 192.0.2.10 --write-tests

By default it only READS: device info, status, clock, access events and the
user list. Nothing on the terminal changes. --write-tests adds the three calls
ETHR uses to manage a terminal, against a throwaway test user it creates and
removes again, plus one clock set to the laptop's current time.

The password is asked for at the prompt and never written anywhere. Names and
employee numbers in the answers are replaced with stable placeholders (E001,
"Person 1"), and face-picture links are removed, so the folder holds no
personal data. The result is a folder and a .zip beside it: send the .zip back.

Every request is the one HikvisionAdapter.php sends, byte for byte where it
matters, so the answers show whether that code works on real firmware.
"""
from __future__ import annotations

import argparse
import datetime as dt
import getpass
import json
import re
import shutil
import sys
import urllib.error
import urllib.request
from pathlib import Path

TEST_EMPLOYEE_NO = "ETHRTEST01"


class Recorder:
    def __init__(self, base: str, user: str, password: str, out: Path):
        self.base = base.rstrip("/")
        self.out = out
        self.n = 0
        self.auth_schemes: set[str] = set()
        self.results: list[dict] = []
        self.pseudonyms: dict[str, str] = {}
        mgr = urllib.request.HTTPPasswordMgrWithDefaultRealm()
        mgr.add_password(None, self.base, user, password)
        # Both handlers: urllib answers whichever challenge the terminal sends,
        # and the challenge itself is recorded (ETHR currently sends Basic).
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPDigestAuthHandler(mgr),
            urllib.request.HTTPBasicAuthHandler(mgr),
        )

    # ── personal data out ────────────────────────────────────────────────
    def pseudo(self, value: str, kind: str) -> str:
        key = f"{kind}:{value}"
        if key not in self.pseudonyms:
            count = sum(1 for k in self.pseudonyms if k.startswith(kind + ":")) + 1
            self.pseudonyms[key] = f"E{count:03d}" if kind == "no" else f"Person {count}"
        return self.pseudonyms[key]

    def scrub(self, node):
        if isinstance(node, dict):
            clean = {}
            for k, v in node.items():
                lk = k.lower()
                if lk in ("employeeno", "employeenostring", "cardno") and isinstance(v, (str, int)) and str(v) != TEST_EMPLOYEE_NO:
                    clean[k] = self.pseudo(str(v), "no")
                elif lk == "name" and isinstance(v, str) and v:
                    clean[k] = self.pseudo(v, "name")
                elif "url" in lk and isinstance(v, str) and ("pic" in lk or "face" in lk):
                    clean[k] = "[picture link removed]"
                else:
                    clean[k] = self.scrub(v)
            return clean
        if isinstance(node, list):
            return [self.scrub(v) for v in node]
        return node

    def scrub_text(self, text: str) -> str:
        try:
            return json.dumps(self.scrub(json.loads(text)), ensure_ascii=False, indent=2)
        except ValueError:
            text = re.sub(r"(<employeeNo>)[^<]*(</employeeNo>)", r"\1[removed]\2", text)
            return re.sub(r"(<name>)[^<]*(</name>)", r"\1[removed]\2", text)

    # ── one exchange ───────────────────────────────────────────────────────
    def call(self, label: str, method: str, path: str, body=None, content_type="application/json") -> dict:
        self.n += 1
        data = None
        if body is not None:
            data = body.encode() if isinstance(body, str) else json.dumps(body).encode()
        req = urllib.request.Request(self.base + path, data=data, method=method)
        if data is not None:
            req.add_header("Content-Type", content_type)
        status, text, headers = None, "", {}
        try:
            with self.opener.open(req, timeout=15) as r:
                status, text, headers = r.status, r.read().decode("utf-8", "replace"), dict(r.headers)
        except urllib.error.HTTPError as e:
            status, text, headers = e.code, e.read().decode("utf-8", "replace"), dict(e.headers)
            challenge = e.headers.get("WWW-Authenticate", "")
            if challenge:
                self.auth_schemes.add(challenge.split()[0])
        except Exception as e:  # unreachable, timeout, TLS
            text = f"{type(e).__name__}: {e}"
        headers = {k: v for k, v in headers.items() if k.lower() not in ("set-cookie", "authorization")}
        record = {
            "step": self.n,
            "label": label,
            "request": {"method": method, "path": path, "body": self.scrub(body) if isinstance(body, (dict, list)) else body},
            "response": {"status": status, "headers": headers, "body": self.scrub_text(text)},
        }
        name = f"{self.n:02d}-{re.sub(r'[^a-z0-9]+', '-', label.lower()).strip('-')}.json"
        (self.out / name).write_text(json.dumps(record, ensure_ascii=False, indent=2), encoding="utf-8")
        ok = status is not None and 200 <= status < 300
        self.results.append({"step": self.n, "label": label, "status": status, "ok": ok})
        print(f"  {'OK  ' if ok else 'FAIL'} {status!s:>4}  {label}")
        return {"status": status, "text": text}


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    p.add_argument("--host", required=True, help="the terminal's address on this network, e.g. 192.0.2.10")
    p.add_argument("--port", type=int, default=80)
    p.add_argument("--https", action="store_true", help="the terminal serves HTTPS (ETHR uses http)")
    p.add_argument("--user", default="admin")
    p.add_argument("--hours", type=int, default=24, help="how far back to read access events (default 24)")
    p.add_argument("--write-tests", action="store_true",
                   help=f"also add, read back and remove test user {TEST_EMPLOYEE_NO}, and set the clock")
    p.add_argument("--utc-offset", default="+03:00", help="the terminal's zone for the clock test (default +03:00)")
    p.add_argument("--out", default=None, help="output folder (default: o1-hikvision-<time>)")
    a = p.parse_args()

    password = getpass.getpass(f"Password for {a.user} on {a.host}: ")
    stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
    out = Path(a.out or f"o1-hikvision-{stamp}")
    out.mkdir(parents=True, exist_ok=True)
    base = f"{'https' if a.https else 'http'}://{a.host}:{a.port}"
    rec = Recorder(base, a.user, password, out)
    del password

    # Which sign-in the terminal asks for. The authenticated opener answers the
    # challenge silently, so ask once without credentials and read it.
    try:
        urllib.request.urlopen(base + "/ISAPI/System/deviceInfo", timeout=15)
        rec.auth_schemes.add("none (answered without a password)")
    except urllib.error.HTTPError as e:
        for challenge in e.headers.get_all("WWW-Authenticate") or []:
            rec.auth_schemes.add(challenge.split()[0])
    except Exception as e:  # unreachable: the calls below will say so
        print(f"  could not reach {base}: {type(e).__name__}")

    print(f"Reading {base} (nothing on the terminal changes in this part)")
    rec.call("device info", "GET", "/ISAPI/System/deviceInfo")
    rec.call("status", "GET", "/ISAPI/System/status")
    rec.call("clock", "GET", "/ISAPI/System/time")
    rec.call("access event capabilities", "GET", "/ISAPI/AccessControl/AcsEvent/capabilities?format=json")
    rec.call("user capabilities", "GET", "/ISAPI/AccessControl/UserInfo/capabilities?format=json")
    rec.call("push targets (httpHosts)", "GET", "/ISAPI/Event/notification/httpHosts")

    # The event search exactly as HikvisionAdapter::buildEventSearchBody pages it.
    end = dt.datetime.now().astimezone()
    start = end - dt.timedelta(hours=a.hours)
    position = 0
    for page in range(1, 4):
        r = rec.call(f"access events page {page}", "POST", "/ISAPI/AccessControl/AcsEvent?format=json", {
            "AcsEventCond": {
                "searchID": f"ethr-o1-{stamp}",
                "searchResultPosition": position,
                "maxResults": 30,
                "major": 5,
                "minor": 0,
                "startTime": start.isoformat(timespec="seconds"),
                "endTime": end.isoformat(timespec="seconds"),
            },
        })
        try:
            acs = json.loads(r["text"]).get("AcsEvent", {})
        except ValueError:
            break
        position += int(acs.get("numOfMatches", 0) or 0)
        if acs.get("responseStatusStrg") != "MORE":
            break

    rec.call("user list", "POST", "/ISAPI/AccessControl/UserInfo/Search?format=json", {
        "UserInfoSearchCond": {"searchID": f"ethr-o1-users-{stamp}", "searchResultPosition": 0, "maxResults": 30},
    })

    if a.write_tests:
        print(f"Write tests (test user {TEST_EMPLOYEE_NO}, then the clock)")
        rec.call("add test user", "POST", "/ISAPI/AccessControl/UserInfo/SetUp?format=json", {
            "UserInfo": {
                "employeeNo": TEST_EMPLOYEE_NO, "name": "ETHR Test", "userType": "normal",
                "Valid": {"enable": True, "beginTime": "2020-01-01T00:00:00", "endTime": "2037-12-31T23:59:59",
                          "timeType": "local"},
            },
        })
        rec.call("find test user", "POST", "/ISAPI/AccessControl/UserInfo/Search?format=json", {
            "UserInfoSearchCond": {"searchID": f"ethr-o1-find-{stamp}", "searchResultPosition": 0, "maxResults": 5,
                                   "EmployeeNoList": [{"employeeNo": TEST_EMPLOYEE_NO}]},
        })
        delete = {"UserInfoDelCond": {"EmployeeNoList": [{"employeeNo": TEST_EMPLOYEE_NO}]}}
        rec.call("remove test user", "PUT", "/ISAPI/AccessControl/UserInfo/Delete?format=json", delete)
        rec.call("remove test user again (expects NotExist)", "PUT",
                 "/ISAPI/AccessControl/UserInfo/Delete?format=json", delete)
        sign, hh, mm = a.utc_offset[0], int(a.utc_offset[1:3]), int(a.utc_offset[4:6])
        posix = f"CST{'-' if sign == '+' else '+'}{hh}:{mm:02d}:00"
        now = dt.datetime.now(dt.timezone(dt.timedelta(hours=hh, minutes=mm) * (1 if sign == "+" else -1)))
        rec.call("set clock", "PUT", "/ISAPI/System/time",
                 '<?xml version="1.0" encoding="UTF-8"?><Time><timeMode>manual</timeMode>'
                 f"<localTime>{now.strftime('%Y-%m-%dT%H:%M:%S')}</localTime><timeZone>{posix}</timeZone></Time>",
                 content_type="application/xml")
        rec.call("clock after set", "GET", "/ISAPI/System/time")

    summary = {
        "captured_at": dt.datetime.now().astimezone().isoformat(timespec="seconds"),
        "terminal": base,
        "auth_challenge_seen": sorted(rec.auth_schemes) or ["none (no 401)"],
        "ethr_sends": "Basic",
        "write_tests": a.write_tests,
        "steps": rec.results,
    }
    (out / "00-summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    archive = shutil.make_archive(str(out), "zip", out)
    failed = [r for r in rec.results if not r["ok"]]
    print(f"\nTerminal asked for: {', '.join(summary['auth_challenge_seen'])}. ETHR currently sends Basic.")
    print(f"{len(rec.results) - len(failed)} of {len(rec.results)} calls answered 2xx.")
    print(f"Send back: {archive}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
