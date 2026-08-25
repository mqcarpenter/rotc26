<?php
/**
 * mobile.php
 * True mobile dashboard at /mobile -- a dedicated, lightweight app
 * shell, NOT another page hung off the full desktop site chrome. No
 * mega-menu, no WhatsApp icon, no three-column footer: a slim top
 * bar, one task panel visible at a time, and a bottom tab bar (same
 * shape as a native app). All five tasks are embedded directly on
 * this one URL -- switching tabs never reloads the page. Tab
 * switching is pure CSS (5 hidden radio inputs + sibling selectors in
 * assets/mobile-dashboard.css), the same no-JS toggle technique
 * templates/header.php already uses for the desktop burger menu and
 * dropdowns, so this page works with JS off too.
 *
 * Supersedes the earlier franchise/manage.php pass (a card grid that
 * still linked out to the full desktop pages). Per Matteo's
 * correction, that wasn't "true mobile" -- it just hung a new page off
 * the same heavy chrome. franchise/manage.php is left in this bundle
 * only for reference/diffing and isn't linked from anywhere; delete it
 * before pushing.
 *
 * LIVE-WIRING IN PROGRESS. The Lineup panel (rosters + projectedScores
 * + nflSchedule, real import?TYPE=lineup) and the Drop panel (rosters +
 * injuries + playerScores, real import?TYPE=fcfsWaiver) are wired to
 * real MFL data, mirroring franchise/submit-lineup.php and
 * franchise/drop-player.php. Trade is wired (pendingTrades +
 * tradeResponse for accept/reject/revoke, tradeProposal for new offers,
 * TYPE=assets for picks), mirroring franchise/offer-trade.php. Both
 * Pick 'Ems submit real import?TYPE=poolPicks (POOLTYPE=NFL from
 * TYPE=nflSchedule, POOLTYPE=Fantasy from TYPE=schedule), mirroring
 * franchise/pool-pick.php. The active tab is persisted across reloads
 * via a hidden `tab` field.
 *
 * ALL FIVE PANELS ARE NOW LIVE. One caveat carried over from
 * franchise/rotc-pickem.php: the schedule.weeklySchedule.matchup key
 * path for the Fantasy pool isn't confirmed live -- hit
 * /mobile?tab=rotc&debug=rotcsched to dump TYPE=schedule and confirm the
 * parse before trusting the ROTC Pick 'Em matchups.
 *
 * Deliberately kept as ONE file/one shared context (one franchise list,
 * one league fetch) rather than five separate includes re-fetching per
 * tab.
 */

$page_title = 'Manage — Return of the Champions';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);

// Site-root base path, same derivation templates/header.php uses (from
// where THIS file sits on disk, not the request path). Required here,
// not optional: this page is SERVED AT /mobile as this folder's
// DirectoryIndex (mobile/index.php), so a relative asset href like
// "assets/mfl26.css" would resolve against /mobile/ and give
// /mobile/assets/... and a 404. Every asset URL and internal link below
// is therefore root-relative via $base. Because this file lives one
// level down (mobile/), the site root on disk is dirname(__DIR__) — the
// same one-level walk-up templates/header.php does from templates/.
$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

// Owner-only — no public view. Same gate every franchise/*.php action
// page uses: redirects to /login.php (returning here afterward) unless
// the MFL owner login captured at login is present in the session.
// Guarded by $hasConfig so a no-config preview still renders the mock;
// on the live server config.php exists, so this always enforces.
$ownerUsername  = null;
$ownerHelmetUrl = null;
if ($hasConfig) {
    require_once $configPath;
    require_once dirname(__DIR__) . '/includes/mfl-api.php';
    require_once dirname(__DIR__) . '/includes/mfl-auth.php';
    require_once dirname(__DIR__) . '/includes/helmets.php';
    rotc_require_login($base);

    // Past the gate the owner is logged in and their franchise resolved.
    // Surface who, as a helmet indicator in the top bar — the same custom
    // helmet art the desktop nav's coach pill uses (includes/helmets.php).
    $ownerUsername    = rotc_mfl_username();
    $ownerFranchiseId = rotc_mfl_franchise_id();
    $ownerHelmetUrl   = $ownerFranchiseId ? rotc_helmet_src($ownerFranchiseId) : null;
}

// ---- Shared live context: one pass, feeds every panel ----
$myFranchiseName = 'My Team';
$week       = 1;
$endWeek    = 17;
$league     = [];
$waiverType = 'NONE';
$waiverLink = '';
if ($hasConfig) {
    $franchises      = mfl_franchises();
    $myFranchiseName = $franchises[$ownerFranchiseId]['name'] ?? $myFranchiseName;
    $leagueRaw       = mfl_cached_get('league', 3600);
    $league          = $leagueRaw['league'] ?? [];
    $endWeek         = (int) ($league['endWeek'] ?? 17);
    if ($endWeek < 1) $endWeek = 17;
    $week            = max(1, min($endWeek, (int) ($_POST['week'] ?? $_GET['week'] ?? 1)));
    // Drop path depends on the waiver system: NONE = immediate fcfsWaiver
    // drop; anything else routes through MFL's own waiver page instead.
    $waiverType      = strtoupper((string) ($league['currentWaiverType'] ?? 'NONE'));
    $waiverLink      = 'https://www42.myfantasyleague.com/' . (defined('MFL_YEAR') ? MFL_YEAR : date('Y')) . '/options?L=' . MFL_LEAGUE_ID . '&O=98';
}

// Active tab, persisted across GET/POST reloads -- the single-page CSS
// tabs otherwise snap back to the default (Lineup) on every submit or
// week/target change. Forms carry a hidden `tab`; a trade-target GET
// (?to=) implies the Trade tab.
$validTabs = ['lineup', 'drop', 'trade', 'nfl', 'rotc', 'live'];
$activeTab = (string) ($_POST['tab'] ?? $_GET['tab'] ?? '');
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = ((string) ($_GET['to'] ?? '') !== '') ? 'trade' : 'lineup';
}

// ---- LIVE: Live Wire panel (mirrors scores/live-scoring.php) ----
// Same state builder and same markup as the full-site page (see
// includes/live-wire-view.php) so the two can't drift; only the chrome
// differs. Failure is swallowed -- a dead upstream must not take down
// the whole dashboard, which is also the lineup/drop/trade surface.
$liveState = null;
if ($hasConfig) {
    require_once __DIR__ . '/../includes/live-wire.php';
    require_once __DIR__ . '/../includes/live-wire-espn.php';
    require_once __DIR__ . '/../includes/live-wire-view.php';
    try {
        $liveState = rotc_live_wire_state();
    } catch (Throwable $e) {
        error_log('mobile live-wire: ' . $e->getMessage());
    }
}

