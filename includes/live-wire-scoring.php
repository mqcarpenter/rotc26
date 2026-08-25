<?php
/**
 * includes/live-wire-scoring.php
 * Turns a player's real stats into the points they earned, so the box
 * score shows WHY a score is what it is instead of just asserting it.
 *
 * Why this has to be computed rather than read: MFL publishes the score
 * and nothing else. Confirmed live -- TYPE=playerScores returns
 * {"id":"13116","score":"32.93"} with no breakdown, and DETAILS=1 changes
 * nothing. There is no stat export anywhere in its API. So the stats come
 * from ESPN's box score and the point values from this league's own
 * scoring rules (TYPE=rules), multiplied together here.
 *
 * IMPORTANT: the computed total is deliberately NEVER shown as the score.
 * MFL's number is authoritative and this reconciles to it, with anything
 * unaccounted for shown as a single "bonuses & other" line. That matters
 * because some of this league's rules cannot be derived from a box score
 * at all:
 *
 *   - length-of-TD bonuses (PS/RS/RC: +3/+4/+5 for 40+/51+/66+ yard
 *     scores) need each TD's length, which lives only in play text
 *   - field goal points are tiered by kick distance (FG 3/4/5), and a box
 *     score reports makes and attempts, not distances
 *
 * Rather than guess at those and print a total that disagrees with MFL,
 * they fall into the remainder line, which is honest and still leaves the
 * bulk of a score itemised.
 */

/** League scoring rules, flattened to position => [event => [rules]]. */
function rotc_lw_scoring_rules(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $raw = mfl_cached_get('rules', 86400);
    $blocks = mfl_normalize_list($raw['rules']['positionRules'] ?? null);
    $out = [];
    foreach ($blocks as $b) {
        $positions = explode('|', (string) rotc_lw_t($b['positions'] ?? ''));
        foreach (mfl_normalize_list($b['rule'] ?? null) as $r) {
            $event = rotc_lw_t($r['event'] ?? '');
            $points = rotc_lw_t($r['points'] ?? '');
            $range = rotc_lw_t($r['range'] ?? '');
            if ($event === '') continue;
            // Ranges look like "0-99", "-50-999", "40-50": the leading
            // minus belongs to the lower bound, not the separator.
            if (!preg_match('/^(-?\d+(?:\.\d+)?)-(-?\d+(?:\.\d+)?)$/', $range, $m)) continue;
            foreach ($positions as $pos) {
                // Appending (never replacing) is what keeps the shared
                // block and the position-specific block both in play.
                $out[$pos][$event][] = [
                    'lo' => (float) $m[1], 'hi' => (float) $m[2], 'points' => $points,
                ];
            }
        }
    }
    return $cache = $out;
}

/** MFL's JSON wraps text nodes as {"$t": "..."}. */
function rotc_lw_t($v): string {
    if (is_array($v)) return (string) ($v['$t'] ?? '');
    return (string) $v;
}

/**
 * Points for one event given its total, or null when this league's rule
 * for it can't be applied to a season total.
 *
 * Three point formats appear in the rules:
 *   "*0.04"  0.04 per unit
 *   "-2/1"   -2 per 1 unit
 *   "3"      a flat 3 when the value falls in the range -- a milestone
 *            bonus (100 rushing yards, 150 from scrimmage), which a total
 *            CAN express, so it is applied.
 *
 * The flat rules that can't be handled are the ones keyed to a single
 * play's length (PS/RS/RC/FG). Those are never fed a value here, so they
 * never match, and their points land in the remainder instead.
 */
function rotc_lw_event_points(string $pos, string $event, float $value, array $rules): ?float {
    if ($value == 0.0) return null;

    // EVERY block listing this position contributes. MFL stacks a
    // position-specific rule on top of the shared one rather than
    // replacing it: a running back gets the shared per-yard rate AND the
    // RB-only 100-yard bonus. Taking the first matching block instead
    // scored rushing yards at zero for every RB -- Derrick Henry came out
    // 19.68 points short before this was fixed.
    $set = $rules[$pos][$event] ?? [];
    if (!$set) return null;

    $total = 0.0;
    $hit = false;
    foreach ($set as $r) {
        if ($value < $r['lo'] || $value > $r['hi']) continue;
        $p = $r['points'];
        if (preg_match('/^\*(-?[\d.]+)$/', $p, $m)) {
            $total += $value * (float) $m[1]; $hit = true; continue;
        }
        if (preg_match('/^(-?[\d.]+)\/(\d+)$/', $p, $m)) {
            $total += $value * ((float) $m[1] / (float) $m[2]); $hit = true; continue;
        }
        // A flat rule on a measurable total is a milestone bonus (100 rush
        // yards, 150 from scrimmage). Flat rules on events we never supply
        // -- the length-of-TD bonuses -- simply never reach here.
        if (preg_match('/^-?[\d.]+$/', $p)) { $total += (float) $p; $hit = true; }
    }
    return $hit ? $total : null;
}

/**
 * ESPN box score labels -> this league's event codes, per category.
 * Only what a box score can actually answer; everything else is left to
 * the remainder rather than approximated.
 */
const ROTC_LW_STAT_EVENTS = [
    'passing'   => ['YDS' => 'PY', 'TD' => '#P', 'INT' => 'IN'],
    'rushing'   => ['CAR' => null, 'YDS' => 'RY', 'TD' => '#R'],
    'receiving' => ['REC' => 'CC', 'YDS' => 'CY', 'TD' => '#C'],
    'fumbles'   => ['LOST' => 'FL'],
    'defensive' => ['SOLO' => 'TK', 'SACKS' => 'SK', 'PD' => 'PD', 'TFL' => null],
    'interceptions' => ['INT' => 'IC', 'YDS' => 'ICY'],
    'kickReturns'   => ['YDS' => 'KY'],
    'puntReturns'   => ['YDS' => 'UY'],
];

