<?php
/**
 * includes/mfl-auth.php
 * Owner login + authenticated (write-capable) MFL API access, layered
 * on top of includes/mfl-api.php's read-only mfl_fetch()/mfl_cached_get().
 *
 * Why this exists: mfl_fetch() always authenticates with the site's own
 * APIKEY (config.php), which per MFL's docs is "only valid for owners"
 * and explicitly does NOT work for import (write) requests or anything
 * requiring commissioner access. To let a real owner submit a lineup,
 * drop a player, offer a trade, etc. from this site, the owner has to
 * log in with THEIR OWN MFL username/password, and every subsequent
 * write call has to carry THEIR session cookie, not the site's APIKEY.
 *
 * Login mechanism (confirmed live against MFL's own docs page,
 * api_info?L=67102, and a deliberate bad-credential test against the
 * real endpoint):
 *   POST https://api.myfantasyleague.com/{year}/login
 *        USERNAME=..&PASSWORD=..&XML=1
 * Confirmed live: XML=1 is required -- passing JSON=1 instead returned
 * an empty 200 response body (login does NOT support JSON like every
 * other export/import call does, even though the rest of the API does).
 * A bad login returns real, live-confirmed body: <error>Invalid
 * Password</error>. A good login is documented (not yet seen live --
 * needs a real owner to actually log in once this is deployed) as
 * <status SOME_COOKIE_NAME="cookie_value" .../> where the attribute
 * name itself IS the cookie name to replay on every later call, as:
 *     Cookie: <name>=<value>
 * Since the docs describe this generically ("cookie_name") rather than
 * giving a fixed literal, rotc_mfl_login() below reads whichever
 * attribute is actually present instead of assuming a fixed name like
 * "MFL_USER_ID" -- more defensive than hardcoding something never
 * confirmed against a real successful login.
 *
 * Credentials are POSTed (never placed in a URL/GET, so they never hit
 * server access logs) straight through to MFL over HTTPS and are never
 * stored anywhere -- only the resulting session cookie is kept, and
 * only in the PHP server-side session (never sent to the browser as
 * anything but the standard HttpOnly PHPSESSID). Logging out clears it.
 *
 * Requires config.php already loaded (MFL_YEAR, MFL_LEAGUE_ID,
 * MFL_USER_AGENT) same as mfl-api.php, and mfl-api.php itself loaded
 * first for mfl_normalize_list().
 */

function rotc_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function rotc_mfl_logged_in(): bool {
    rotc_session_start();
    return !empty($_SESSION['rotc_mfl_cookie_name']) && !empty($_SESSION['rotc_mfl_cookie_value']);
}

function rotc_mfl_username(): ?string {
    rotc_session_start();
    return $_SESSION['rotc_mfl_username'] ?? null;
}

/**
 * The owner's franchise id within THIS league (MFL_LEAGUE_ID), resolved
 * once at login via rotc_mfl_resolve_franchise_id() and cached in the
 * session. Every franchise/*.php action page needs this to know whose
 * roster/lineup/trade to act on.
 */
function rotc_mfl_franchise_id(): ?string {
    rotc_session_start();
    return $_SESSION['rotc_mfl_franchise_id'] ?? null;
}