// ---- Write-action handler ----
$result = null;
if ($hasConfig && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'lineup') {
        // LIVE: import?TYPE=lineup, same call franchise/submit-lineup.php
        // makes. MFL validates position limits and returns the reason on
        // rejection -- surfaced verbatim rather than swallowed.
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => 'lineup', 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } else {
            $checked = array_filter((array) ($_POST['starters'] ?? []));
            $resp = rotc_mfl_authed_request('import', 'lineup', ['W' => $week, 'STARTERS' => implode(',', $checked)]);
            if ($resp === null) {
                $result = ['action' => 'lineup', 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $result = ['action' => 'lineup', 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $result = ['action' => 'lineup', 'ok' => true];
            }
        }
    } elseif ($action === 'drop') {
        // LIVE: import?TYPE=fcfsWaiver (DROP), same as franchise/drop-player.php.
        // Only valid while the league runs no waivers (currentWaiverType
        // NONE); otherwise drops must go through MFL's waiver page.
        if ($waiverType !== 'NONE') {
            $result = ['action' => 'drop', 'ok' => false, 'error' => 'This league is on a waiver system right now — drop through MyFantasyLeague instead.'];
        } elseif (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => 'drop', 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } else {
            $drop = array_filter((array) ($_POST['drop'] ?? []));
            if (!$drop) {
                $result = ['action' => 'drop', 'ok' => false, 'error' => 'Pick at least one player to drop.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'fcfsWaiver', ['DROP' => implode(',', $drop)]);
                if ($resp === null) {
                    $result = ['action' => 'drop', 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $result = ['action' => 'drop', 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $result = ['action' => 'drop', 'ok' => true];
                }
            }
        }
    } elseif ($action === 'trade') {
        // LIVE: import?TYPE=tradeProposal, same as franchise/offer-trade.php.
        // give_up[]/receive[] mix player ids and draft-pick ids (FP_/DP_)
        // into the single WILL_GIVE_UP / WILL_RECEIVE lists MFL expects.
        $to = (string) ($_POST['offeredto'] ?? '');
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => 'trade', 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } elseif ($to === '' || $to === $ownerFranchiseId || !isset($franchises[$to])) {
            $result = ['action' => 'trade', 'ok' => false, 'error' => 'Choose who to send the offer to.'];
        } else {
            $giveUp  = array_filter((array) ($_POST['give_up'] ?? []));
            $receive = array_filter((array) ($_POST['receive'] ?? []));
            if (!$giveUp || !$receive) {
                $result = ['action' => 'trade', 'ok' => false, 'error' => 'Pick at least one asset on each side of the trade.'];
            } else {
                $params = ['OFFEREDTO' => $to, 'WILL_GIVE_UP' => implode(',', $giveUp), 'WILL_RECEIVE' => implode(',', $receive)];
                $comments = trim((string) ($_POST['comments'] ?? ''));
                if ($comments !== '') $params['COMMENTS'] = $comments;
                $resp = rotc_mfl_authed_request('import', 'tradeProposal', $params);
                if ($resp === null) {
                    $result = ['action' => 'trade', 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $result = ['action' => 'trade', 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $result = ['action' => 'trade', 'ok' => true];
                }
            }
        }
    } elseif ($action === 'traderespond') {
        // LIVE: import?TYPE=tradeResponse (accept / reject / revoke) on an
        // existing pending trade -- a separate import type from tradeProposal.
        $respondAction = (string) ($_POST['respond_action'] ?? '');
        $tradeId       = (string) ($_POST['respond_trade_id'] ?? '');
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => 'trade', 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } elseif (!in_array($respondAction, ['accept', 'reject', 'revoke'], true) || $tradeId === '') {
            $result = ['action' => 'trade', 'ok' => false, 'error' => 'Unknown trade response.'];
        } else {
            $resp = rotc_mfl_authed_request('import', 'tradeResponse', ['TRADE_ID' => $tradeId, 'RESPONSE' => $respondAction]);
            if ($resp === null) {
                $result = ['action' => 'trade', 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
            } elseif (isset($resp['error'])) {
                $result = ['action' => 'trade', 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
            } else {
                $result = ['action' => 'trade', 'ok' => true, 'respond' => ['accept' => 'accepted', 'reject' => 'rejected', 'revoke' => 'revoked'][$respondAction] ?? 'updated'];
            }
        }
    } elseif ($action === 'nflpick' || $action === 'rotcpick') {
        // LIVE: import?TYPE=poolPicks, same shape as franchise/pool-pick.php.
        // Each matchup posts pick_{away}_{home}=<winner id>; MFL wants
        // PICK{away},{home}=<winner> and RANK{away},{home}=1 (plain Pickem,
        // not a confidence pool). Scans the posted pick_* fields directly
        // so it needs neither the NFL nor fantasy schedule rebuilt here.
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $result = ['action' => $action, 'ok' => false, 'error' => 'Your session expired — reload the page and try again.'];
        } else {
            $params = ['POOLTYPE' => ($action === 'nflpick' ? 'NFL' : 'Fantasy'), 'WEEK' => (int) ($_POST['poolweek'] ?? 0)];
            $picked = 0;
            foreach ($_POST as $k => $v) {
                if (strncmp($k, 'pick_', 5) !== 0) continue;
                $winner = trim((string) $v);
                if ($winner === '') continue;
                $rest = substr($k, 5);
                $usc = strrpos($rest, '_'); // away/home ids carry no underscore (NFL codes / numeric franchise ids)
                if ($usc === false) continue;
                $key = substr($rest, 0, $usc) . ',' . substr($rest, $usc + 1);
                $params['PICK' . $key] = $winner;
                $params['RANK' . $key] = '1';
                $picked++;
            }
            if ($picked === 0) {
                $result = ['action' => $action, 'ok' => false, 'error' => 'Pick at least one matchup.'];
            } else {
                $resp = rotc_mfl_authed_request('import', 'poolPicks', $params);
                if ($resp === null) {
                    $result = ['action' => $action, 'ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $result = ['action' => $action, 'ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $result = ['action' => $action, 'ok' => true];
                }
            }
        }
    }
}

// ---- LIVE: Lineup panel (mirrors franchise/submit-lineup.php) ----
// Combined DL (DT+DE) and DB (CB+S) grouping, same as the desktop page,
// matching this IDP league's own combined starter slot types.
$lineup = [];
$lnCurrentFallback = false;
if ($hasConfig) {
    $lnSections = ['QB', 'RB', 'WR', 'TE', 'DL', 'LB', 'DB'];
    $lnBucket   = ['QB'=>'QB','RB'=>'RB','WR'=>'WR','TE'=>'TE','DT'=>'DL','DE'=>'DL','LB'=>'LB','CB'=>'DB','S'=>'DB'];

    // Owner's roster for the week, via the authenticated call (not the
    // read-only APIKEY) -- per-franchise rosters are owner-only.
    $rosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $ownerFranchiseId, 'W' => $week]);
    $lnRoster = mfl_normalize_list($rosterResp['rosters']['franchise']['player'] ?? null);
    // TYPE=rosters&W=<week> is a per-week snapshot; a future week has no
    // snapshot yet and comes back empty. You still set a future lineup off
    // your CURRENT roster, so fall back to the current (no-W) roster and
    // flag it so the panel can say so.
    $lnCurrentFallback = false;
    if (!$lnRoster) {
        $rosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $ownerFranchiseId]);
        $lnRoster = mfl_normalize_list($rosterResp['rosters']['franchise']['player'] ?? null);
        $lnCurrentFallback = (bool) $lnRoster;
    }
    $lnRoster = array_filter($lnRoster, function ($p) {
        $s = strtoupper((string) ($p['status'] ?? ''));
        return strpos($s, 'IR') === false && strpos($s, 'TAXI') === false;
    });

    // Player details (name / team / position).
    $lnDetails = [];
    $lnIds = array_column($lnRoster, 'id');
    if ($lnIds) {
        foreach (array_chunk(array_unique($lnIds), 150) as $chunk) {
            $r = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($r['players']['player'] ?? null) as $p) { $lnDetails[$p['id']] = $p; }
        }
    }

    // This week's NFL opponent per team.
    $lnOpp = [];
    $lnSched = mfl_cached_get('nflSchedule', 3600, ['W' => $week], false);
    foreach (mfl_normalize_list($lnSched['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) !== 2) continue;
        [$t1, $t2] = $teams;
        if (!empty($t1['id']) && !empty($t2['id'])) {
            $lnOpp[$t1['id']] = ['opp' => $t2['id'], 'home' => ($t1['isHome'] ?? '0') === '1'];
            $lnOpp[$t2['id']] = ['opp' => $t1['id'], 'home' => ($t2['isHome'] ?? '0') === '1'];
        }
    }

    // League-scored projections for the week.
    $lnProj = [];
    $lnProjRaw = mfl_cached_get('projectedScores', 3600, ['W' => $week, 'COUNT' => 3000]);
    foreach (mfl_normalize_list($lnProjRaw['projectedScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $lnProj[$row['id']] = $row['score'] ?? null;
    }

    // Pre-check: a just-submitted lineup (this POST) wins; otherwise read
    // the currently-submitted starters back from MFL so the form opens
    // showing your existing lineup instead of everything on the bench.
    $lnChecked = array_filter((array) ($_POST['starters'] ?? []));
    $lnFromMfl = false;
    if (!$lnChecked) {
        $lnChecked = array_keys(rotc_current_starter_ids($ownerFranchiseId, $week));
        $lnFromMfl = (bool) $lnChecked;
    }

    $lnGrouped = array_fill_keys($lnSections, []);
    $lnGrouped['Other'] = [];
    foreach ($lnRoster as $p) {
        $pd   = $lnDetails[$p['id']] ?? [];
        $sec  = $lnBucket[$pd['position'] ?? ''] ?? 'Other';
        $team = $pd['team'] ?? '';
        $opp  = $lnOpp[$team] ?? null;
        $nm   = $pd['name'] ?? ('Player #' . $p['id']);
        if (strpos($nm, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $nm, 2)); $nm = "$f $l"; }
        $lnGrouped[$sec][] = [
            'id'       => $p['id'],
            'name'     => $nm,
            'team'     => $team,
            'opp'      => $opp ? (($opp['home'] ? 'vs ' : '@ ') . $opp['opp']) : '--',
            'proj'     => $lnProj[$p['id']] ?? null,
            'starting' => in_array($p['id'], $lnChecked, true),
        ];
    }
    $lineup = array_filter($lnGrouped, fn($v) => !empty($v));
}

// ---- LIVE: Drop panel (mirrors franchise/drop-player.php) ----
// Current roster (no week) + injury status + YTD points, sorted by
// position then name -- the simplified drop surface.
$droppable = [];
if ($hasConfig) {
    $drRosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $ownerFranchiseId]);
    $drRoster = mfl_normalize_list($drRosterResp['rosters']['franchise']['player'] ?? null);

    $drDetails = [];
    $drIds = array_column($drRoster, 'id');
    if ($drIds) {
        foreach (array_chunk(array_unique($drIds), 150) as $chunk) {
            $r = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($r['players']['player'] ?? null) as $p) { $drDetails[$p['id']] = $p; }
        }
    }

    $drInj = [];
    $drInjRaw = mfl_cached_get('injuries', 1800, [], false);
    foreach (mfl_normalize_list($drInjRaw['injuries']['injury'] ?? null) as $inj) {
        if (!empty($inj['id'])) $drInj[$inj['id']] = $inj['status'] ?? '';
    }

    $drYtd = [];
    $drYtdRaw = mfl_cached_get('playerScores', 1800, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($drYtdRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $drYtd[$row['id']] = $row['score'] ?? '';
    }

    foreach ($drRoster as $p) {
        $pd = $drDetails[$p['id']] ?? [];
        $nm = $pd['name'] ?? ('Player #' . $p['id']);
        if (strpos($nm, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $nm, 2)); $nm = "$f $l"; }
        $droppable[] = [
            'id'   => $p['id'],
            'name' => $nm,
            'pos'  => $pd['position'] ?? '',
            'team' => $pd['team'] ?? '',
            'inj'  => $drInj[$p['id']] ?? '',
            'ytd'  => ($drYtd[$p['id']] ?? '') !== '' ? (float) $drYtd[$p['id']] : null,
        ];
    }
    usort($droppable, fn($a, $b) => strcasecmp($a['pos'], $b['pos']) ?: strcasecmp($a['name'], $b['name']));
}

