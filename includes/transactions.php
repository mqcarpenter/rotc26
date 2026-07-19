<?php
/**
 * includes/transactions.php
 * Shared transaction parsing/rendering helpers -- pulled out of
 * transactions/transactions.php so the front-page "Latest Transactions"
 * sidebar widget (templates/latest-transactions.php) can reuse the same
 * TRADE/FREE_AGENT parsing logic instead of re-implementing it. See
 * transactions/transactions.php's own doc comment for how the raw
 * "added|dropped" transaction string format was confirmed live.
 */

// WAIVER/TAXI exclusion, shared with transactions/transactions.php --
// this league doesn't use either (confirmed live: currentWaiverType is
// NONE, Taxi Squad dropped from the nav entirely per Matteo's request).
if (!defined('ROTC_TXN_EXCLUDED_TYPES')) {
    define('ROTC_TXN_EXCLUDED_TYPES', ['WAIVER', 'BBID_WAIVER', 'WAIVER_REQUEST', 'BBID_WAIVER_REQUEST', 'TAXI']);
}

/**
 * Splits the raw pipe-delimited "added|dropped" transaction string
 * into player id lists. Shared by rotc_txn_details() (the Details
 * column) and rotc_txn_type_badge() (the Type column) so both agree on
 * what actually happened in a FREE_AGENT-type row instead of each
 * re-parsing it separately.
 */
function rotc_txn_parse_added_dropped(array $t): array {
    $raw = (string) ($t['transaction'] ?? '');
    $sides = explode('|', $raw, 2);
    return [
        'added'   => array_filter(explode(',', $sides[0] ?? '')),
        'dropped' => array_filter(explode(',', $sides[1] ?? '')),
    ];
}

/**
 * Pill label + CSS class for the Type column. FREE_AGENT isn't
 * exclusively a drop -- confirmed via MFL's own import docs that
 * fcfsWaiver (the call behind every FREE_AGENT-type row here) accepts
 * ADD and DROP independently or together in one instant move -- so
 * this looks at what the row's own added/dropped lists actually
 * contain rather than assuming every FREE_AGENT row is a drop.
 */
function rotc_txn_type_badge(array $t): array {
    $type = $t['type'] ?? '';
    if ($type === 'FREE_AGENT') {
        $parsed = rotc_txn_parse_added_dropped($t);
        if ($parsed['added'] && $parsed['dropped']) return ['label' => 'ADD/DROP', 'class' => 'rotc-pill-swap'];
        if ($parsed['dropped']) return ['label' => 'DROP', 'class' => 'rotc-pill-drop'];
        if ($parsed['added']) return ['label' => 'ADD', 'class' => 'rotc-pill-add'];
        return ['label' => $type ?: '--', 'class' => ''];
    }
    if ($type === 'TRADE') return ['label' => 'TRADE', 'class' => 'rotc-pill-trade'];
    return ['label' => $type ?: '--', 'class' => ''];
}

/**
 * Human-readable Details text for one transaction row. $players must be
 * DETAILS=1 records (not just id/name) if $useHoverCard is true, so the
 * shared hover widget (includes/player-hover.php) has bio/photo to show
 * -- same hover treatment every other player list on the site uses.
 *
 * $compact: one line total regardless of how many players were
 * dropped/added (comma-joined, no per-player "gave up on X. See ya."
 * theming) -- used by the front-page sidebar widget, where a single
 * multi-player transaction row rendering as several separate lines
 * made "last 15 transactions" visually read as far more than 15 items.
 * The full Transactions Report keeps the themed per-player lines
 * (default, $compact=false) since that verbosity was a specific request.
 */