function rotc_mfl_logout(): void {
    rotc_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** CSRF token for every form that POSTs to a write action. */
function rotc_csrf_token(): string {
    rotc_session_start();
    if (empty($_SESSION['rotc_csrf'])) $_SESSION['rotc_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['rotc_csrf'];
}

function rotc_csrf_check(?string $token): bool {
    rotc_session_start();
    return !empty($_SESSION['rotc_csrf']) && is_string($token) && hash_equals($_SESSION['rotc_csrf'], $token);
}

/**
 * Logs the owner in. Returns ['ok'=>true] or ['ok'=>false,'error'=>...].
 * On success, stores the session cookie + resolves the franchise id.
 */
function rotc_mfl_login(string $username, string $password): array {
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your MyFantasyLeague username and password.'];
    }

    $year = defined('MFL_YEAR') ? MFL_YEAR : (int) date('Y');
    $ch = curl_init("https://api.myfantasyleague.com/{$year}/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['USERNAME' => $username, 'PASSWORD' => $password, 'XML' => 1]),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    if (!$ok || !$body) {
        return ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.'];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if ($xml === false) {
        return ['ok' => false, 'error' => 'Unexpected response from MyFantasyLeague.'];
    }

    if ($xml->getName() === 'error') {
        return ['ok' => false, 'error' => (string) $xml];
    }

    if ($xml->getName() === 'status') {
        $cookieName = null;
        $cookieValue = null;
        foreach ($xml->attributes() as $name => $value) {
            // MFL's own docs only promise ONE attribute (the cookie
            // name/value pair) but a URL/host redirect attribute isn't
            // out of the question, so skip anything that looks like
            // one rather than assuming the first attribute is always
            // the cookie.
            if (in_array(strtoupper((string) $name), ['URL', 'HOST'], true)) continue;
            $cookieName = (string) $name;
            $cookieValue = (string) $value;
            break;
        }
        if ($cookieName === null) {
            return ['ok' => false, 'error' => 'Login succeeded but MyFantasyLeague did not return a session cookie.'];
        }

        rotc_session_start();
        session_regenerate_id(true);
        $_SESSION['rotc_mfl_cookie_name']  = $cookieName;
        $_SESSION['rotc_mfl_cookie_value'] = $cookieValue;
        $_SESSION['rotc_mfl_username']     = $username;
        $_SESSION['rotc_mfl_login_time']   = time();
        unset($_SESSION['rotc_mfl_franchise_id']);

        rotc_mfl_resolve_franchise_id();

        return ['ok' => true];
    }

    return ['ok' => false, 'error' => 'Unrecognized response from MyFantasyLeague.'];
}

/**
 * Authenticated export/import call carrying the owner's session cookie
 * (set by rotc_mfl_login()) instead of the read-only site APIKEY that
 * mfl_fetch() uses. Always POSTs -- MFL's own recommendation, and
 * required in practice once STARTERS/PICKS lists get long enough to
 * blow past GET length limits.
 *
 * Unlike mfl_fetch(), this returns the raw decoded response even when
 * it contains an 'error' key -- import error messages (e.g. "Lineup
 * does not meet the minimum requirement for position RB") are exactly
 * what the owner needs to see, not something to swallow into null.
 * Returns null only on a genuine transport failure (can't reach MFL) or
 * if the caller isn't logged in.
 */
function rotc_mfl_authed_request(string $command, string $type, array $params = [], bool $includeLeague = true, ?int $year = null): ?array {
    rotc_session_start();
    if (!rotc_mfl_logged_in()) return null;

    $year = $year ?? (defined('MFL_YEAR') ? MFL_YEAR : (int) date('Y'));
    $fields = array_merge(['TYPE' => $type, 'JSON' => 1], $params);
    if ($includeLeague && defined('MFL_LEAGUE_ID')) $fields['L'] = MFL_LEAGUE_ID;

    $ch = curl_init("https://api.myfantasyleague.com/{$year}/{$command}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => defined('MFL_USER_AGENT') ? MFL_USER_AGENT : 'ROTC26-Site',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_COOKIE         => $_SESSION['rotc_mfl_cookie_name'] . '=' . rawurlencode($_SESSION['rotc_mfl_cookie_value']),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok || !$body) return null;

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Maps the logged-in owner to their franchise id within MFL_LEAGUE_ID,
 * via TYPE=myleagues (the one call documented to return "all of the
 * leagues of the current user", each with that user's franchise id).
 * NOTE: the exact field names below (league_id / franchise_id) are
 * taken from MFL's documented shape but have NOT been confirmed
 * against a real live response -- that needs an actual owner login,
 * which wasn't possible to test ahead of time without real league
 * credentials. If this comes back empty after a real login, the field
 * names here are the first thing to check against what MFL actually
 * sends back.
 */
function rotc_mfl_resolve_franchise_id(): ?string {
    rotc_session_start();
    if (!empty($_SESSION['rotc_mfl_franchise_id'])) return $_SESSION['rotc_mfl_franchise_id'];
    if (!rotc_mfl_logged_in()) return null;

    $year = defined('MFL_YEAR') ? MFL_YEAR : (int) date('Y');
    $resp = rotc_mfl_authed_request('export', 'myleagues', ['FRANCHISE_NAMES' => 1], false, $year);
    if (!$resp) return null;

    $leagues = mfl_normalize_list($resp['myleagues']['leagues']['league'] ?? $resp['leagues']['league'] ?? null);
    $leagueId = defined('MFL_LEAGUE_ID') ? (string) MFL_LEAGUE_ID : null;

    foreach ($leagues as $lg) {
        $lgId = (string) ($lg['league_id'] ?? $lg['id'] ?? '');
        if ($lgId !== '' && $lgId === $leagueId) {
            $fid = $lg['franchise_id'] ?? $lg['franchise'] ?? null;
            if ($fid) {
                $_SESSION['rotc_mfl_franchise_id'] = (string) $fid;
                return (string) $fid;
            }
        }
    }
    return null;
}

/**
 * Call at the top of any franchise/*.php action page, before any HTML
 * output. Redirects to login.php (with a redirect param pointing back
 * to the current page) if the owner isn't logged in, or if login
 * succeeded but the franchise id couldn't be resolved (see the NOTE on
 * rotc_mfl_resolve_franchise_id() -- that's the one part of this whole
 * flow that couldn't be verified live ahead of time).
 */
function rotc_require_login(string $base): void {
    rotc_session_start();
    if (!rotc_mfl_logged_in()) {
        $redirect = $_SERVER['REQUEST_URI'] ?? ($base . '/franchise/');
        header('Location: ' . $base . '/login.php?redirect=' . urlencode($redirect));
        exit;
    }
    if (rotc_mfl_resolve_franchise_id() === null) {
        header('Location: ' . $base . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '') . '&err=franchise');
        exit;
    }
}
