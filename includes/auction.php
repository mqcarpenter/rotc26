<?php
/**
 * includes/auction.php
 * Data layer for MFL's live AUCTION (the O=43 "Auction Bid" flow), the
 * mirror of includes/draft-board.php for the snake draft.
 *
 * LIVE SOURCE (confirmed live 2026-09-01, league 67102):
 *   {baseURL}/fflnetdynamic{YEAR}/{L}_LEAGUE_auction_results.xml
 * Public (no APIKEY, no cookie -- confirmed by fetching it anonymously),
 * refreshed continuously, same family as the draft's
 * {L}_LEAGUE_draft_results.xml. Root element:
 *
 *   <auctionResults resumed="0" currentNominator="" nextNominator=""
 *                   over="" autorotateTimestamp="1" paused="0"
 *                   lastAuctionOver="0" timestamp="1788269078">
 *     <franchise id="0011" startingFunds="$500" spent="$0.00"
 *                max="$515.00" numPlayers="27" openSpots="0"
 *                canNominate="" />
 *     ...
 *   </auctionResults>
 *
 * The 16 <franchise> rows are the same numbers MFL prints in the
 * "Financial Status" / "Roster Status" boxes on the O=43 page, so the
 * per-franchise budget panel on draft-auction/auction-bid.php is real
 * data, not a scrape.
 *
 * AUCTION ELEMENTS -- CONFIRMED LIVE 2026-09-01 against a running
 * auction, and again 2026-09-03 after four had closed:
 *
 *   open:   <auction player="16704" timeStarted="1788270711"
 *                    highBid="15.00" formattedHighBid="$15.00"
 *                    highBidder="0001" lastBidTime="1788270711" />
 *   closed: ... same, plus completed="1788364670"
 *
 * The root picks up currentPlayer / timeStarted / lastBidTime while
 * bidding is open.
 *
 * CRITICAL: closed auctions are NOT removed from this file -- they stay
 * forever and are distinguished ONLY by the `completed` attribute. The
 * first version of this parser treated every <auction> as open, which
 * (once four had closed) reported "4 Active, $60 bid" on a league with
 * nothing running, double-counting the same auctions as both open and
 * closed. `completed` is therefore the split, not a detail.
 *
 * The close timestamps also confirm the 24h rule from the data side:
 * three of the four closed 24.0h / 24.1h / 24.8h after their opening
 * bid. (The fourth ran 26.1h -- MFL sweeps periodically rather than
 * closing on the exact second, so ROTC_AUCTION_DURATION is right for
 * "when bidding ends" and the actual close lands at MFL's next sweep.)
 *
 * There is NO end time in the feed, but the rule is known: this league
 * runs a HARD STOP 24 hours after the opening bid -- confirmed by the
 * commissioner, and consistent with MFL's own page showing "23 hours, 59
 * minutes" left on an auction one minute old. A later bid does NOT
 * extend the clock, so the deadline is simply:
 *
 *     ends = timeStarted + ROTC_AUCTION_DURATION
 *
 * That's a league setting, not something MFL publishes, so it lives in
 * one named constant below rather than being scattered as a magic 86400.
 * If the league ever switches to an extending/"going once" clock, this
 * is the single thing to change -- and 'ends' would then have to come
 * off lastBidTime instead of timeStarted.
 *
 * Completed auctions come from the documented export instead
 * (TYPE=auctionResults -> auctionResults.auctionUnit.auction[]), which
 * is post-hoc and reliable.
 *
 * Requires config.php + includes/mfl-api.php. rotc_auction_nominate()
 * additionally requires includes/mfl-auth.php and a logged-in owner.
 */

/**
 * How long an auction runs, in seconds, from its OPENING bid. A hard
 * stop -- later bids don't extend it. Not published by MFL anywhere in
 * the API or the live feed; this is the league's own rule (see the file
 * header). MFL flags an auction visually once it's more than 90% over,
 * and ROTC_AUCTION_CLOSING_FRACTION mirrors that threshold so this site
 * warns at the same moment MFL does.
 */
const ROTC_AUCTION_DURATION = 86400;
const ROTC_AUCTION_CLOSING_FRACTION = 0.9;