// ---- LIVE: Trade panel (mirrors franchise/offer-trade.php) ----
/**
 * One tradable-asset row list for a roster: players sorted by position
 * (QB..S order, then anything else), name within, each as
 * ['id','name','pos','pts'], with the franchise's draft picks appended
 * as PICK rows. Top-level so it's callable from the data block below.
 */
function rotc_m_trade_options(array $roster, array $players, array $picks, array $prevPts): array {
    $order = ['QB'=>0,'RB'=>1,'WR'=>2,'TE'=>3,'DE'=>4,'DT'=>5,'LB'=>6,'CB'=>7,'S'=>8];
    $rows = [];
    foreach ($roster as $p) {
        $pd  = $players[$p['id']] ?? [];
        $pos = strtoupper($pd['position'] ?? '');
        $nm  = $pd['name'] ?? ('Player #' . $p['id']);
        if (strpos($nm, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $nm, 2)); $nm = "$f $l"; }
        $rows[] = [
            'id'   => (string) $p['id'],
            'name' => $nm,
            'pos'  => $pos,
            'pts'  => ($prevPts[$p['id']] ?? '') !== '' ? (float) $prevPts[$p['id']] : null,
            'ord'  => $order[$pos] ?? 99,
        ];
    }
    usort($rows, fn($a, $b) => ($a['ord'] <=> $b['ord']) ?: strcasecmp($a['name'], $b['name']));
    foreach ($rows as &$r) { unset($r['ord']); } unset($r);
    foreach ($picks as $pickId => $label) {
        $rows[] = ['id' => (string) $pickId, 'name' => $label, 'pos' => 'PICK', 'pts' => null];
    }
    return $rows;
}