function rotc_txn_details(array $t, array $players, array $franchises, bool $useHoverCard = false, bool $compact = false): string {
    $nameOf = function ($id) use ($players, $useHoverCard) {
        $pd = $players[$id] ?? null;
        $name = $pd['name'] ?? ('Player #' . $id);
        return $useHoverCard ? rotc_player_hover_span($name, $pd) : htmlspecialchars($name);
    };

    if (($t['type'] ?? '') === 'TRADE') {
        $f1 = $t['franchise'] ?? '';
        $f2 = $t['franchise2'] ?? '';
        $f1Gave = array_filter(explode(',', $t['franchise1_gave_up'] ?? ''));
        $f2Gave = array_filter(explode(',', $t['franchise2_gave_up'] ?? ''));
        $f1Name = $franchises[$f1]['name'] ?? $f1;
        $f2Name = $franchises[$f2]['name'] ?? $f2;
        $parts = [];
        if ($f1Gave) $parts[] = htmlspecialchars($f1Name) . ' sent: ' . implode(', ', array_map($nameOf, $f1Gave));
        if ($f2Gave) $parts[] = htmlspecialchars($f2Name) . ' sent: ' . implode(', ', array_map($nameOf, $f2Gave));
        return $parts ? implode('<br>', $parts) : '--';
    }

    // Everything else observed so far (FREE_AGENT, and presumably the
    // rest of the fcfsWaiver/waiver/IR family) uses the pipe-delimited
    // "added|dropped" shape described in transactions/transactions.php's
    // header comment.
    $raw = (string) ($t['transaction'] ?? '');
    if ($raw === '') return '--';
    $parsed = rotc_txn_parse_added_dropped($t);
    $added = $parsed['added'];
    $dropped = $parsed['dropped'];
    $franchiseName = $franchises[$t['franchise'] ?? '']['name'] ?? ($t['franchise'] ?? 'This team');
    $parts = [];
    if ($added) $parts[] = 'Added: ' . implode(', ', array_map($nameOf, $added));
    if ($compact) {
        if ($dropped) $parts[] = 'Dropped: ' . implode(', ', array_map($nameOf, $dropped));
    } else {
        // Per Matteo's request: a drop gets themed language instead of a
        // plain "Dropped: X" label, one line per dropped player so a
        // multi-player drop doesn't cram everyone into one sentence.
        foreach ($dropped as $id) {
            $parts[] = htmlspecialchars($franchiseName) . ' gave up on ' . $nameOf($id) . '. See ya.';
        }
    }
    return $parts ? implode('<br>', $parts) : htmlspecialchars($raw);
}

/**
 * Latest $count transactions league-wide (any franchise, TRANS_TYPE not
 * filtered beyond the same WAIVER/TAXI exclusion transactions.php
 * already applies -- this league doesn't use either, see that file's
 * own doc comment), newest first, with DETAILS=1 player bios resolved
 * for the shared hover card.
 * @return array each row: the raw MFL transaction record, plus 'badge'
 *   and 'detailsHtml' (rotc_txn_details() with hover cards) precomputed.
 */
function rotc_fetch_latest_transactions(int $count = 15): array {
    $franchises = mfl_franchises();
    $raw = mfl_cached_get('transactions', 900, ['TRANS_TYPE' => 'DEFAULT', 'COUNT' => 100]);
    $rows = mfl_normalize_list($raw['transactions']['transaction'] ?? null);
    $rows = array_values(array_filter($rows, function ($t) {
        return !in_array($t['type'] ?? '', ROTC_TXN_EXCLUDED_TYPES, true);
    }));
    usort($rows, function ($a, $b) { return (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0); });
    $rows = array_slice($rows, 0, $count);

    $allIds = [];
    foreach ($rows as $t) {
        if (($t['type'] ?? '') === 'TRADE') {
            foreach (explode(',', $t['franchise1_gave_up'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
            foreach (explode(',', $t['franchise2_gave_up'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
        } else {
            $sides = explode('|', (string) ($t['transaction'] ?? ''), 2);
            foreach (explode(',', $sides[0] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
            foreach (explode(',', $sides[1] ?? '') as $id) { if ($id !== '') $allIds[] = $id; }
        }
    }
    $players = [];
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }

    foreach ($rows as &$t) {
        $t['badge'] = rotc_txn_type_badge($t);
        $t['detailsHtml'] = rotc_txn_details($t, $players, $franchises, true, true);
        $t['franchiseName'] = $franchises[$t['franchise'] ?? '']['name'] ?? ($t['franchise'] ?? '');
    }
    unset($t);

    return $rows;
}