/** Cache dir shared with mfl-api.php's file cache. */
function rotc_auction_cache_path(string $key): string {
    $dir = sys_get_temp_dir() . '/rotc-mfl-cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . $key . '.xml';
}

/**
 * Raw live auction XML body, cached $ttl seconds. $force skips the cache
 * entirely -- used right after a nomination POST to verify it landed.
 * Returns null if the file can't be fetched and nothing is cached.
 */
function rotc_auction_fetch_xml(int $ttl = 30, bool $force = false): ?string {
    $leagueId = defined('MFL_LEAGUE_ID') ? MFL_LEAGUE_ID : '';
    $year     = defined('MFL_YEAR') ? MFL_YEAR : date('Y');
    $cacheFile = rotc_auction_cache_path("auction-live-{$leagueId}-{$year}");

    if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') return $cached;
    }

    $league  = mfl_cached_get('league', 86400);
    $baseUrl = rtrim((string) ($league['league']['baseURL'] ?? ''), '/');
    if ($baseUrl === '') {
        $baseUrl = 'https://' . (defined('MFL_SERVER_BASE') ? MFL_SERVER_BASE : 'www42.myfantasyleague.com');
    }
    $url = "{$baseUrl}/fflnetdynamic{$year}/{$leagueId}_LEAGUE_auction_results.xml";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200
          && strpos((string) $body, '<auctionResults') !== false;

    if ($ok) { @file_put_contents($cacheFile, $body); return $body; }
    // MFL hiccuped -- a stale copy beats an empty page.
    if (file_exists($cacheFile)) {
        $stale = file_get_contents($cacheFile);
        if ($stale !== false && $stale !== '') return $stale;
    }
    return null;
}

/** "$515.00" / "$500" / "" -> float. MFL money fields carry the sign. */
function rotc_auction_money(?string $raw): float {
    if ($raw === null) return 0.0;
    $clean = preg_replace('/[^0-9.\-]/', '', $raw);
    return $clean === '' || $clean === '-' ? 0.0 : (float) $clean;
}

/**
 * First non-empty attribute among $names (case-insensitive), so an
 * unconfirmed attribute name doesn't cost us the whole column. See the
 * "NOT CONFIRMED" note at the top of this file.
 */
function rotc_auction_attr(array $attrs, array $names, string $default = ''): string {
    $lower = [];
    foreach ($attrs as $k => $v) $lower[strtolower((string) $k)] = (string) $v;
    foreach ($names as $n) {
        $n = strtolower($n);
        if (isset($lower[$n]) && $lower[$n] !== '') return $lower[$n];
    }
    return $default;
}

/**
 * Parsed live auction state:
 *   ['ok'=>bool, 'timestamp'=>int, 'paused'=>bool, 'over'=>bool,
 *    'currentNominator'=>str, 'nextNominator'=>str,
 *    'franchises'=>[id => ['startingFunds'=>f,'spent'=>f,'max'=>f,
 *                          'numPlayers'=>int,'openSpots'=>int,
 *                          'canNominate'=>bool]],
 *    'auctions'=>[ ... ],   // OPEN only
 *    'closed'=>[ ... ]]     // same shape + 'completed'=>int
 * Each auction: ['player'=>str,'bid'=>float,'bidder'=>str,
 *                'started'=>int,'lastBid'=>int,'ends'=>int]
 */
