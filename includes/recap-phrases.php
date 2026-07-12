<?php
/**
 * includes/recap-phrases.php
 * Curated flavor-line bank for weekly recap articles (see
 * rotc_recap_flavor_line() in includes/weekly-recap.php). Each
 * matchup's recap closes with one line picked from whichever category
 * fits the result -- a blowout gets a 'blowout' line, a one-score game
 * gets a 'nailbiter' line, a costly bench decision gets a 'benchMiss'
 * line, everything else gets 'general'. The pick is deterministic
 * (seeded by franchise+week+category), so a given matchup always shows
 * the same line rather than a different one on every reload.
 *
 * Deliberately NOT presented as a real quote attributed to a real
 * person -- MFL's own recaps do that ("'We brought our A-game,' coach
 * Tim said"), Matteo asked to skip that and use unattributed flavor
 * text instead.
 *
 * To add more lines: paste them into the matching category array
 * below (plain strings, no HTML needed -- they're escaped when
 * rendered). Any category can be left empty; rotc_recap_flavor_line()
 * falls back to 'general' if the requested category has nothing in it,
 * and returns '' if 'general' is also empty.
 */
const ROTC_RECAP_PHRASES = [
    'general' => [
        "The locker room was buzzing after this one.",
        "A statement performance from start to finish.",
        "Every point counted down to the final whistle.",
        "This one will be talked about all week.",
        "The kind of week that swings a whole season.",
    ],

    // Matteo: add lines for lopsided results here.
    'blowout' => [
    ],

    // Matteo: add lines for one-score / down-to-the-wire results here.
    'nailbiter' => [
    ],

    // Matteo: add lines for a costly bench decision here.
    'benchMiss' => [
    ],
];