$tradeFranchises = [];   // targets: all franchises minus mine, sorted by name
$tradeTargetId   = '';
$tradeTargetName = '';
$tradeMyList     = [];
$tradeTheirList  = [];
$tradeAllPicks   = [];   // pickId => label (for labeling pending-trade sides)
$tradePlayers    = [];   // id => details (for labeling pending-trade sides)
$pendingIncoming = [];
$pendingOutgoing = [];
$pendingFetchFailed = false;
if ($hasConfig) {
    $tradeFranchises = $franchises;
    unset($tradeFranchises[$ownerFranchiseId]);
    uasort($tradeFranchises, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $tradeTargetId = (string) ($_POST['offeredto'] ?? $_GET['to'] ?? '');
    if ($tradeTargetId !== '' && !isset($tradeFranchises[$tradeTargetId])) $tradeTargetId = '';
    $tradeTargetName = $tradeTargetId !== '' ? ($franchises[$tradeTargetId]['name'] ?? '') : '';

    // Prior-season total points (current season reference before it starts).
    $tradePrevPts = [];
    $tpRaw = mfl_cached_get_year('playerScores', (int) MFL_YEAR - 1, 86400, ['W' => 'YTD', 'COUNT' => 3000]);
    foreach (mfl_normalize_list($tpRaw['playerScores']['playerScore'] ?? null) as $row) {
        if (!empty($row['id'])) $tradePrevPts[$row['id']] = $row['score'] ?? '';
    }

    // Draft-pick assets for every franchise (TYPE=assets).
    $tradePickData = rotc_all_franchise_picks($franchises, $ownerFranchiseId);
    $tradeAllPicks = $tradePickData['all'];
    $myPicks    = $tradePickData['byFranchise'][$ownerFranchiseId] ?? [];
    $theirPicks = $tradeTargetId !== '' ? ($tradePickData['byFranchise'][$tradeTargetId] ?? []) : [];

    // Pending trades involving this owner.
    $pendingPlayerIds = [];
    $pendingResp = rotc_mfl_authed_request('export', 'pendingTrades');
    $pendingFetchFailed = ($pendingResp === null || isset($pendingResp['error']));
    if (!$pendingFetchFailed) {
        foreach (mfl_normalize_list($pendingResp['pendingTrades']['pendingTrade'] ?? null) as $t) {
            $offeringTeam = (string) ($t['offeringteam'] ?? '');
            $offeredTo    = (string) ($t['offeredto'] ?? '');
            $gives = array_values(array_filter(explode(',', (string) ($t['will_give_up'] ?? ''))));
            $gets  = array_values(array_filter(explode(',', (string) ($t['will_receive'] ?? ''))));
            $pendingPlayerIds = array_merge($pendingPlayerIds, $gives, $gets);
            $row = ['trade_id' => $t['trade_id'] ?? null, 'comments' => $t['comments'] ?? '', 'expires' => $t['expires'] ?? null];
            if ($offeredTo === $ownerFranchiseId) {
                $row['other'] = $offeringTeam; $row['receive'] = $gives; $row['give_up'] = $gets;
                $pendingIncoming[] = $row;
            } elseif ($offeringTeam === $ownerFranchiseId) {
                $row['other'] = $offeredTo; $row['give_up'] = $gives; $row['receive'] = $gets;
                $pendingOutgoing[] = $row;
            }
        }
    }

    // Rosters: mine always, target's when chosen.
    $myTradeResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $ownerFranchiseId]);
    $myTradeRoster = mfl_normalize_list($myTradeResp['rosters']['franchise']['player'] ?? null);
    $allIds = array_merge(array_column($myTradeRoster, 'id'), $pendingPlayerIds);
    $theirTradeRoster = [];
    if ($tradeTargetId !== '') {
        $theirTradeResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => $tradeTargetId]);
        $theirTradeRoster = mfl_normalize_list($theirTradeResp['rosters']['franchise']['player'] ?? null);
        $allIds = array_merge($allIds, array_column($theirTradeRoster, 'id'));
    }
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $r = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk), 'DETAILS' => 1], false);
            foreach (mfl_normalize_list($r['players']['player'] ?? null) as $p) { $tradePlayers[$p['id']] = $p; }
        }
    }

    $tradeMyList    = rotc_m_trade_options($myTradeRoster, $tradePlayers, $myPicks, $tradePrevPts);
    $tradeTheirList = $tradeTargetId !== '' ? rotc_m_trade_options($theirTradeRoster, $tradePlayers, $theirPicks, $tradePrevPts) : [];
}

// Names one side of a pending trade (players + picks) into a short string.
function rotc_m_trade_side(array $ids, array $players, array $pickLabels): string {
    if (!$ids) return 'nothing';
    $parts = [];
    foreach ($ids as $id) {
        if (isset($pickLabels[$id])) { $parts[] = $pickLabels[$id]; continue; }
        if (str_starts_with($id, 'DP_') || str_starts_with($id, 'FP_')) { $parts[] = 'Draft pick ' . $id; continue; }
        $pd = $players[$id] ?? [];
        $nm = $pd['name'] ?? ('Player #' . $id);
        if (strpos($nm, ',') !== false) { [$l, $f] = array_map('trim', explode(',', $nm, 2)); $nm = "$f $l"; }
        $meta = trim(($pd['position'] ?? '') . ' ' . ($pd['team'] ?? ''));
        $parts[] = $meta !== '' ? "$nm ($meta)" : $nm;
    }
    return implode(', ', $parts);
}

/**
 * Winner ids to pre-select in a pool pick form: the just-submitted POST
 * wins; otherwise the picks already on file at MFL for this week. Returns
 * [ [winnerId => true], $fromMfl ] -- $fromMfl true means these came from
 * an existing submission (so the panel can say so).
 */
function rotc_m_pool_selection(string $action, string $franchiseId, string $poolType, $week): array {
    if (($_POST['action'] ?? '') === $action) {
        $set = [];
        foreach ($_POST as $k => $v) {
            if (strncmp($k, 'pick_', 5) === 0 && trim((string) $v) !== '') $set[(string) $v] = true;
        }
        return [$set, false];
    }
    $set = rotc_current_pool_pick_ids($franchiseId, $poolType, $week);
    return [$set, (bool) $set];
}

