#!/usr/bin/env python3
"""
tools/trade_offers_backfill.py

One-time backfill of the MFL commissioner-only "Trade Offers" report into
structured JSON, covering every season this league has existed on MFL.

Why this exists: MFL's public export API (TYPE=transactions) only returns
*accepted* trades -- its own docs say "All non-pending transactions", and
confirmed live, every TRADE row carries no status field. Rejected, revoked
and expired offers exist ONLY in the commissioner web report at
  /{year}/options?L={league}&O=03&TYPE=TRADE_OFFERS&FRANCHISE=0000&DAYS=...
which is HTML, not API, and requires a logged-in commissioner cookie.

Two gotchas this encodes, both confirmed live:
  * MFL RECYCLES league ids per year. 67102 is this league only from 2017;
    2016 is 46283, and 67102/46283 belong to entirely unrelated leagues in
    other years. Hence SEASONS below is an explicit per-year map, never a
    single id looped over years.
  * DAYS=99999 is dramatically slower server-side and times out; DAYS=3000
    covers a full season and responds reliably.

Cookie comes from ~/.mfl_commish_cookie (or $MFL_COMMISH_COOKIE), never from
a literal in this file, so the value stays out of the repo and out of git.
"""

import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mfl_html import parse_html  # noqa: E402

# season -> MFL league id. See module docstring on why this is not one id.
SEASONS = {
    2016: "46283",
    2017: "67102", 2018: "67102", 2019: "67102", 2020: "67102",
    2021: "67102", 2022: "67102", 2023: "67102", 2024: "67102",
    2025: "67102", 2026: "67102",
}

HOST = "www42.myfantasyleague.com"
DAYS = 3000
UA = "ROTC26-Site"
OUT = Path.home() / "rotc26" / "data" / "trade_offers.json"

# The five statuses this report emits. "Trade" is an accepted trade; its
# count per season matches the export API's TRADE count exactly, which is
# what validates a parse run.
STATUSES = {"Trade Proposal", "Trade Rejected", "Trade Revoked",
            "Trade Offer Expired", "Trade"}


def cookie() -> str:
    env = os.environ.get("MFL_COMMISH_COOKIE")
    if env:
        return env.strip()
    path = Path.home() / ".mfl_commish_cookie"
    if not path.exists():
        sys.exit("No cookie: set $MFL_COMMISH_COOKIE or write ~/.mfl_commish_cookie")
    return path.read_text().strip()


JAR = Path.home() / ".mfl_cookiejar"


def mode(page: str) -> str:
    """Which identity the session is currently in.

    In commissioner mode MFL's menu offers "Become Owner"; in owner mode it
    offers "Become Commissioner". Testing merely for the word
    "Commissioner" matches BOTH and is how a half-empty report first got
    mistaken for a complete one.
    """
    flat = re.sub(r"&nbsp;|\s+", " ", page)
    if "Become Commissioner" in flat:
        return "owner"
    if "Become Owner" in flat:
        return "commissioner"
    return "logged out"


def init_jar(ck: str) -> None:
    """Seed a curl cookie jar from the raw MFL_USER_ID cookie.

    A jar (rather than a fixed -H Cookie header) is required because the
    commissioner-mode switch below returns Set-Cookie, and that updated
    session has to carry into the report request.
    """
    name, _, value = ck.partition("=")
    # secure=FALSE deliberately: MFL redirects pre-2020 season pages to
    # plain http, and curl drops a secure-flagged cookie on that hop, which
    # surfaces as a bogus "not logged in" for 2016-2019 only. The cookie
    # therefore does traverse http for those seasons -- acceptable for a
    # one-off backfill of a fantasy league, and the reason to log out of
    # MFL afterwards so this session token stops being valid.
    JAR.write_text(
        "# Netscape HTTP Cookie File\n"
        f".myfantasyleague.com\tTRUE\t/\tFALSE\t2147483647\t"
        f"{name.strip()}\t{value.strip()}\n"
    )
    JAR.chmod(0o600)


def curl(url: str) -> str:
    # curl rather than urllib: MFL redirects between hosts and protocols
    # (pre-2020 seasons drop to http), and curl -L handles that chain
    # without the mixed-content restrictions a browser imposes.
    res = subprocess.run(
        ["curl", "-s", "-m", "120", "-L", "-A", UA,
         "-b", str(JAR), "-c", str(JAR), url],
        capture_output=True, text=True,
    )
    if res.returncode != 0:
        raise RuntimeError(f"curl failed ({res.returncode}) for {url}")
    return res.stdout