function rotc_auction_parse_live(?string $body): array {
    $out = [
        'ok' => false, 'timestamp' => 0, 'paused' => false, 'over' => false,
        'currentNominator' => '', 'nextNominator' => '',
        'currentPlayer' => '', 'lastBidTime' => 0,
        'franchises' => [], 'auctions' => [], 'closed' => [],
    ];
    if ($body === null || $body === '') return $out;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) return $out;

    $root = [];
    foreach ($xml->attributes() as $k => $v) $root[(string) $k] = (string) $v;
    $out['ok']               = true;
    $out['timestamp']        = (int) ($root['timestamp'] ?? 0);
    $out['paused']           = !empty($root['paused']) && $root['paused'] !== '0';
    $out['over']             = !empty($root['over']) && $root['over'] !== '0';
    $out['currentNominator'] = (string) ($root['currentNominator'] ?? '');
    $out['nextNominator']    = (string) ($root['nextNominator'] ?? '');
    $out['currentPlayer']    = (string) ($root['currentPlayer'] ?? '');
    $out['lastBidTime']      = (int) ($root['lastBidTime'] ?? 0);

    foreach ($xml->children() as $child) {
        $attrs = [];
        foreach ($child->attributes() as $k => $v) $attrs[(string) $k] = (string) $v;

        if (strtolower($child->getName()) === 'franchise') {
            $id = (string) ($attrs['id'] ?? '');
            if ($id === '') continue;
            $out['franchises'][$id] = [
                'startingFunds' => rotc_auction_money($attrs['startingFunds'] ?? null),
                'spent'         => rotc_auction_money($attrs['spent'] ?? null),
                'max'           => rotc_auction_money($attrs['max'] ?? null),
                'numPlayers'    => (int) ($attrs['numPlayers'] ?? 0),
                'openSpots'     => (int) ($attrs['openSpots'] ?? 0),
                'canNominate'   => !empty($attrs['canNominate']) && $attrs['canNominate'] !== '0',
            ];
            continue;
        }

        // Anything that isn't a <franchise> row is an auction -- open or
        // long since closed. Confirmed attribute names lead each list
        // (see the file header); the alternates are insurance.
        $playerId = rotc_auction_attr($attrs, ['player', 'playerId', 'player_id', 'id']);
        if ($playerId === '') continue;

        $started = (int) rotc_auction_attr($attrs, ['timeStarted', 'started', 'startTime', 'start'], '0');
        $row = [
            'player'  => $playerId,
            'bid'     => rotc_auction_money(rotc_auction_attr($attrs, ['highBid', 'formattedHighBid', 'bid', 'currentBid', 'amount'])),
            'bidder'  => rotc_auction_attr($attrs, ['highBidder', 'franchise', 'bidder', 'franchiseId']),
            'started' => $started,
            'lastBid' => (int) rotc_auction_attr($attrs, ['lastBidTime', 'lastBid', 'last_bid_time'], '0'),
            // Derived, not read: see ROTC_AUCTION_DURATION. Only
            // meaningful with a real start time -- a 0 start would
            // produce a deadline in 1970 and mark everything as closing.
            'ends'    => $started > 0 ? $started + ROTC_AUCTION_DURATION : 0,
        ];

        // THE split (see the file header): a closed auction stays in this
        // file forever, carrying `completed`. Anything else is live.
        $completed = (int) rotc_auction_attr($attrs, ['completed', 'completedTime', 'ended'], '0');
        if ($completed > 0) {
            $row['completed'] = $completed;
            $out['closed'][] = $row;
        } else {
            $out['auctions'][] = $row;
        }
    }
    return $out;
}

/** Convenience: fetch + parse in one call. */
function rotc_auction_live(int $ttl = 30, bool $force = false): array {
    return rotc_auction_parse_live(rotc_auction_fetch_xml($ttl, $force));
}

/**
 * Completed auctions, from the documented post-hoc export:
 *   [ ['player'=>id, 'franchise'=>id, 'bid'=>float, 'ts'=>int], ... ]
 * Empty while this season's auction hasn't produced a winner yet --
 * MFL returns a bare auctionUnit with no <auction> children then
 * (confirmed live 2026-09-01), which is not an error.
 */
function rotc_auction_completed(): array {
    $raw = mfl_cached_get('auctionResults', 600, []);
    $unit = $raw['auctionResults']['auctionUnit'] ?? null;
    if (is_array($unit) && array_keys($unit) === range(0, max(0, count($unit) - 1)) && isset($unit[0])) {
        $unit = $unit[0];
    }
    $out = [];
    foreach (mfl_normalize_list($unit['auction'] ?? null) as $a) {
        $out[] = [
            'player'    => (string) ($a['player'] ?? ''),
            'franchise' => (string) ($a['franchise'] ?? ''),
            'bid'       => rotc_auction_money((string) ($a['winningBid'] ?? $a['lastBidAmount'] ?? '')),
            'ts'        => (int) ($a['lastBidTime'] ?? $a['timestamp'] ?? 0),
        ];
    }
    return $out;
}

