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

## Accept/reject/revoke a pending trade -- `tradeResponse`, not `tradeProposal`

Confirmed straight from the docs page (no live testing needed -- MFL states
this explicitly): responding to an existing pending trade is a separate
import type, `tradeResponse`, not a resubmission of `tradeProposal`. Params
are just `TRADE_ID` + `RESPONSE` (`accept`/`reject`/`revoke`) and an optional
`COMMENTS`. `revoke` is restricted by MFL to the trade's originator;
`accept`/`reject` to its target -- enforced by MFL itself, not something this
codebase needs to check. `offer-trade.php` originally guessed this could be
done by resubmitting `tradeProposal` with the same give-up/receive lists,
which was never more than partially confirmed and had no reject path at all;
that's been replaced with the real `tradeResponse` call.
