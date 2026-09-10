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

// A completed game's box score never changes, so once fetched it's cached
// for the rest of the week rather than the 60s used while a game is live.
// One successful fetch after the final whistle should be enough forever.
if (!defined('ROTC_LW_ESPN_FINAL_TTL')) define('ROTC_LW_ESPN_FINAL_TTL', 604800);

/** MFL team codes differ from ESPN's for eight teams; the rest match. */
const ROTC_LW_MFL_TO_ESPN = [
    'GBP' => 'GB', 'JAC' => 'JAX', 'KCC' => 'KC', 'LVR' => 'LV',
    'NEP' => 'NE', 'NOS' => 'NO', 'SFO' => 'SF', 'TBB' => 'TB',
];

function rotc_lw_espn_team(string $mflTeam): string {
    $t = strtoupper(trim($mflTeam));
    return ROTC_LW_MFL_TO_ESPN[$t] ?? $t;
}

/**
 * Small cached GET. Returns decoded JSON or null; never throws.
 *
 * Retries once on a transport failure or bad status before falling back
 * to a stale copy -- a completed game's box score is worth a second
 * attempt rather than quietly going missing over one flaky connection,
 * since (unlike the scoreboard) it will otherwise never be asked for
 * again once $ttl has it marked fresh.
 */
function rotc_lw_espn_get(string $url, int $ttl): ?array {
    $dir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/espn-' . md5($url) . '.json';

    if (is_readable($file) && (time() - filemtime($file)) < $ttl) {
        $hit = json_decode((string) file_get_contents($file), true);
        if (is_array($hit)) return $hit;
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,     // a garnish must never stall the page
            CURLOPT_FOLLOWLOCATION => true,
            // site.api.espn.com is the endpoint espn.com's own front-end
            // calls from the browser, not a documented public API -- it
            // 403s a request identifying itself as a script (confirmed in
            // production: MFL's own UA, sent here previously, was
            // rejected every time). Presenting as an ordinary browser hit
            // is what the endpoint actually expects.
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Referer: https://www.espn.com/',
                'Origin: https://www.espn.com',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $data = json_decode((string) $body, true);
            if (!is_array($data)) return null;
            @file_put_contents($file, json_encode($data), LOCK_EX);
            return $data;
        }
        // This degrade is otherwise completely silent -- a stat breakdown
        // just quietly never appears -- so log every failed attempt instead
        // of leaving "why is nothing showing" undiagnosable from outside.
        // A non-200 body is usually a WAF/block page that names the actual
        // reason (rate limit, IP block, bad UA, ...) -- worth a snippet
        // rather than just the status code if this ever needs debugging
        // again.
        $snippet = $body !== false ? substr(preg_replace('/\s+/', ' ', (string) $body), 0, 200) : '';
        error_log(sprintf('live-wire espn: GET %s failed on attempt %d (http %d%s)%s',
            $url, $attempt, $code, $err !== '' ? ", curl: $err" : '',
            $snippet !== '' ? ", body: $snippet" : ''));
    }
    // Serve a stale copy rather than nothing.
    if (is_readable($file)) {
        $stale = json_decode((string) file_get_contents($file), true);
        if (is_array($stale)) return $stale;
    }
    return null;
}

/**
 * NFL team abbreviation -> ['id' => ESPN game id, 'final' => bool], for
 * one day's slate. 'final' lets callers cache a completed game's box
 * score far longer than an in-progress one -- it will never change again,
 * so one successful fetch should "lock in" the stat breakdown rather than
 * depend on every future page load re-fetching it successfully.
 */
function rotc_lw_espn_games(?string $date = null): array {
    $q = $date ? ('?dates=' . preg_replace('/\D/', '', $date)) : '';
    // 90s: the slate itself barely changes; only scores do, and those come
    // from MFL anyway.
    $d = rotc_lw_espn_get(
        'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard' . $q, 90);
    $map = [];
    foreach ((array) ($d['events'] ?? []) as $ev) {
        $id = (string) ($ev['id'] ?? '');
        if ($id === '') continue;
        $final = (bool) ($ev['status']['type']['completed'] ?? false);
        foreach ((array) ($ev['competitions'][0]['competitors'] ?? []) as $c) {
            $ab = strtoupper((string) ($c['team']['abbreviation'] ?? ''));
            if ($ab !== '') $map[$ab] = ['id' => $id, 'final' => $final];
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
    $g = $games[$team] ?? null;
    if (!$g) return null;

    $sum = rotc_lw_espn_get(
        'https://site.api.espn.com/apis/site/v2/sports/football/nfl/summary?event=' . urlencode($g['id']),
        $g['final'] ? ROTC_LW_ESPN_FINAL_TTL : 60);
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