/** Human labels for the breakdown rows. */
const ROTC_LW_EVENT_LABEL = [
    'PY' => 'Pass yds', '#P' => 'Pass TD', 'IN' => 'INT thrown', 'PC' => 'Completions',
    'INC' => 'Incompletions', 'RY' => 'Rush yds', '#R' => 'Rush TD',
    'CC' => 'Receptions', 'CY' => 'Rec yds', '#C' => 'Rec TD', 'FL' => 'Fumbles lost',
    'TK' => 'Tackles', 'AS' => 'Assists', 'SK' => 'Sacks', 'PD' => 'Passes def',
    'IC' => 'INT caught', 'ICY' => 'INT ret yds', 'KY' => 'KR yds', 'UY' => 'PR yds',
    'TYS' => 'Scrimmage yds bonus', 'TY' => 'Total yds bonus',
];

/**
 * Raw stats per ESPN athlete id, as MFL event codes.
 * Matching is on athlete id (MFL carries espn_id), so it is exact.
 */
function rotc_lw_espn_events(array $mflTeams, ?string $date = null): array {
    $games = rotc_lw_espn_games($date);
    $ids = [];
    foreach ($mflTeams as $t) {
        $ab = rotc_lw_espn_team((string) $t);
        if (isset($games[$ab])) $ids[$games[$ab]] = true;
    }

    $out = [];
    foreach (array_keys($ids) as $gid) {
        $sum = rotc_lw_espn_get(
            'https://site.api.espn.com/apis/site/v2/sports/football/nfl/summary?event='
            . urlencode((string) $gid), 60);
        if (!$sum) continue;

        foreach ((array) ($sum['boxscore']['players'] ?? []) as $teamBlock) {
            foreach ((array) ($teamBlock['statistics'] ?? []) as $cat) {
                $cname = (string) ($cat['name'] ?? '');
                $map = ROTC_LW_STAT_EVENTS[$cname] ?? null;
                if (!$map) continue;
                $labels = (array) ($cat['labels'] ?? []);
                foreach ((array) ($cat['athletes'] ?? []) as $a) {
                    $aid = (string) ($a['athlete']['id'] ?? '');
                    if ($aid === '') continue;
                    $stats = (array) ($a['stats'] ?? []);
                    foreach ($labels as $k => $label) {
                        $v = (string) ($stats[$k] ?? '');
                        if ($v === '') continue;

                        // "22/33" completions/attempts carries two events.
                        if ($label === 'C/ATT' && strpos($v, '/') !== false) {
                            [$c, $att] = array_map('floatval', explode('/', $v, 2));
                            $out[$aid]['PC'] = $c;
                            $out[$aid]['INC'] = max(0, $att - $c);
                            continue;
                        }
                        // Defensive TOT includes assists; MFL scores them apart.
                        if ($cname === 'defensive' && $label === 'TOT') {
                            $out[$aid]['_TOT'] = (float) $v;
                            continue;
                        }
                        $ev = $map[$label] ?? null;
                        if ($ev === null) continue;
                        $out[$aid][$ev] = (float) $v;
                    }
                }
            }
        }
    }
    foreach ($out as $aid => $ev) {
        if (isset($ev['_TOT'], $ev['TK'])) {
            $out[$aid]['AS'] = max(0, $ev['_TOT'] - $ev['TK']);
        }
        unset($out[$aid]['_TOT']);
    }
    return $out;
}

/**
 * Itemised scoring for one player.
 *
 * Returns ['rows' => [[label, stat, points], ...], 'other' => float],
 * where 'other' is whatever MFL's official score contains that these
 * rows don't -- length-of-TD bonuses, field goal distances, yardage
 * milestones. It is shown rather than hidden, so the column always
 * reconciles to the real score.
 */
function rotc_lw_breakdown(string $pos, array $events, float $officialScore): array {
    $rules = rotc_lw_scoring_rules();
    $pos = strtoupper($pos) ?: 'WR';
    $rows = [];
    $sum = 0.0;

    // Combined-yardage bonuses (TYS from scrimmage, TY total) are real
    // rules in this league but no box score reports them, so they're
    // derived from the components before scoring.
    $scrim = ($events['RY'] ?? 0) + ($events['CY'] ?? 0);
    if ($scrim > 0) $events['TYS'] = $scrim;
    $all = $scrim + ($events['PY'] ?? 0) + ($events['KY'] ?? 0) + ($events['UY'] ?? 0);
    if ($all > 0) $events['TY'] = $all;

    // Fixed order so a row doesn't jump around between refreshes. The two
    // derived bonuses sit last -- they're a consequence of the rows above.
    $order = ['PC', 'INC', 'PY', '#P', 'IN', 'RY', '#R', 'CC', 'CY', '#C',
              'FL', 'TK', 'AS', 'SK', 'PD', 'IC', 'ICY', 'KY', 'UY', 'TYS', 'TY'];
    foreach ($order as $ev) {
        if (!isset($events[$ev])) continue;
        $val = (float) $events[$ev];
        if ($val == 0.0) continue;
        $pts = rotc_lw_event_points($pos, $ev, $val, $rules);
        if ($pts === null) continue;
        $rows[] = [
            'label' => ROTC_LW_EVENT_LABEL[$ev] ?? $ev,
            'stat'  => rtrim(rtrim(number_format($val, 1), '0'), '.'),
            'pts'   => round($pts, 2),
        ];
        $sum += $pts;
    }

    return ['rows' => $rows, 'other' => round($officialScore - $sum, 2)];
}
