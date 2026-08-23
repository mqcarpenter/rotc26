<?php
/**
 * includes/trade-offers.php
 * Queries + rendering for the "Trade Market" panel on history/index.php.
 *
 * Reads rotchist_trade_offers (see sql/rotchist_trade_offers.sql), which
 * holds every trade OFFER 2016-2026, not just the ones that happened --
 * 14,403 rows covering 7,405 offers against 329 completed trades. The
 * rejected/revoked/expired side of that is unavailable from MFL's public
 * API and comes from the commissioner report scrape.
 *
 * Kept out of history/index.php itself because that file is already 650
 * lines of records-hub queries, and this panel's aggregation is bulky
 * enough to warrant its own home -- same reasoning as includes/top-games.php.
 *
 * Charts are hand-rolled inline SVG: the site has no charting library and
 * pulling one in for two charts isn't worth the payload.
 */

// Grouping key for a franchise across seasons. Prefers the stable
// rotchist_franchises identity resolved at ingest, falling back to the raw
// MFL id when that resolution wasn't available.
//
// Grouping on the MFL id instead would be WRONG, because MFL slots change
// hands: slot 0002 was Krypton Knights 2006-2020 and Carnivorous
// SilverBacks from 2021, two different franchises. Krypton Knights also
// MOVED, from slot 0002 to 0004. Only the rotchist_franchises identity
// follows a franchise through both, which is why this resolves rather than
// grouping on what MFL happens to call a slot in the current season.
//
// This legitimately yields more keys than there are current teams (21 vs
// 16): franchises that have left the league still hold their history.
//
// The trailing COLLATE is required, not cosmetic: CAST(int AS CHAR) yields a
// string in the connection's collation while the CHAR(4) column carries the
// column's own collation, and UNION-ing the two halves then fails with
// "Illegal mix of collations for operation 'UNION'". Pinning both sides to
// one collation makes the halves compatible. It must be utf8mb4_general_ci:
// that is what the existing rotchist_* tables use, and the joins to
// rotchist_franchises / rotchist_mfl_franchises compare against them.
const ROTC_TO_KEY_PROPOSER  = "COALESCE(CAST(t.proposer_id AS CHAR), CONCAT('m', t.proposer_mfl_id)) COLLATE utf8mb4_general_ci";
const ROTC_TO_KEY_RECIPIENT = "COALESCE(CAST(t.recipient_id AS CHAR), CONCAT('m', t.recipient_mfl_id)) COLLATE utf8mb4_general_ci";

function rotc_trade_offers_available(PDO $db): bool {
    try {
        $db->query("SELECT 1 FROM rotchist_trade_offers LIMIT 1")->fetchColumn();
        return true;
    } catch (Throwable $e) {
        return false; // table not created / not ingested yet
    }
}

/**
 * Display name per grouping key, taking the most recent season's name so a
 * franchise reads under the name people know it by now.
 */
function rotc_trade_offers_names(PDO $db): array {
    $sql = "
        SELECT t.season, " . ROTC_TO_KEY_PROPOSER . " AS k,
               COALESCE(rf.current_name, mf.franchise_name,
                        CONCAT('Franchise ', t.proposer_mfl_id)) AS nm
          FROM rotchist_trade_offers t
          LEFT JOIN rotchist_franchises rf ON rf.id = t.proposer_id
          LEFT JOIN rotchist_mfl_franchises mf
                 ON mf.season = t.season AND mf.mfl_franchise_id = t.proposer_mfl_id
        UNION
        SELECT t.season, " . ROTC_TO_KEY_RECIPIENT . " AS k,
               COALESCE(rf.current_name, mf.franchise_name,
                        CONCAT('Franchise ', t.recipient_mfl_id)) AS nm
          FROM rotchist_trade_offers t
          LEFT JOIN rotchist_franchises rf ON rf.id = t.recipient_id
          LEFT JOIN rotchist_mfl_franchises mf
                 ON mf.season = t.season AND mf.mfl_franchise_id = t.recipient_mfl_id
        ORDER BY season";
    $names = [];
    foreach ($db->query($sql) as $row) {
        $names[$row['k']] = $row['nm']; // later season overwrites earlier
    }
    return $names;
}