/**
 * The four numbers the front-page pill strip shows:
 *   active     - open auctions right now (live XML)
 *   bidTotal   - dollars currently committed across those open auctions.
 *                MFL exposes a current high bid per auction, not a count
 *                of bids placed, so "total bids" is money on the table --
 *                the only honest reading of the data that exists.
 *   completed  - auctions that have closed and awarded a player
 *   spent      - dollars actually spent. Prefers the sum of winning bids
 *                (exact, per player); falls back to the live file's
 *                per-franchise 'spent' totals if the export hasn't caught
 *                up yet, since that's the same figure MFL shows owners.
 *
 * 'completed' comes from the export rather than the live file's closed
 * rows, but the live file backstops it: the export is the documented
 * source and carries the winning bid per player, while the live file is
 * what updates first. Whichever knows about more closed auctions wins,
 * so the count can never go backwards between the two.
 */
function rotc_auction_summary(): array {
    $live = rotc_auction_live(60);
    $completed = rotc_auction_completed();

    $bidTotal = 0.0;
    foreach ($live['auctions'] as $a) $bidTotal += $a['bid'];

    $spent = 0.0;
    foreach ($completed as $c) $spent += $c['bid'];
    if ($spent <= 0.0) {
        foreach ($live['franchises'] as $f) $spent += $f['spent'];
    }

    // Soonest hard deadline across the open auctions -- the one piece of
    // auction state that's genuinely time-critical, so the front page
    // gets it without having to open the auction page.
    $soonestEnd = 0;
    foreach ($live['auctions'] as $a) {
        if ($a['ends'] > 0 && ($soonestEnd === 0 || $a['ends'] < $soonestEnd)) $soonestEnd = $a['ends'];
    }

    return [
        'active'     => count($live['auctions']),
        'bidTotal'   => $bidTotal,
        'completed'  => max(count($completed), count($live['closed'])),
        'spent'      => $spent,
        'live'       => $live['ok'],
        'paused'     => $live['paused'],
        'timestamp'  => $live['timestamp'],
        'soonestEnd' => $soonestEnd,
    ];
}

/**
 * Timestamp formatted in league time ("Tue 8:51am"). Explicitly ET
 * rather than the server's default zone: the host's timezone isn't
 * guaranteed (a local check rendered MFL's 8:51 a.m. CT auction as
 * 1:51pm UTC), and an auction start time that's five hours off is worse
 * than useless. index.php already pins the league's own dates to
 * America/New_York the same way.
 */
function rotc_auction_when(int $ts): string {
    if ($ts <= 0) return '--';
    try {
        return (new DateTime('@' . $ts))
            ->setTimezone(new DateTimeZone('America/New_York'))
            ->format('D g:ia');
    } catch (Exception $e) {
        return date('D g:ia', $ts);
    }
}

/**
 * "3h 42m" / "12m" / "Closed" -- time remaining until a hard deadline.
 * Hours and minutes, no seconds: these run a full day, and a ticking
 * seconds counter would be false precision on a feed cached for 20s.
 */
function rotc_auction_time_left(int $endsAt): string {
    if ($endsAt <= 0) return '--';
    $s = $endsAt - time();
    if ($s <= 0) return 'Closed';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    if ($h >= 1) return $h . 'h ' . $m . 'm';
    return max(1, $m) . 'm';
}

/**
 * Is this auction into MFL's "more than 90% over" window -- the point
 * its own page starts flagging the row? Drives the same warning styling
 * here so the two agree.
 */
function rotc_auction_is_closing(int $endsAt): bool {
    if ($endsAt <= 0) return false;
    $left = $endsAt - time();
    return $left > 0 && $left < ROTC_AUCTION_DURATION * (1 - ROTC_AUCTION_CLOSING_FRACTION);
}

/**
 * "3m ago" / "4h ago" / "2d ago" for a unix timestamp. Hours and days,
 * not m:ss -- at the scale email auctions actually run, seconds are
 * noise. Same reasoning as the draft module's since-last-pick clock.
 */
