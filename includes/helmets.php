<?php
/**
 * includes/helmets.php
 * Franchise ID -> custom helmet-art image, ported from the HELMETS /
 * SINGLE_ART mapping in templates/matchup-ticker.php. That one's JS
 * (the ticker builds its DOM client-side); this is the PHP-side copy
 * for server-rendered pages like standings.php that need the same
 * custom helmet art instead of MFL's own franchise 'icon' field
 * (which is just whatever small image each owner uploaded — not
 * necessarily a helmet at all).
 *
 * Keep both mappings in sync if a franchise's art or ID changes.
 */

const ROTC_HELMET_BASE = 'https://www.returnofthechampions.com/img/img/helmetsfinished/';

const ROTC_HELMET_PREFIX = [
    '0001' => 'AOH', '0002' => 'CB',  '0003' => 'DB',  '0004' => 'KK',
    '0005' => 'FEC', '0006' => 'SW',  '0007' => 'FCC', '0008' => 'SPD',
    '0009' => 'HIT', '0010' => 'RRD', '0011' => 'EH',  '0012' => 'JP',
    '0013' => 'GAM', '0014' => 'DP',  '0015' => 'GZ',  '0016' => 'JEP',
];

// A few teams only have one facing direction of art.
// AOH added 2026-07-18: the previous AOH_L_01.jpg/AOH_R_01.jpg files were
// the WRONG helmet entirely (an "AH" wordmark helmet, not this team's
// real art). Every AOH_* variant that already existed on the server had
// a baked-in "Angels of Harlem" wordmark band along the bottom -- the
// clean, text-free source (AOH_UPDATE.png) was supplied directly by
// Matteo, not found on the server; that file was trimmed of its white
// background/padding (chroma-keyed to transparency) and uploaded over
// AOH_HELMET.png so every page picks up the fix from one place rather
// than needing a per-page correction. Facing is 'right' -- the art's
// facemask/grille sits on the right of the frame.
const ROTC_HELMET_SINGLE_ART = [
    'CB'  => ['file' => 'CB_HELMET.png', 'facing' => 'right'],
    'JP'  => ['file' => 'JP_HELMET.png', 'facing' => 'right'],
    'EH'  => ['file' => 'EH_HELMET.png', 'facing' => 'left'],
    // Filename bumped to _v2 on 2026-07-18: same fixed art as before, but
    // AOH_HELMET.png was served with a 7-day Cache-Control (max-age=604800)
    // -- overwriting that filename in place left every browser (and any
    // intermediate CDN) showing the stale pre-fix image for up to a week.
    // A new filename forces a real fetch. Bump the suffix again if the art
    // ever needs to change.
    'AOH' => ['file' => 'AOH_HELMET_v2.png', 'facing' => 'right'],
    // Native Americans: defunct (2003-and-earlier) team, only known by name
    // (see ROTC_HELMET_PREFIX_BY_NAME) -- single-direction art supplied
    // directly, facemask on the right.
    'NAT' => ['file' => 'NAT_HELMET.png', 'facing' => 'right'],
];

/**
 * Defunct/renamed franchises that no longer have a CURRENT-season MFL
 * franchise_id (so they can't go in ROTC_HELMET_PREFIX, which is keyed
 * by that id) but still need helmet art for Hall of Fame's pre-2017
 * champions (see includes/hall-of-fame.php's ROTC_HOF_MANUAL_CHAMPIONS
 * -- those years are sourced from MFL's own League Champions page, not
 * the live bracket API, so there's no numeric franchise_id to key off
 * at all). Confirmed live these prefixes have real dual-direction art
 * already uploaded (both _L_01.jpg and _R_01.jpg exist).
 */
const ROTC_HELMET_PREFIX_BY_NAME = [
    'Motown Lions' => 'ML',
    'Alamo Assault' => 'AA',
    'Phishermen' => 'PHI',
    'Native Americans' => 'NAT',
];

/**
 * $side is 'left' or 'right' — which way the helmet should face.
 * A single icon in a table just needs one; 'right' is a fine default.
 */
function rotc_helmet_src(string $franchiseId, string $side = 'right'): ?string {
    $prefix = ROTC_HELMET_PREFIX[$franchiseId] ?? null;
    if (!$prefix) return null;
    return rotc_helmet_src_by_prefix($prefix, $side);
}

function rotc_helmet_flip(string $franchiseId, string $side = 'right'): bool {
    $prefix = ROTC_HELMET_PREFIX[$franchiseId] ?? null;
    if (!$prefix) return false;
    return rotc_helmet_flip_by_prefix($prefix, $side);
}

/** Same as rotc_helmet_src(), but for a defunct/renamed team known only by NAME (see ROTC_HELMET_PREFIX_BY_NAME). */
function rotc_helmet_src_by_name(string $teamName, string $side = 'right'): ?string {
    $prefix = ROTC_HELMET_PREFIX_BY_NAME[$teamName] ?? null;
    if (!$prefix) return null;
    return rotc_helmet_src_by_prefix($prefix, $side);
}

function rotc_helmet_flip_by_name(string $teamName, string $side = 'right'): bool {
    $prefix = ROTC_HELMET_PREFIX_BY_NAME[$teamName] ?? null;
    if (!$prefix) return false;
    return rotc_helmet_flip_by_prefix($prefix, $side);
}

function rotc_helmet_src_by_prefix(string $prefix, string $side): string {
    if (isset(ROTC_HELMET_SINGLE_ART[$prefix])) {
        return ROTC_HELMET_BASE . ROTC_HELMET_SINGLE_ART[$prefix]['file'];
    }
    return ROTC_HELMET_BASE . $prefix . '_' . ($side === 'left' ? 'R' : 'L') . '_01.jpg';
}

function rotc_helmet_flip_by_prefix(string $prefix, string $side): bool {
    if (!isset(ROTC_HELMET_SINGLE_ART[$prefix])) return false;
    return ROTC_HELMET_SINGLE_ART[$prefix]['facing'] === $side;
}