// ---- LIVE: NFL Pick 'Em panel (mirrors franchise/pool-pick.php) ----
$nflStartWeek = 1; $nflEndWeek = 17; $nflWeek = 1; $nflMatchups = [];
$nflPicked = []; $nflPickedFromMfl = false;
if ($hasConfig) {
    $nflStartWeek = (int) ($league['nflPoolStartWeek'] ?? 1);
    $nflEndWeek   = (int) ($league['nflPoolEndWeek'] ?? ($league['endWeek'] ?? 17));
    if ($nflStartWeek < 1) $nflStartWeek = 1;
    if ($nflEndWeek < $nflStartWeek) $nflEndWeek = $nflStartWeek;
    $nflWeek = max($nflStartWeek, min($nflEndWeek, (int) ($_POST['poolweek'] ?? $_GET['nflweek'] ?? $nflStartWeek)));
    $nflSchedResp = mfl_cached_get('nflSchedule', 3600, ['W' => $nflWeek], false);
    foreach (mfl_normalize_list($nflSchedResp['nflSchedule']['matchup'] ?? null) as $m) {
        $teams = mfl_normalize_list($m['team'] ?? null);
        if (count($teams) !== 2) continue;
        $away = null; $home = null;
        foreach ($teams as $t) { if (($t['isHome'] ?? '0') === '1') $home = $t['id']; else $away = $t['id']; }
        if ($away && $home) $nflMatchups[] = ['away' => $away, 'home' => $home];
    }
    [$nflPicked, $nflPickedFromMfl] = rotc_m_pool_selection('nflpick', $ownerFranchiseId, 'NFL', $nflWeek);
}

// ---- LIVE: ROTC Pick 'Em panel (Fantasy pool; per rotc-pickem.php's plan) ----
// The Fantasy pool is the same PICK/RANK poolPicks shape as the NFL pool,
// with franchise ids instead of NFL codes and POOLTYPE=Fantasy. Matchups
// come from TYPE=schedule. The schedule.weeklySchedule.matchup key path is
// NOT confirmed live -- ?tab=rotc&debug=rotcsched dumps it to verify.
$rotcStartWeek = 1; $rotcEndWeek = 17; $rotcWeek = 1; $rotcMatchups = [];
$rotcPicked = []; $rotcPickedFromMfl = false;
if ($hasConfig) {
    $rotcStartWeek = (int) ($league['fantasyPoolStartWeek'] ?? 1);
    $rotcEndWeek   = (int) ($league['fantasyPoolEndWeek'] ?? ($league['endWeek'] ?? 17));
    if ($rotcStartWeek < 1) $rotcStartWeek = 1;
    if ($rotcEndWeek < $rotcStartWeek) $rotcEndWeek = $rotcStartWeek;
    $rotcWeek = max($rotcStartWeek, min($rotcEndWeek, (int) ($_POST['poolweek'] ?? $_GET['rotcweek'] ?? $rotcStartWeek)));

    $rotcSchedResp = mfl_cached_get('schedule', 900, ['W' => $rotcWeek]);
    foreach (mfl_normalize_list($rotcSchedResp['schedule']['weeklySchedule'] ?? null) as $wk) {
        foreach (mfl_normalize_list($wk['matchup'] ?? null) as $m) {
            $fr = mfl_normalize_list($m['franchise'] ?? null);
            if (count($fr) !== 2) continue;
            $away = null; $home = null;
            foreach ($fr as $f) { if (($f['isHome'] ?? '0') === '1') $home = $f['id']; else $away = $f['id']; }
            if ($away === null || $home === null) { $away = $fr[0]['id'] ?? null; $home = $fr[1]['id'] ?? null; }
            if ($away && $home) $rotcMatchups[] = ['away' => $away, 'home' => $home];
        }
    }
    [$rotcPicked, $rotcPickedFromMfl] = rotc_m_pool_selection('rotcpick', $ownerFranchiseId, 'Fantasy', $rotcWeek);

    if (($_GET['debug'] ?? '') === 'rotcsched') {
        header('Content-Type: text/plain');
        echo "fantasyPoolStartWeek=$rotcStartWeek endWeek=$rotcEndWeek week=$rotcWeek\n\nRAW schedule:\n";
        print_r($rotcSchedResp);
        echo "\nPARSED matchups:\n";
        print_r($rotcMatchups);
        exit;
    }
}

