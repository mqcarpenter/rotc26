# MFL API Reference

`api_info-2026-07-17.html` is a saved copy of MFL's own API documentation page
(`https://api.myfantasyleague.com/2026/api_info?STATE=details`), captured
2026-07-17. It's the authoritative param list for every export/import call
this codebase uses (`tradeProposal`, `pendingTrades`, `assets`, `rosters`,
`league`, etc.) -- MFL doesn't version this page with a stable URL per
season, so a fresh copy is worth re-saving if this file starts looking stale
against a live `Test it!` link.

## `assets` response shape -- confirmed 2026-07-18

MFL's page documents the *request* params for every export type, but not the
*response* shape -- for most calls that's fine because a live response has
already been seen and confirmed (see the `pendingTrades` handling in
`franchise/offer-trade.php` for an example of that confirmation process).

`assets` (used by `rotc_all_franchise_picks()` in `offer-trade.php` to power
draft-pick trading) was the one call where the response shape was still a
best-guess. A live `?debug=assets` dump showed the real shape is different
from what was assumed -- each franchise has `currentYearDraftPicks.draftPick[]`
and `futureYearDraftPicks.draftPick[]` (there's no flat `asset` list keyed by
`type`, which is why this silently returned zero picks before the fix). Each
`draftPick` already carries a ready-to-submit id in `pick` (e.g.
`FP_0001_2027_1`) and a ready-made label in `description` (e.g. "Year 2027
Round 1 Draft Pick from Angels of Harlem") -- no round-number/suffix
formatting needed.

**Still open:** `currentYearDraftPicks` was empty for every franchise in the
live sample (likely because this year's draft already happened), so its
`draftPick` shape is assumed symmetric with `futureYearDraftPicks` rather than
separately confirmed. Re-check via `?debug=assets` once a franchise actually
holds an unspent current-year pick.