function rotc_trade_offers_data(PDO $db): array {
    $out = [];

    // ---- headline + per-season -------------------------------------
    $out['seasons'] = $db->query("
        SELECT season,
               SUM(status = 'proposal') AS proposals,
               SUM(status = 'rejected') AS rejected,
               SUM(status = 'revoked')  AS revoked,
               SUM(status = 'expired')  AS expired,
               SUM(status = 'accepted') AS accepted
          FROM rotchist_trade_offers
         GROUP BY season
         ORDER BY season
    ")->fetchAll();

    $out['totals'] = ['proposals' => 0, 'rejected' => 0, 'revoked' => 0,
                      'expired' => 0, 'accepted' => 0];
    foreach ($out['seasons'] as $s) {
        foreach ($out['totals'] as $k => $_) $out['totals'][$k] += (int) $s[$k];
    }

    // ---- per-franchise ---------------------------------------------
    // Built as a UNION of proposer-side and recipient-side rows, the same
    // shape history/index.php uses to turn one game row into two team rows.
    //
    // Acceptance is split into proposer/recipient: an accepted trade
    // involves two franchises, so a single "accepted" counter credited to
    // both sides and divided by offers-made produces rates above 100% for
    // franchises that mostly receive.
    $sql = "
        SELECT k,
               SUM(made) AS offers_made, SUM(recd) AS offers_received,
               SUM(accp) AS accepted_as_proposer, SUM(accr) AS accepted_as_recipient,
               SUM(rejby) AS rejected_by_them, SUM(revby) AS revoked_by_them,
               SUM(expon) AS expired_on_them
          FROM (
            SELECT " . ROTC_TO_KEY_PROPOSER . " AS k,
                   (t.status = 'proposal') AS made, 0 AS recd,
                   (t.status = 'accepted') AS accp, 0 AS accr,
                   0 AS rejby, (t.status = 'revoked') AS revby, 0 AS expon
              FROM rotchist_trade_offers t
            UNION ALL
            SELECT " . ROTC_TO_KEY_RECIPIENT . " AS k,
                   0, (t.status = 'proposal'),
                   0, (t.status = 'accepted'),
                   (t.status = 'rejected'), 0, (t.status = 'expired')
              FROM rotchist_trade_offers t
          ) sides
         GROUP BY k
         ORDER BY offers_made DESC";
    $out['franchises'] = $db->query($sql)->fetchAll();

    // ---- most frequent offer pairings -------------------------------
    $out['pairs'] = $db->query("
        SELECT " . ROTC_TO_KEY_PROPOSER . " AS from_k,
               " . ROTC_TO_KEY_RECIPIENT . " AS to_k,
               COUNT(*) AS offers,
               SUM(t.status = 'accepted') AS trades
          FROM rotchist_trade_offers t
         WHERE t.status IN ('proposal', 'accepted')
         GROUP BY from_k, to_k
         ORDER BY offers DESC
         LIMIT 15
    ")->fetchAll();

    // ---- seasonality -------------------------------------------------
    $out['months'] = $db->query("
        SELECT MONTH(occurred_at) AS m, COUNT(*) AS n
          FROM rotchist_trade_offers
         WHERE status = 'proposal'
         GROUP BY m ORDER BY m
    ")->fetchAll();

    $out['names'] = rotc_trade_offers_names($db);
    return $out;
}

/** Horizontal stacked bar, one row per season, as inline SVG. */
function rotc_trade_offers_season_chart(array $seasons): string {
    if (!$seasons) return '';
    $segs = [
        ['accepted', 'Accepted', '#2e7d32'],
        ['rejected', 'Rejected', '#c62828'],
        ['revoked',  'Revoked',  '#ef6c00'],
        ['expired',  'Expired',  '#6a1b9a'],
    ];
    // Proposals aren't a segment: every other status IS the outcome of a
    // proposal, so stacking both would double-count the same offer.
    $max = 1;
    foreach ($seasons as $s) {
        $t = (int) $s['accepted'] + (int) $s['rejected']
           + (int) $s['revoked'] + (int) $s['expired'];
        if ($t > $max) $max = $t;
    }

    $rowH = 22; $gap = 6; $labelW = 46; $barW = 420;
    $h = count($seasons) * ($rowH + $gap) + 34;
    $svg = '<svg class="rotc-to-chart" viewBox="0 0 ' . ($labelW + $barW + 56)
         . ' ' . $h . '" role="img" aria-label="Trade offer outcomes by season">';

    $y = 0;
    foreach ($seasons as $s) {
        $svg .= '<text x="0" y="' . ($y + 15) . '" class="rotc-to-axis">'
              . htmlspecialchars((string) $s['season']) . '</text>';
        $x = $labelW;
        $total = 0;
        foreach ($segs as [$key, $label, $colour]) {
            $v = (int) $s[$key];
            $total += $v;
            if ($v <= 0) continue;
            $w = ($v / $max) * $barW;
            $svg .= '<rect x="' . round($x, 2) . '" y="' . $y . '" width="'
                  . round($w, 2) . '" height="' . $rowH . '" fill="' . $colour
                  . '"><title>' . htmlspecialchars("{$s['season']} $label: $v")
                  . '</title></rect>';
            $x += $w;
        }
        $svg .= '<text x="' . round($x + 6, 2) . '" y="' . ($y + 15)
              . '" class="rotc-to-axis">' . $total . '</text>';
        $y += $rowH + $gap;
    }

    $lx = $labelW;
    foreach ($segs as [$key, $label, $colour]) {
        $svg .= '<rect x="' . $lx . '" y="' . ($y + 6) . '" width="10" height="10" fill="' . $colour . '"/>'
              . '<text x="' . ($lx + 14) . '" y="' . ($y + 15) . '" class="rotc-to-axis">'
              . htmlspecialchars($label) . '</text>';
        $lx += 26 + strlen($label) * 6.2;
    }
    return $svg . '</svg>';
}

/** Offers made vs. hit rate, one row per franchise. */
function rotc_trade_offers_volume_chart(array $franchises, array $names): string {
    $rows = array_slice(array_filter($franchises,
        fn($f) => (int) $f['offers_made'] > 0), 0, 12);
    if (!$rows) return '';

    $max = max(array_map(fn($f) => (int) $f['offers_made'], $rows)) ?: 1;
    $rowH = 20; $gap = 5; $labelW = 150; $barW = 300;
    $h = count($rows) * ($rowH + $gap) + 10;
    $svg = '<svg class="rotc-to-chart" viewBox="0 0 ' . ($labelW + $barW + 90)
         . ' ' . $h . '" role="img" aria-label="Offers made and acceptance rate by franchise">';

    $y = 0;
    foreach ($rows as $f) {
        $made = (int) $f['offers_made'];
        $acc  = (int) $f['accepted_as_proposer'];
        $rate = $made ? ($acc / $made) : 0;
        $name = $names[$f['k']] ?? $f['k'];
        $svg .= '<text x="0" y="' . ($y + 14) . '" class="rotc-to-axis">'
              . htmlspecialchars(mb_strimwidth($name, 0, 24, '…')) . '</text>';
        $w = ($made / $max) * $barW;
        $svg .= '<rect x="' . $labelW . '" y="' . $y . '" width="' . round($w, 2)
              . '" height="' . $rowH . '" fill="#1565c0"><title>'
              . htmlspecialchars("$name: $made offers, $acc accepted")
              . '</title></rect>';
        // Success sits inside the volume bar, so the gap between the two is
        // the visual point of the chart.
        $svg .= '<rect x="' . $labelW . '" y="' . $y . '" width="'
              . round(($acc / $max) * $barW, 2) . '" height="' . $rowH . '" fill="#2e7d32"/>';
        $svg .= '<text x="' . ($labelW + $barW + 8) . '" y="' . ($y + 14)
              . '" class="rotc-to-axis">' . $made . ' · '
              . number_format($rate * 100, 1) . '%</text>';
        $y += $rowH + $gap;
    }
    return $svg . '</svg>';
}

function rotc_trade_offers_render(array $d): void {
    $t = $d['totals'];
    $names = $d['names'];
    $offers = (int) $t['proposals'];
    $trades = (int) $t['accepted'];
    $rate = $offers ? ($trades / $offers) * 100 : 0;
    // end() takes its argument by reference, so it needs a real variable --
    // end($d['seasons']) raises "Only variables should be passed by reference".
    $seasonRows = $d['seasons'];
    $firstSeason = $seasonRows ? (int) $seasonRows[0]['season'] : null;
    $lastSeason = $seasonRows ? (int) $seasonRows[count($seasonRows) - 1]['season'] : null;

    echo '<h3>The Trade Market</h3>';
    echo '<div class="rotc-to-stats">';
    foreach ([
        [number_format($offers), 'offers made'],
        [number_format($trades), 'trades completed'],
        [number_format($rate, 1) . '%', 'offers that stuck'],
        [$firstSeason . '–' . $lastSeason, 'seasons covered'],
    ] as [$big, $small]) {
        echo '<div class="rotc-to-stat"><strong>' . htmlspecialchars($big)
           . '</strong><span>' . htmlspecialchars($small) . '</span></div>';
    }
    echo '</div>';

    echo '<p class="rotc-to-note">Rejected, revoked and expired offers are '
       . 'visible only to the commissioner in MFL and are not part of its '
       . 'public API — they come from the league\'s own trade-offer log. '
       . 'Completed trades reconcile exactly with the API for every season.</p>';

    echo '<h3>Offer Outcomes by Season</h3>';
    echo rotc_trade_offers_season_chart($d['seasons']);
    rotchist_table(
        ['season' => 'Season', 'proposals' => 'Offers', 'accepted' => 'Trades',
         'rejected' => 'Rejected', 'revoked' => 'Revoked', 'expired' => 'Expired'],
        $d['seasons'], 'No trade offer data ingested yet.'
    );

    echo '<h3>Offer Volume vs. Success Rate</h3>';
    echo '<p class="rotc-to-note">Blue is offers made; green is the share that '
       . 'became trades.</p>';
    echo rotc_trade_offers_volume_chart($d['franchises'], $names);

    echo '<h3>By Franchise</h3>';
    $rows = [];
    foreach ($d['franchises'] as $f) {
        $made = (int) $f['offers_made'];
        $recd = (int) $f['offers_received'];
        $rows[] = [
            'team'   => $names[$f['k']] ?? $f['k'],
            'made'   => $made,
            'recd'   => $recd,
            'accp'   => (int) $f['accepted_as_proposer'],
            'accr'   => (int) $f['accepted_as_recipient'],
            'rejby'  => (int) $f['rejected_by_them'],
            'revby'  => (int) $f['revoked_by_them'],
            'expon'  => (int) $f['expired_on_them'],
            'hit'    => $made ? number_format(100 * (int) $f['accepted_as_proposer'] / $made, 1) . '%' : '—',
            'rej'    => $recd ? number_format(100 * (int) $f['rejected_by_them'] / $recd, 1) . '%' : '—',
        ];
    }
    rotchist_table([
        'team' => 'Franchise', 'made' => 'Offers Made', 'recd' => 'Received',
        'accp' => 'Landed', 'accr' => 'Accepted', 'rejby' => 'Rejected',
        'revby' => 'Revoked', 'expon' => 'Let Expire',
        'hit' => 'Hit %', 'rej' => 'Reject %',
    ], $rows, 'No trade offer data ingested yet.');
    echo '<p class="rotc-to-note"><strong>Landed</strong> is offers this '
       . 'franchise made that were accepted; <strong>Accepted</strong> is '
       . 'offers it received and took. <strong>Hit %</strong> is Landed over '
       . 'Offers Made; <strong>Reject %</strong> is Rejected over Received.</p>';

    echo '<h3>Most Frequent Offer Pairings</h3>';
    $pairRows = [];
    foreach ($d['pairs'] as $p) {
        $pairRows[] = [
            'from'   => $names[$p['from_k']] ?? $p['from_k'],
            'to'     => $names[$p['to_k']] ?? $p['to_k'],
            'offers' => (int) $p['offers'],
            'trades' => (int) $p['trades'],
        ];
    }
    rotchist_table(['from' => 'Offered By', 'to' => 'Offered To',
                    'offers' => 'Offers', 'trades' => 'Trades'],
                   $pairRows, 'No pairings yet.');

    echo '<h3>When Offers Happen</h3>';
    $monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                   'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $monthRows = [];
    foreach ($d['months'] as $m) {
        $monthRows[] = ['month' => $monthNames[(int) $m['m']] ?? $m['m'],
                        'n' => (int) $m['n']];
    }
    rotchist_table(['month' => 'Month', 'n' => 'Offers'], $monthRows, 'No data yet.');
}