function rotc_auction_ago(int $ts): string {
    if ($ts <= 0) return '--';
    $s = max(0, time() - $ts);
    if ($s < 60)    return 'just now';
    if ($s < 3600)  return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    return floor($s / 86400) . 'd ago';
}

/** "$1,234" / "$12.50" -- whole dollars unless there are real cents. */
function rotc_auction_fmt_money(float $v): string {
    return '$' . (fmod($v, 1.0) == 0.0 ? number_format($v) : number_format($v, 2));
}

/**
 * Put a player up for auction as $franchiseId at $openingBid.
 *
 * This posts MFL's own O=43 form, NOT an import API call: confirmed
 * against MFL's import reference (and re-confirmed live 2026-09-01) that
 * live auction nomination/bidding is not part of the documented API at
 * all -- the only auctionResults import is the bulk post-hoc load meant
 * for an offline auction. The real form was captured from a live O=43
 * page for franchise 0014:
 *
 *   POST {leagueHost}/{YEAR}/auction_bid
 *        LEAGUE_ID, FRANCHISE_ID, PLAYER_PICK, OPENING_BID, MSG
 *
 * carrying the owner's own MFL session cookie (the same one
 * includes/mfl-auth.php stores at login) -- so this acts strictly as the
 * logged-in owner, never as the site.
 *
 * The response is an HTML page, not XML/JSON, so success is verified by
 * re-reading the live auction file uncached and checking the player is
 * now in an open auction. That is a fact about MFL's state rather than a
 * guess at parsing its HTML.
 *
 * Returns ['ok'=>bool, 'error'=>?string].
 */
function rotc_auction_nominate(string $franchiseId, string $playerId, string $openingBid, string $msg = ''): array {
    if (!function_exists('rotc_mfl_logged_in') || !rotc_mfl_logged_in()) {
        return ['ok' => false, 'error' => 'You need to be logged in to MyFantasyLeague to start an auction.'];
    }
    if ($playerId === '')  return ['ok' => false, 'error' => 'Pick a player to put up for auction.'];
    if (!is_numeric($openingBid) || (float) $openingBid <= 0) {
        return ['ok' => false, 'error' => 'Enter a valid opening bid.'];
    }

    rotc_session_start();
    $year = defined('MFL_YEAR') ? (int) MFL_YEAR : (int) date('Y');
    $host = rotc_mfl_league_host($year);

    $ch = curl_init("{$host}/{$year}/auction_bid");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'LEAGUE_ID'    => defined('MFL_LEAGUE_ID') ? MFL_LEAGUE_ID : '',
            'FRANCHISE_ID' => $franchiseId,
            'PLAYER_PICK'  => $playerId,
            'OPENING_BID'  => number_format((float) $openingBid, 2, '.', ''),
            'MSG'          => $msg,
        ]),
        CURLOPT_COOKIE         => $_SESSION['rotc_mfl_cookie_name'] . '=' . rawurlencode($_SESSION['rotc_mfl_cookie_value']),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errNo = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false || $errNo !== 0) {
        return ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.'];
    }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "MyFantasyLeague returned HTTP {$code}."];
    }

    // Did it actually take? Re-read the live file with the cache bypassed.
    $live = rotc_auction_live(0, true);
    foreach ($live['auctions'] as $a) {
        if ($a['player'] === $playerId) return ['ok' => true, 'error' => null];
    }

    // No confirmation -- surface whatever MFL said rather than a shrug.
    return ['ok' => false, 'error' => rotc_auction_extract_error((string) $body)];
}

/**
 * Raise the bid on auctions already running, as $franchiseId.
 * $bids is [playerId => amount]; $comments is an optional
 * [playerId => text]. Returns ['ok'=>bool, 'error'=>?string,
 * 'placed'=>[playerId, ...]].
 *
 * CONFIRMED LIVE 2026-09-01 off a real running auction -- this was the
 * one piece that couldn't be built when the auction page first went up,
 * because with no auction open MFL rendered no bid row to read. The form
 * names each bid input after the PLAYER ID itself, with the comment
 * field prefixed:
 *
 *   POST {leagueHost}/{YEAR}/auction_bid
 *        LEAGUE_ID, FRANCHISE_ID, <playerId>=25.00, CMT_<playerId>=...
 *
 * MFL's own page validates with validateBid(input, <current high bid>)
 * and states "All bids must be in increments of $5.00" (bidIncrement in
 * the league settings), so callers should pre-validate -- but MFL stays
 * the authority and its rejection is surfaced verbatim.
 *
 * Like rotc_auction_nominate(), success is confirmed against the live
 * feed (the high bid moved to what we sent, or we're the high bidder)
 * rather than by parsing MFL's HTML reply.
 */