def become_commissioner(year: int, league: str) -> None:
    """Switch the session from owner mode into commissioner mode.

    Logging in via /login lands you in OWNER mode, and MFL shows an owner
    only the trade offers their own franchise is party to -- confirmed
    live: 2020 returned 83 rows as owner vs 987 as commissioner, with all
    accepted trades present either way (those are public) but most
    proposals missing. The site's own "Become Commissioner" link is
    /logout?L=..&BECOME=0000; despite the path it switches identity
    rather than ending the session.
    """
    curl(f"https://{HOST}/{year}/logout?L={league}&BECOME=0000")


CACHE = Path.home() / "rotc26" / "data" / "cache"


def fetch(year: int, league: str, refresh: bool = False) -> str:
    """Fetch a season's report, caching the raw HTML.

    Parsing this report took several iterations to get right; caching means
    a parser change is re-run offline instead of re-hitting a host that
    throttles these heavy reports.
    """
    CACHE.mkdir(parents=True, exist_ok=True)
    cached = CACHE / f"trade_offers_{year}.html"
    if cached.exists() and not refresh:
        return cached.read_text(errors="ignore")

    url = (f"https://{HOST}/{year}/options?L={league}&O=03"
           f"&TYPE=TRADE_OFFERS&FRANCHISE=0000&DAYS={DAYS}")
    page = curl(url)
    if mode(page) == "owner":
        # Only switch when actually needed. BECOME rides on /logout, and
        # calling it once per season is what appears to have torn down a
        # working session mid-run.
        become_commissioner(year, league)
        page = curl(url)

    # Validate BEFORE caching: an earlier version cached whatever came
    # back, so a dead session persisted logged-out pages to disk and a
    # later re-run happily parsed them as "0 rows".
    m = mode(page)
    if m != "commissioner":
        raise RuntimeError(f"{year}: session is {m}, expected commissioner")
    cached.write_text(page)
    return page


DATE_RE = r"\w{3} \w{3} \d{1,2} \d{1,2}:\d{2}:\d{2} [ap]\.m\. [A-Z]{2,3} \d{4}"


def split_detail(detail: str) -> dict:
    """Break a transaction cell into assets, expiry and the offer comment.

    The cell is a flat run of text holding up to four things, e.g.
      offers <assets> for <assets> Expires: <date> Reason: <trash talk>
      gave up <assets> gave up <assets> Expires: <date>
    Anchoring "Expires:" on the date pattern matters: a trailing
    "Reason: ..." otherwise gets swallowed into the expiry, which loses
    the comment entirely and leaves an unparseable timestamp.
    """
    expires, reason = "", ""
    m = re.search(rf"\bExpires:\s*({DATE_RE})", detail)
    if m:
        expires = m.group(1)
    m = re.search(r"\bReason:\s*(.*)$", detail)
    if m:
        reason = m.group(1).strip()

    assets = re.split(r"\bExpires:|\bReason:", detail)[0].strip()
    gave = got = ""
    m = re.match(r"^offers\s+(.*?)\s+for\s+(.*)$", assets, re.S)
    if m:
        gave, got = m.group(1).strip(), m.group(2).strip()
    elif assets.startswith("gave up"):
        # Accepted trades render as two "gave up" runs, proposer first. A
        # one-sided trade leaves the second run empty rather than absent.
        sides = [s.strip() for s in assets.split("gave up")[1:]]
        gave = sides[0] if sides else ""
        got = sides[1] if len(sides) > 1 else ""
    else:
        gave = assets
    return {"gave": gave, "got": got, "expires": expires, "reason": reason}


