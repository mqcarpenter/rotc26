# MFL API Reference

`api_info-2026-07-17.html` is a saved copy of MFL's own API documentation page
(`https://api.myfantasyleague.com/2026/api_info?STATE=details`), captured
2026-07-17. It's the authoritative param list for every export/import call
this codebase uses (`tradeProposal`, `pendingTrades`, `assets`, `rosters`,
`league`, etc.) -- MFL doesn't version this page with a stable URL per
season, so a fresh copy is worth re-saving if this file starts looking stale
against a live `Test it!` link.

## Known gap: `assets` response shape

MFL's page documents the *request* params for every export type, but not the
*response* shape -- for most calls that's fine because a live response has
already been seen and confirmed (see the `pendingTrades` handling in
`franchise/offer-trade.php` for an example of that confirmation process).

`assets` (used by `rotc_all_franchise_picks()` in `offer-trade.php` to power
draft-pick trading) is the one call where the response shape is still a
best-guess, not confirmed live. The field names assumed --
`type`/`year`/`round`/`pick`/`original_team` -- are inferred from the
`DP_`/`FP_` id format documented under `tradeProposal`, nothing more.

To confirm or correct this: load `/franchise/offer-trade.php?debug=assets`
while logged in as any owner, and compare the real JSON against what
`rotc_all_franchise_picks()` expects. Update this README (and the function's
doc comment) once it's actually been checked against a live response.