function rotc_auction_bid(string $franchiseId, array $bids, array $comments = []): array {
    if (!function_exists('rotc_mfl_logged_in') || !rotc_mfl_logged_in()) {
        return ['ok' => false, 'error' => 'You need to be logged in to MyFantasyLeague to bid.', 'placed' => []];
    }
    $fields = [
        'LEAGUE_ID'    => defined('MFL_LEAGUE_ID') ? MFL_LEAGUE_ID : '',
        'FRANCHISE_ID' => $franchiseId,
    ];
    $wanted = [];
    foreach ($bids as $playerId => $amount) {
        $playerId = (string) $playerId;
        if ($playerId === '' || !is_numeric($amount) || (float) $amount <= 0) continue;
        $fields[$playerId] = number_format((float) $amount, 2, '.', '');
        $wanted[$playerId] = (float) $amount;
        $cmt = trim((string) ($comments[$playerId] ?? ''));
        if ($cmt !== '') $fields['CMT_' . $playerId] = substr($cmt, 0, 50);
    }
    if (!$wanted) return ['ok' => false, 'error' => 'Enter a bid amount on at least one auction.', 'placed' => []];

    rotc_session_start();
    $year = defined('MFL_YEAR') ? (int) MFL_YEAR : (int) date('Y');
    $host = rotc_mfl_league_host($year);

    $ch = curl_init("{$host}/{$year}/auction_bid");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_COOKIE         => $_SESSION['rotc_mfl_cookie_name'] . '=' . rawurlencode($_SESSION['rotc_mfl_cookie_value']),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body  = curl_exec($ch);
    $errNo = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false || $errNo !== 0) {
        return ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.', 'placed' => []];
    }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "MyFantasyLeague returned HTTP {$code}.", 'placed' => []];
    }

    // Confirm against the live feed, cache bypassed. A proxy bid means
    // the high bid can land BELOW what we sent while we're still the
    // high bidder, so being the high bidder counts as placed -- checking
    // only for an exact amount match would report a winning proxy bid as
    // a failure.
    $live = rotc_auction_live(0, true);
    $placed = [];
    foreach ($live['auctions'] as $a) {
        if (!isset($wanted[$a['player']])) continue;
        if ($a['bidder'] === $franchiseId || $a['bid'] >= $wanted[$a['player']]) {
            $placed[] = $a['player'];
        }
    }
    if (count($placed) === count($wanted)) return ['ok' => true, 'error' => null, 'placed' => $placed];

    return [
        'ok'     => false,
        'error'  => rotc_auction_extract_error((string) $body),
        'placed' => $placed,
    ];
}

/**
 * Best-effort error text out of an MFL HTML response. MFL renders
 * problems as plain prose in the page body, so this looks for an
 * error-classed block first, then any sentence that reads like a
 * rejection, then gives up with a message that says so honestly rather
 * than claiming success.
 */
function rotc_auction_extract_error(string $html): string {
    if (preg_match('~<[^>]+class="[^"]*error[^"]*"[^>]*>(.*?)</~is', $html, $m)) {
        $text = trim(html_entity_decode(strip_tags($m[1])));
        if ($text !== '') return $text;
    }
    $text = html_entity_decode(strip_tags(preg_replace('~(?s)<(script|style).*?</\1>~i', ' ', $html)));
    foreach (preg_split('/[\r\n.]+/', $text) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line === '' || strlen($line) > 200) continue;
        if (preg_match('/\b(cannot|can not|not allowed|no longer|must be|too low|insufficient|invalid|already|error)\b/i', $line)) {
            return $line;
        }
    }
    return 'MyFantasyLeague accepted the request but the auction did not show up. Check the Auction Bid page on MFL before trying again.';
}
