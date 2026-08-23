#!/usr/bin/env python3
"""
tools/trade_offers_report.py

Console summary over data/trade_offers.json, plus a validation check
against MFL's public export API.

The validation matters: the offer data is scraped HTML, so the one
independently verifiable quantity is the accepted-trade count, which the
API reports authoritatively via TYPE=transactions&TRANS_TYPE=TRADE. If
those two disagree for a season, the scrape is wrong.
"""

import json
import subprocess
import sys
from pathlib import Path

DATA = Path.home() / "rotc26" / "data" / "trade_offers.json"


def api_trade_counts(seasons):
    out = {}
    for year, league in seasons:
        url = (f"https://api.myfantasyleague.com/{year}/export?"
               f"TYPE=transactions&L={league}&TRANS_TYPE=TRADE&JSON=1")
        res = subprocess.run(["curl", "-s", "-m", "30", "-L",
                              "-A", "ROTC26-Site", url],
                             capture_output=True, text=True)
        try:
            d = json.loads(res.stdout)
            t = (d.get("transactions") or {}).get("transaction")
            out[year] = 0 if t is None else (len(t) if isinstance(t, list) else 1)
        except Exception:
            out[year] = None
    return out


def main():
    d = json.loads(DATA.read_text())
    S = d["seasons"]

    # Most recent name wins for display; per-season names stay in the JSON.
    names = {}
    for y in sorted(S):
        names.update(S[y]["franchise_names"])

    print("SEASON TOTALS")
    print(f"{'Yr':<6}{'Rows':>6}{'Offers':>8}{'Rej':>6}{'Rev':>6}{'Exp':>6}{'Trades':>8}")
    tot = {}
    for y in sorted(S):
        st = S[y]["statuses"]
        row = (st.get("Trade Proposal", 0), st.get("Trade Rejected", 0),
               st.get("Trade Revoked", 0), st.get("Trade Offer Expired", 0),
               st.get("Trade", 0))
        for i, k in enumerate(["off", "rej", "rev", "exp", "tr"]):
            tot[k] = tot.get(k, 0) + row[i]
        print(f"{y:<6}{S[y]['rows']:>6}{row[0]:>8}{row[1]:>6}{row[2]:>6}"
              f"{row[3]:>6}{row[4]:>8}")
    print(f"{'ALL':<6}{sum(S[y]['rows'] for y in S):>6}{tot['off']:>8}"
          f"{tot['rej']:>6}{tot['rev']:>6}{tot['exp']:>6}{tot['tr']:>8}")

    # ---- per-franchise, all seasons ----
    T = {}
    for y in sorted(S):
        for f, v in S[y]["by_franchise"].items():
            t = T.setdefault(f, {})
            for k, n in v.items():
                t[k] = t.get(k, 0) + n

    print("\nPER-FRANCHISE (all seasons)")
    hdr = (f"{'Franchise':<32}{'Made':>6}{'Recd':>6}{'AccP':>6}{'AccR':>6}"
           f"{'RejBy':>7}{'RevBy':>7}{'ExpOn':>7}{'Hit%':>7}{'Rej%':>7}")
    print(hdr)
    for f, v in sorted(T.items(), key=lambda x: -x[1].get("offers_made", 0)):
        made = v.get("offers_made", 0)
        recd = v.get("offers_received", 0)
        accp = v.get("accepted_as_proposer", 0)
        accr = v.get("accepted_as_recipient", 0)
        # Hit% = share of THIS franchise's own offers that were accepted.
        # Rej% = share of offers received that this franchise rejected.
        hit = f"{100*accp/made:.1f}" if made else "-"
        rej = f"{100*v.get('rejected_by_them',0)/recd:.1f}" if recd else "-"
        print(f"{names.get(f,f)[:31]:<32}{made:>6}{recd:>6}{accp:>6}{accr:>6}"
              f"{v.get('rejected_by_them',0):>7}{v.get('revoked_by_them',0):>7}"
              f"{v.get('expired_on_them',0):>7}{hit:>7}{rej:>7}")

    # ---- validation against the public API ----
    if "--validate" in sys.argv:
        print("\nVALIDATION: scraped 'Trade' vs API TRANS_TYPE=TRADE")
        pairs = [(y, S[y]["league_id"]) for y in sorted(S)]
        api = api_trade_counts(pairs)
        ok = True
        for y in sorted(S):
            mine = S[y]["statuses"].get("Trade", 0)
            theirs = api.get(y)
            flag = "OK" if mine == theirs else "MISMATCH"
            if mine != theirs:
                ok = False
            print(f"  {y}: scraped={mine:<4} api={theirs}  {flag}")
        print("  all seasons agree" if ok else "  *** discrepancies above ***")


if __name__ == "__main__":
    main()
