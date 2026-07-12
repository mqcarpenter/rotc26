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
const ROTC_HELMET_SINGLE_ART = [
    'CB' => ['file' => 'CB_HELMET.png', 'facing' => 'right'],
    'JP' => ['file' => 'JP_HELMET.png', 'facing' => 'right'],
    'EH' => ['file' => 'EH_HELMET.png', 'facing' => 'left'],
];

/**
 * $side is 'left' or 'right' — which way the helmet should face.
 * A single icon in a table just needs one; 'right' is a fine default.
 */
function rotc_helmet_src(string $franchiseId, string $side = 'right'): ?string {
    $prefix = ROTC_HELMET_PREFIX[$franchiseId] ?? null;
    if (!$prefix) return null;
    if (isset(ROTC_HELMET_SINGLE_ART[$prefix])) {
        return ROTC_HELMET_BASE . ROTC_HELMET_SINGLE_ART[$prefix]['file'];
    }
    return ROTC_HELMET_BASE . $prefix . '_' . ($side === 'left' ? 'R' : 'L') . '_01.jpg';
}

function rotc_helmet_flip(string $franchiseId, string $side = 'right'): bool {
    $prefix = ROTC_HELMET_PREFIX[$franchiseId] ?? null;
    if (!$prefix || !isset(ROTC_HELMET_SINGLE_ART[$prefix])) return false;
    return ROTC_HELMET_SINGLE_ART[$prefix]['facing'] === $side;
}