// Verification dump for the "show already-submitted choices" reads --
// /mobile?debug=picks. Confirms the weeklyResults (lineup) and pool
// (pick) shapes against real submissions before trusting the pre-select.
if ($hasConfig && ($_GET['debug'] ?? '') === 'picks') {
    header('Content-Type: text/plain');
    echo "franchise=$ownerFranchiseId  lineupWeek=$week  nflWeek=$nflWeek  rotcWeek=$rotcWeek\n\n";
    echo "=== weeklyResults (W=$week) RAW ===\n";  print_r(mfl_cached_get('weeklyResults', 0, ['W' => $week]));
    echo "\n=== parsed current starters ===\n";     print_r(rotc_current_starter_ids($ownerFranchiseId, $week));
    echo "\n=== pool NFL RAW ===\n";                print_r(mfl_cached_get('pool', 0, ['POOLTYPE' => 'NFL']));
    echo "\n=== parsed NFL picks (W=$nflWeek) ===\n"; print_r(rotc_current_pool_pick_ids($ownerFranchiseId, 'NFL', $nflWeek));
    echo "\n=== pool Fantasy RAW ===\n";            print_r(mfl_cached_get('pool', 0, ['POOLTYPE' => 'Fantasy']));
    echo "\n=== parsed Fantasy picks (W=$rotcWeek) ===\n"; print_r(rotc_current_pool_pick_ids($ownerFranchiseId, 'Fantasy', $rotcWeek));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="theme-color" content="#2A1810">
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Roboto+Condensed:400,700|Roboto:400,400i,700" rel="stylesheet">
<?php $cssVer1 = @filemtime(dirname(__DIR__) . '/assets/mfl26.css') ?: time(); ?>
<?php $cssVer2 = @filemtime(dirname(__DIR__) . '/assets/mobile-dashboard.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/mfl26.css?v=<?= $cssVer1 ?>">
<link rel="stylesheet" href="<?= $base ?>/assets/mobile-dashboard.css?v=<?= $cssVer2 ?>">
<?php $cssVerLW = @filemtime(dirname(__DIR__) . '/assets/live-wire.css') ?: time(); ?>
<link rel="stylesheet" href="<?= $base ?>/assets/live-wire.css?v=<?= $cssVerLW ?>">
</head>
<body>
<?php if (!$hasConfig): ?>
  <div class="rotc-mapp">
    <div class="rotc-mapp-panels" style="padding-top:40px;">
      <p>This isn't available right now — check back soon.</p>
    </div>
  </div>
<?php else: ?>
<div class="rotc-mapp">

  <!-- Tab state -- must come before .rotc-mapp-panels and .rotc-mapp-tabbar
       in the DOM for the ~ sibling-selector CSS in mobile-dashboard.css
       to work. Default tab: Lineup. -->
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-lineup"<?= $activeTab === 'lineup' ? ' checked' : '' ?>>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-drop"<?= $activeTab === 'drop' ? ' checked' : '' ?>>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-trade"<?= $activeTab === 'trade' ? ' checked' : '' ?>>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-nfl"<?= $activeTab === 'nfl' ? ' checked' : '' ?>>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-rotc"<?= $activeTab === 'rotc' ? ' checked' : '' ?>>
  <input class="rotc-mtab" type="radio" name="mtab" id="mtab-live"<?= $activeTab === 'live' ? ' checked' : '' ?>>

  <header class="rotc-mapp-topbar">
    <div class="rotc-mapp-brand">
      <img src="<?= $base ?>/assets/img/rotc-icon.png" alt="">
      <div class="rotc-mapp-brand-text">
        <div class="rotc-mapp-brand-name"><?= htmlspecialchars($myFranchiseName) ?></div>
        <div class="rotc-mapp-brand-week">Week <?= (int) $week ?> &middot; 2026 Season</div>
      </div>
    </div>
    <div class="rotc-mapp-topbar-actions">
      <a class="rotc-mapp-fullsite" href="<?= $base ?: '/' ?>">Full Site</a>
      <?php if ($ownerHelmetUrl): ?>
        <a class="rotc-mapp-coach" href="<?= $base ?>/logout.php" title="<?= $ownerUsername ? 'Logged in as ' . htmlspecialchars($ownerUsername) . ' — tap to log out' : 'Tap to log out' ?>">
          <img src="<?= htmlspecialchars($ownerHelmetUrl) ?>" alt="Log out" class="rotc-mapp-coach-helmet">
        </a>
      <?php endif; ?>
    </div>
  </header>

  <main class="rotc-mapp-panels">

    <!-- ================= LINEUP ================= -->
    <section class="rotc-mapp-panel panel-lineup">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Lineup</h1>
        <form method="get" class="rotc-mapp-week-form">
          <input type="hidden" name="tab" value="lineup">
          <select class="rotc-mapp-week-select" name="week" onchange="this.form.submit()" aria-label="Week">
            <?php for ($w = 1; $w <= (int) $endWeek; $w++): ?>
              <option value="<?= $w ?>"<?= $w === (int) $week ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
          <noscript><button type="submit" class="rotc-mbtn rotc-mbtn-small">Go</button></noscript>
        </form>
      </div>
      <?php if ($result && $result['action'] === 'lineup'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok">Lineup submitted for Week <?= (int) $week ?>. Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Tap Start for each player you want in. MyFantasyLeague enforces the position limits when you submit — if it doesn't fit, it'll say exactly why here.</p>
      <?php if ($lnFromMfl): ?>
        <p class="rotc-mapp-blurb" style="color:var(--accent);">✓ Showing your currently-submitted Week <?= (int) $week ?> lineup — change any toggles and re-submit to update it.</p>
      <?php endif; ?>
      <?php if ($lnCurrentFallback): ?>
        <p class="rotc-mapp-blurb" style="color:var(--accent);">Week <?= (int) $week ?> hasn't started yet, so this is your <strong>current</strong> roster. Setting a lineup here submits it for Week <?= (int) $week ?>.</p>
      <?php endif; ?>
      <?php if (!$lineup): ?>
        <div class="rotc-mapp-card"><div class="rotc-mrow"><div class="rotc-mrow-body"><div class="rotc-mrow-meta">No roster found for Week <?= (int) $week ?>.</div></div></div></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="lineup">
        <input type="hidden" name="tab" value="lineup">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <input type="hidden" name="week" value="<?= (int) $week ?>">
        <?php foreach ($lineup as $section => $rows): ?>
          <p class="rotc-mapp-section-title"><?= htmlspecialchars($section) ?></p>
          <div class="rotc-mapp-card">
            <?php foreach ($rows as $i => $p): $fid = 'start_' . $section . '_' . $i; ?>
              <div class="rotc-mrow">
                <div class="rotc-mrow-body">
                  <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                  <div class="rotc-mrow-meta"><?= htmlspecialchars($p['team']) ?> &middot; <?= htmlspecialchars($p['opp']) ?></div>
                </div>
                <div class="rotc-mrow-stat"><?= $p['proj'] !== null ? htmlspecialchars(number_format((float) $p['proj'], 1)) : '--' ?><span class="rotc-mrow-stat-label">Proj</span></div>
                <label class="rotc-mtoggle" for="<?= $fid ?>">
                  <input type="checkbox" id="<?= $fid ?>" name="starters[]" value="<?= htmlspecialchars($p['id']) ?>"<?= $p['starting'] ? ' checked' : '' ?>>
                  <span class="rotc-mtoggle-pill">Start</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Lineup</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- ================= DROP ================= -->
    <section class="rotc-mapp-panel panel-drop">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Drop a Player</h1>
      </div>
      <?php if ($result && $result['action'] === 'drop'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok">Player(s) dropped. Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($waiverType !== 'NONE'): ?>
        <p class="rotc-mapp-blurb">This league is on a waiver system (<?= htmlspecialchars($waiverType) ?>) right now, so drops go through a waiver request. <a href="<?= htmlspecialchars($waiverLink) ?>" style="color:var(--accent);font-weight:700;">Manage on MyFantasyLeague &rarr;</a></p>
      <?php elseif (!$droppable): ?>
        <p class="rotc-mapp-blurb">This league drops immediately, first-come-first-served — no waiver waiting period.</p>
        <div class="rotc-mapp-card"><div class="rotc-mrow"><div class="rotc-mrow-body"><div class="rotc-mrow-meta">No roster found.</div></div></div></div>
      <?php else: ?>
      <p class="rotc-mapp-blurb">This league drops immediately, first-come-first-served — no waiver waiting period.</p>
      <form method="post">
        <input type="hidden" name="action" value="drop">
        <input type="hidden" name="tab" value="drop">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <div class="rotc-mapp-card">
          <?php foreach ($droppable as $i => $p): $fid = 'drop_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?><?= $p['inj'] ? ' <span style="color:var(--accent);font-size:11px;">' . htmlspecialchars($p['inj']) . '</span>' : '' ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?> &middot; <?= htmlspecialchars($p['team']) ?> &middot; <?= $p['ytd'] !== null ? htmlspecialchars(number_format((float) $p['ytd'], 1)) . ' YTD pts' : 'no YTD pts' ?></div>
              </div>
              <label class="rotc-mtoggle danger" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="drop[]" value="<?= htmlspecialchars($p['id']) ?>">
                <span class="rotc-mtoggle-pill">Drop</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mbtn-secondary rotc-mapp-sticky-submit" onclick="return confirm('Drop the selected player(s)? This happens immediately.');">Drop Selected</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- ================= TRADE ================= -->
    <section class="rotc-mapp-panel panel-trade">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Offer a Trade</h1>
      </div>
      <?php if ($result && $result['action'] === 'trade'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok"><?= isset($result['respond']) ? 'Trade ' . htmlspecialchars($result['respond']) . '.' : 'Trade offer sent.' ?> Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($pendingFetchFailed): ?>
        <p class="rotc-mapp-blurb">Couldn't check MyFantasyLeague for pending trades right now.</p>
      <?php else: ?>
        <?php if ($pendingIncoming): ?>
          <p class="rotc-mapp-section-title">Offered to you</p>
          <?php foreach ($pendingIncoming as $t): $other = $franchises[$t['other']]['name'] ?? ('Franchise #' . $t['other']); $oh = rotc_helmet_src($t['other']); ?>
            <div class="rotc-mapp-pending-card">
              <div class="rotc-mapp-pending-head">
                <?php if ($oh): ?><img src="<?= htmlspecialchars($oh) ?>" alt=""><?php endif; ?>
                <span><?= htmlspecialchars($other) ?></span>
              </div>
              <p class="rotc-mapp-pending-line"><b>They give you:</b> <?= htmlspecialchars(rotc_m_trade_side($t['receive'], $tradePlayers, $tradeAllPicks)) ?></p>
              <p class="rotc-mapp-pending-line"><b>You give up:</b> <?= htmlspecialchars(rotc_m_trade_side($t['give_up'], $tradePlayers, $tradeAllPicks)) ?></p>
              <?php if (!empty($t['comments'])): ?><p class="rotc-mapp-pending-line" style="color:var(--muted);">"<?= htmlspecialchars($t['comments']) ?>"</p><?php endif; ?>
              <?php if (!empty($t['trade_id'])): ?>
                <div class="rotc-mapp-pending-actions">
                  <form method="post" style="flex:1 1 0;">
                    <input type="hidden" name="action" value="traderespond"><input type="hidden" name="tab" value="trade">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                    <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                    <input type="hidden" name="respond_action" value="accept">
                    <button type="submit" class="rotc-mbtn rotc-mbtn-small" style="width:100%;">Accept</button>
                  </form>
                  <form method="post" style="flex:1 1 0;">
                    <input type="hidden" name="action" value="traderespond"><input type="hidden" name="tab" value="trade">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                    <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                    <input type="hidden" name="respond_action" value="reject">
                    <button type="submit" class="rotc-mbtn rotc-mbtn-secondary rotc-mbtn-small" style="width:100%;">Reject</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($pendingOutgoing): ?>
          <p class="rotc-mapp-section-title">Sent by you — awaiting response</p>
          <?php foreach ($pendingOutgoing as $t): $other = $franchises[$t['other']]['name'] ?? ('Franchise #' . $t['other']); $oh = rotc_helmet_src($t['other']); ?>
            <div class="rotc-mapp-pending-card">
              <div class="rotc-mapp-pending-head">
                <?php if ($oh): ?><img src="<?= htmlspecialchars($oh) ?>" alt=""><?php endif; ?>
                <span>To <?= htmlspecialchars($other) ?></span>
              </div>
              <p class="rotc-mapp-pending-line"><b>You give up:</b> <?= htmlspecialchars(rotc_m_trade_side($t['give_up'], $tradePlayers, $tradeAllPicks)) ?></p>
              <p class="rotc-mapp-pending-line"><b>You receive:</b> <?= htmlspecialchars(rotc_m_trade_side($t['receive'], $tradePlayers, $tradeAllPicks)) ?></p>
              <?php if (!empty($t['trade_id'])): ?>
                <div class="rotc-mapp-pending-actions">
                  <form method="post" style="flex:1 1 0;" onsubmit="return confirm('Revoke this trade offer?');">
                    <input type="hidden" name="action" value="traderespond"><input type="hidden" name="tab" value="trade">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
                    <input type="hidden" name="respond_trade_id" value="<?= htmlspecialchars($t['trade_id']) ?>">
                    <input type="hidden" name="respond_action" value="revoke">
                    <button type="submit" class="rotc-mbtn rotc-mbtn-secondary rotc-mbtn-small" style="width:100%;">Revoke</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!$pendingIncoming && !$pendingOutgoing): ?>
          <p class="rotc-mapp-blurb">No pending trade offers right now.</p>
        <?php endif; ?>
      <?php endif; ?>

      <p class="rotc-mapp-section-title">New offer</p>
      <form method="get" class="rotc-mapp-week-form" style="margin-bottom:12px;">
        <input type="hidden" name="tab" value="trade">
        <select class="rotc-mapp-trade-target" name="to" onchange="this.form.submit()" style="flex:1 1 auto;">
          <option value="">— choose a franchise —</option>
          <?php foreach ($tradeFranchises as $fid => $f): ?>
            <option value="<?= htmlspecialchars($fid) ?>"<?= $fid === $tradeTargetId ? ' selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if ($tradeTargetId === ''): ?>
        <p class="rotc-mapp-blurb">Pick a franchise above to build an offer.</p>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="trade">
        <input type="hidden" name="tab" value="trade">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <input type="hidden" name="offeredto" value="<?= htmlspecialchars($tradeTargetId) ?>">

        <p class="rotc-mapp-section-title">You give up</p>
        <div class="rotc-mapp-card">
          <?php foreach ($tradeMyList as $i => $p): $fid = 'give_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?><?= $p['pts'] !== null ? ' &middot; ' . htmlspecialchars(number_format((float) $p['pts'], 1)) . ' pts' : '' ?></div>
              </div>
              <label class="rotc-mtoggle" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="give_up[]" value="<?= htmlspecialchars($p['id']) ?>">
                <span class="rotc-mtoggle-pill">Add</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>

        <p class="rotc-mapp-section-title">You receive from <?= htmlspecialchars($tradeTargetName) ?></p>
        <div class="rotc-mapp-card">
          <?php foreach ($tradeTheirList as $i => $p): $fid = 'recv_' . $i; ?>
            <div class="rotc-mrow">
              <div class="rotc-mrow-body">
                <div class="rotc-mrow-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="rotc-mrow-meta"><?= htmlspecialchars($p['pos']) ?><?= $p['pts'] !== null ? ' &middot; ' . htmlspecialchars(number_format((float) $p['pts'], 1)) . ' pts' : '' ?></div>
              </div>
              <label class="rotc-mtoggle" for="<?= $fid ?>">
                <input type="checkbox" id="<?= $fid ?>" name="receive[]" value="<?= htmlspecialchars($p['id']) ?>">
                <span class="rotc-mtoggle-pill">Add</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <textarea name="comments" rows="2" placeholder="Message (optional)" class="rotc-mapp-trade-comments"></textarea>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Send Trade Offer</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- ================= NFL PICK 'EM ================= -->
    <section class="rotc-mapp-panel panel-nfl">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">NFL Pick 'Em</h1>
        <form method="get" class="rotc-mapp-week-form">
          <input type="hidden" name="tab" value="nfl">
          <select class="rotc-mapp-week-select" name="nflweek" onchange="this.form.submit()" aria-label="Week">
            <?php for ($w = (int) $nflStartWeek; $w <= (int) $nflEndWeek; $w++): ?>
              <option value="<?= $w ?>"<?= $w === (int) $nflWeek ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>
      </div>
      <?php if ($result && $result['action'] === 'nflpick'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok">Picks submitted for Week <?= (int) $nflWeek ?>. Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Tap the team you think wins each game.<?= $nflPickedFromMfl ? ' <span style="color:var(--accent);">✓ Your submitted Week ' . (int) $nflWeek . ' picks are shown — change any and re-submit.</span>' : '' ?></p>
      <?php if (!$nflMatchups): ?>
        <div class="rotc-mapp-card"><div class="rotc-mrow"><div class="rotc-mrow-body"><div class="rotc-mrow-meta">No NFL schedule found for Week <?= (int) $nflWeek ?> yet.</div></div></div></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="nflpick">
        <input type="hidden" name="tab" value="nfl">
        <input type="hidden" name="poolweek" value="<?= (int) $nflWeek ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <div class="rotc-mapp-card">
          <?php foreach ($nflMatchups as $m): $fname = 'pick_' . $m['away'] . '_' . $m['home']; ?>
            <div class="rotc-mpick">
              <div class="rotc-mpick-vs"><?= htmlspecialchars($m['away']) ?> @ <?= htmlspecialchars($m['home']) ?></div>
              <div class="rotc-mpick-choices">
                <label class="rotc-mpick-btn"><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['away']) ?>"<?= isset($nflPicked[$m['away']]) ? ' checked' : '' ?>><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['away']) ?></span></label>
                <label class="rotc-mpick-btn"><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['home']) ?>"<?= isset($nflPicked[$m['home']]) ? ' checked' : '' ?>><span class="rotc-mpick-btn-face"><?= htmlspecialchars($m['home']) ?></span></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Picks</button>
      </form>
      <?php endif; ?>
    </section>

    <!-- ================= ROTC PICK 'EM ================= -->
    <section class="rotc-mapp-panel panel-rotc">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">ROTC Pick 'Em</h1>
        <form method="get" class="rotc-mapp-week-form">
          <input type="hidden" name="tab" value="rotc">
          <select class="rotc-mapp-week-select" name="rotcweek" onchange="this.form.submit()" aria-label="Week">
            <?php for ($w = (int) $rotcStartWeek; $w <= (int) $rotcEndWeek; $w++): ?>
              <option value="<?= $w ?>"<?= $w === (int) $rotcWeek ? ' selected' : '' ?>>Week <?= $w ?></option>
            <?php endfor; ?>
          </select>
        </form>
      </div>
      <?php if ($result && $result['action'] === 'rotcpick'): ?>
        <?php if ($result['ok']): ?>
          <div class="rotc-mapp-banner ok">Picks submitted for Week <?= (int) $rotcWeek ?>. Good luck, punk.</div>
        <?php else: ?>
          <div class="rotc-mapp-banner err"><?= nl2br(htmlspecialchars($result['error'])) ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <p class="rotc-mapp-blurb">Franchise vs. franchise — pick who wins each fantasy matchup this week.<?= $rotcPickedFromMfl ? ' <span style="color:var(--accent);">✓ Your submitted Week ' . (int) $rotcWeek . ' picks are shown — change any and re-submit.</span>' : '' ?></p>
      <?php if (!$rotcMatchups): ?>
        <div class="rotc-mapp-card"><div class="rotc-mrow"><div class="rotc-mrow-body"><div class="rotc-mrow-meta">No fantasy schedule found for Week <?= (int) $rotcWeek ?> yet.</div></div></div></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="rotcpick">
        <input type="hidden" name="tab" value="rotc">
        <input type="hidden" name="poolweek" value="<?= (int) $rotcWeek ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <div class="rotc-mapp-card">
          <?php foreach ($rotcMatchups as $m):
            $fname    = 'pick_' . $m['away'] . '_' . $m['home'];
            $awayName = $franchises[$m['away']]['name'] ?? $m['away'];
            $homeName = $franchises[$m['home']]['name'] ?? $m['home'];
            $awayAbbr = $franchises[$m['away']]['abbrev'] ?? $m['away'];
            $homeAbbr = $franchises[$m['home']]['abbrev'] ?? $m['home'];
            $isMine   = $m['away'] === $ownerFranchiseId || $m['home'] === $ownerFranchiseId;
          ?>
            <div class="rotc-mpick<?= $isMine ? ' mine' : '' ?>">
              <div class="rotc-mpick-vs"><?= htmlspecialchars($awayName) ?> @ <?= htmlspecialchars($homeName) ?></div>
              <div class="rotc-mpick-choices">
                <label class="rotc-mpick-btn"><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['away']) ?>"<?= isset($rotcPicked[$m['away']]) ? ' checked' : '' ?>><span class="rotc-mpick-btn-face"><?= htmlspecialchars($awayAbbr) ?></span></label>
                <label class="rotc-mpick-btn"><input type="radio" name="<?= htmlspecialchars($fname) ?>" value="<?= htmlspecialchars($m['home']) ?>"<?= isset($rotcPicked[$m['home']]) ? ' checked' : '' ?>><span class="rotc-mpick-btn-face"><?= htmlspecialchars($homeAbbr) ?></span></label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="rotc-mbtn rotc-mapp-sticky-submit">Submit Picks</button>
      </form>
      <?php endif; ?>
    </section>


    <!-- ================= LIVE WIRE ================= -->
    <section class="rotc-mapp-panel panel-live lw-page lw-embed">
      <div class="rotc-mapp-panel-head">
        <h1 class="rotc-mapp-panel-title">Live Wire</h1>
      </div>
      <?php if (!$liveState): ?>
        <div class="lw-empty">
          <h2>Nothing on the field yet</h2>
          <p>MFL doesn't publish live scoring until games kick off.
             Your matchup shows up here as its own field once they do.</p>
          <p><a class="lw-demo-btn" href="<?= $base ?>/scores/live-scoring?demo=1">See how it works &rarr;</a></p>
        </div>
      <?php else: ?>
        <?php rotc_lw_render_wire($liveState); ?>
        <?php rotc_lw_render_cards($liveState, $ownerFranchiseId ?: null, $base); ?>
        <?php rotc_lw_render_script($base); ?>
      <?php endif; ?>
    </section>

  </main>

  <nav class="rotc-mapp-tabbar">
    <label class="tab-lineup" for="mtab-lineup">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h6M9 8h2"/></svg>
      Lineup
    </label>
    <label class="tab-drop" for="mtab-drop">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><path d="M17 11h6"/></svg>
      Drop
    </label>
    <label class="tab-trade" for="mtab-trade" style="position:relative;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
      Trade
      <?php if ($pendingIncoming): ?><span class="rotc-mapp-tab-badge"><?= count($pendingIncoming) ?></span><?php endif; ?>
    </label>
    <label class="tab-nfl" for="mtab-nfl">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22V3"/></svg>
      NFL
    </label>
    <label class="tab-rotc" for="mtab-rotc">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9 12 2"/></svg>
      ROTC
    </label>
    <label class="tab-live" for="mtab-live">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 6v12"/><path d="M6 10v4M18 10v4"/></svg>
      Live
    </label>
  </nav>

</div>
<?php endif; ?>
</body>
</html>
