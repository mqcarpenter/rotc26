<?php
/**
 * includes/live-wire-espn.php
 * Explains a big play. Optional enrichment for includes/live-wire.php.
 *
 * MFL knows a player's score went up 7.4 and nothing else -- there is no
 * play-by-play anywhere in its API, so on MFL data alone the feed can only
 * ever say "+7.4". ESPN's public NFL feed does carry the play text, the
 * quarter and the game clock, so this joins the two: MFL says WHO and HOW
 * MUCH, ESPN says WHAT HAPPENED.
 *
 * Deliberately lazy. The scoreboard is one small cached call; a game
 * summary (~200KB) is only fetched when a player on that team actually
 * had a big play, which is a handful of times an afternoon. Fetching all
 * sixteen summaries every 30s would be a needless few hundred MB a day
 * for text that is usually not wanted.
 *
 * Everything here degrades to null. ESPN is undocumented and unversioned,
 * so if it changes shape or goes away the feed simply falls back to
 * showing the points jump on its own -- no page should break over a
 * garnish.
 */

/** MFL team codes differ from ESPN's for eight teams; the rest match. */
const ROTC_LW_MFL_TO_ESPN = [
    'GBP' => 'GB', 'JAC' => 'JAX', 'KCC' => 'KC', 'LVR' => 'LV',
    'NEP' => 'NE', 'NOS' => 'NO', 'SFO' => 'SF', 'TBB' => 'TB',
];

function rotc_lw_espn_team(string $mflTeam): string {
    $t = strtoupper(trim($mflTeam));
    return ROTC_LW_MFL_TO_ESPN[$t] ?? $t;
}

/** Small cached GET. Returns decoded JSON or null; never throws. */
function rotc_lw_espn_get(string $url, int $ttl): ?array {
    $dir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/espn-' . md5($url) . '.json';

    if (is_readable($file) && (time() - filemtime($file)) < $ttl) {
        $hit = json_decode((string) file_get_contents($file), true);
        if (is_array($hit)) return $hit;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,     // a garnish must never stall the page
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        // Serve a stale copy rather than nothing.
        if (is_readable($file)) {
            $stale = json_decode((string) file_get_contents($file), true);
            if (is_array($stale)) return $stale;
        }
        return null;
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) return null;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data;
}

/** NFL team abbreviation -> ESPN game id, for one day's slate. */
function rotc_lw_espn_games(?string $date = null): array {
    $q = $date ? ('?dates=' . preg_replace('/\D/', '', $date)) : '';
    // 90s: the slate itself barely changes; only scores do, and those come
    // from MFL anyway.
    $d = rotc_lw_espn_get(
        'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard' . $q, 90);
    $map = [];
    foreach ((array) ($d['events'] ?? []) as $ev) {
        $id = (string) ($ev['id'] ?? '');
        foreach ((array) ($ev['competitions'][0]['competitors'] ?? []) as $c) {
            $ab = strtoupper((string) ($c['team']['abbreviation'] ?? ''));
            if ($ab !== '' && $id !== '') $map[$ab] = $id;
        }
    }
    return $map;
}

/**
 * The play behind a jump, or null.
 *
 * Matches on surname within the play text, most recent play first, and
 * prefers scoring plays -- a 5+ point jump is usually a touchdown, and
 * ESPN's scoringPlays list is far smaller and cleaner than the full
 * drive log. Surname matching is imperfect (two Johnsons in one game
 * would collide), hence returning null rather than guessing when the
 * name doesn't appear at all.
 */
function rotc_lw_espn_explain(string $mflTeam, string $playerName, ?string $date = null): ?array {
    $team = rotc_lw_espn_team($mflTeam);
    if ($team === '') return null;

    $games = rotc_lw_espn_games($date);
    $gid = $games[$team] ?? null;
    if (!$gid) return null;

    $sum = rotc_lw_espn_get(
        'https://site.api.espn.com/apis/site/v2/sports/football/nfl/summary?event=' . urlencode($gid), 60);
    if (!$sum) return null;

    $parts = preg_split('/\s+/', trim($playerName)) ?: [];
    $surname = $parts ? end($parts) : '';
    if (mb_strlen($surname) < 3) return null;

    $candidates = [];
    foreach ((array) ($sum['scoringPlays'] ?? []) as $sp) {
        $candidates[] = [
            'text'   => (string) ($sp['text'] ?? ''),
            'clock'  => (string) ($sp['clock']['displayValue'] ?? ''),
            'period' => (int) ($sp['period']['number'] ?? 0),
            'score'  => true,
        ];
    }
    // Fall back to the drive log for big non-scoring plays (a long catch
    // clears 5 points in most formats without reaching the end zone).
    foreach (array_reverse((array) ($sum['drives']['previous'] ?? [])) as $dr) {
        foreach (array_reverse((array) ($dr['plays'] ?? [])) as $pl) {
            $candidates[] = [
                'text'   => (string) ($pl['text'] ?? ''),
                'clock'  => (string) ($pl['clock']['displayValue'] ?? ''),
                'period' => (int) ($pl['period']['number'] ?? 0),
                'score'  => (bool) ($pl['scoringPlay'] ?? false),
            ];
        }
    }

    $best = null;
    foreach ($candidates as $c) {
        if ($c['text'] === '' || stripos($c['text'], $surname) === false) continue;
        // Scoring plays win; otherwise take the first (most recent) hit.
        if ($c['score']) { $best = $c; break; }
        if ($best === null) $best = $c;
    }
    if ($best === null) return null;

    return [
        'text'   => mb_substr($best['text'], 0, 160),
        'clock'  => $best['clock'],
        'period' => $best['period'],
    ];
}