def parse(page: str, year: int) -> dict:
    if mode(page) != "commissioner":
        raise RuntimeError(f"{year}: session is {mode(page)}, "
                           "expected commissioner")

    doc = parse_html(page)

    target = None
    for t in doc.find_all("table"):
        rows = t.kids("tr")
        if len(rows) > 5 and "FRANCHISE" in rows[0].text.upper():
            target = rows
            break
    if not target:
        return {"rows": [], "names": {}}

    # Franchise id -> name comes from the report's own "involving" <select>,
    # so names are captured as they were THAT season. Franchise names change
    # over time (0004 has been Sexual Chocolate, Philippine Seminoles and
    # Krypton Knights), so a single current-name map would mislabel history.
    names = {}
    for opt in doc.find_all("option"):
        fid, label = opt.attrs.get("value", ""), opt.text
        if re.fullmatch(r"\d{4}", fid) and fid != "0000" and label:
            names[fid] = label

    out = []
    for row in target[1:]:
        cells = row.kids("td", "th")
        if len(cells) < 5:
            continue
        status = cells[2].text
        if status not in STATUSES:
            continue
        # Both franchises are linked in the FRANCHISE cell, proposer first,
        # recipient second -- that order is what makes proposer/recipient
        # attribution possible at all.
        ids = []
        for val in cells[1].attr_values("href", "src"):
            m = re.search(r"(?:F=|FRANCHISE=)(\d{4})", val)
            if m and m.group(1) not in ids:
                ids.append(m.group(1))
        detail = cells[3].text
        parts = split_detail(detail)
        out.append({
            "season": year,
            "status": status,
            "proposer": ids[0] if ids else None,
            "recipient": ids[1] if len(ids) > 1 else None,
            "detail": detail,
            "proposer_gave": parts["gave"],
            "recipient_gave": parts["got"],
            "expires": parts["expires"],
            "reason": parts["reason"],
            "date": cells[4].text,
        })
    return {"rows": out, "names": names}


def aggregate(rows: list) -> dict:
    """Per-franchise counts.

    Acceptance is split into accepted_as_proposer / accepted_as_recipient
    rather than one 'accepted' bucket: an accepted trade involves two
    franchises, so crediting both to a single counter and dividing by
    offers-made yields nonsense rates (>100%) for franchises that mostly
    receive offers.
    """
    agg = {}

    def slot(fid):
        return agg.setdefault(fid, {
            "offers_made": 0, "offers_received": 0,
            "accepted_as_proposer": 0, "accepted_as_recipient": 0,
            "rejected_by_them": 0, "revoked_by_them": 0,
            "expired_on_them": 0,
        })

    for r in rows:
        p, q, s = r["proposer"], r["recipient"], r["status"]
        if s == "Trade Proposal":
            if p: slot(p)["offers_made"] += 1
            if q: slot(q)["offers_received"] += 1
        elif s == "Trade":
            if p: slot(p)["accepted_as_proposer"] += 1
            if q: slot(q)["accepted_as_recipient"] += 1
        elif s == "Trade Rejected":
            if q: slot(q)["rejected_by_them"] += 1
        elif s == "Trade Revoked":
            if p: slot(p)["revoked_by_them"] += 1
        elif s == "Trade Offer Expired":
            if q: slot(q)["expired_on_them"] += 1
    return agg


def main():
    init_jar(cookie())

    # Merge onto whatever is already stored rather than starting empty: a
    # run where late seasons fail must not wipe seasons already collected.
    seasons, rows_by_season = {}, {}
    if OUT.exists():
        prev = json.loads(OUT.read_text())
        seasons = {int(k): v for k, v in prev.get("seasons", {}).items()}
        for r in prev.get("rows", []):
            rows_by_season.setdefault(r["season"], []).append(r)
    failures = []
    for year, league in sorted(SEASONS.items()):
        try:
            page = fetch(year, league, refresh="--refresh" in sys.argv)
            parsed = parse(page, year)
        except RuntimeError as e:
            # Keep going: one bad season shouldn't discard the rest of a
            # run that takes minutes and hits a rate-limited host.
            print(f"{year} (L={league}): FAILED -- {e}", flush=True)
            failures.append(year)
            time.sleep(2)
            continue
        rows = parsed["rows"]
        counts = {}
        for r in rows:
            counts[r["status"]] = counts.get(r["status"], 0) + 1
        seasons[year] = {
            "league_id": league,
            "rows": len(rows),
            "statuses": counts,
            "franchise_names": parsed["names"],
            "by_franchise": aggregate(rows),
        }
        rows_by_season[year] = rows
        print(f"{year} (L={league}): {len(rows):5} rows  {counts}", flush=True)
        time.sleep(2)  # be polite; heavy report, repeated hits get throttled

    all_rows = [r for y in sorted(rows_by_season) for r in rows_by_season[y]]
    seasons = {k: seasons[k] for k in sorted(seasons)}
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({
        "source": "MFL commissioner TRADE_OFFERS report (O=03)",
        "seasons": seasons,
        "rows": all_rows,
    }, indent=1))
    print(f"\nwrote {OUT}  ({len(all_rows)} rows)")
    if failures:
        print(f"FAILED seasons: {failures}")


if __name__ == "__main__":
    main()
