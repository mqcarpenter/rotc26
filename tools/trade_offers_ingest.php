#!/usr/bin/env php
<?php
/**
 * tools/trade_offers_ingest.php
 *
 * Loads data/trade_offers.json (produced by tools/trade_offers_backfill.py)
 * into rotchist_trade_offers. Run on the host, where the DB lives --
 * ROTCHIST_READ_DB_HOST is 'localhost' there, so this cannot run from a
 * developer machine.
 *
 *   php tools/trade_offers_ingest.php            # insert/update
 *   php tools/trade_offers_ingest.php --dry-run  # parse and report only
 *
 * Uses WRITE credentials, deliberately separate from the SELECT-only user
 * the site itself uses (see includes/rotchist-db.php). Add to config.php:
 *
 *   define('ROTCHIST_WRITE_DB_HOST', 'localhost');
 *   define('ROTCHIST_WRITE_DB_NAME', '...');
 *   define('ROTCHIST_WRITE_DB_USER', '...');   // needs INSERT/UPDATE
 *   define('ROTCHIST_WRITE_DB_PASS', '...');
 *
 * Idempotent: rows key on a content hash, so re-running after a re-scrape
 * updates in place rather than duplicating.
 */

$root = dirname(__DIR__);
$configPath = getenv('ROTC_CONFIG_PATH') ?: ($root . '/config.php');
if (file_exists($configPath)) require_once $configPath;

$dryRun = in_array('--dry-run', $argv, true);

$jsonPath = $root . '/data/trade_offers.json';
if (!file_exists($jsonPath)) {
    fwrite(STDERR, "missing $jsonPath -- run tools/trade_offers_backfill.py first\n");
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);
if (!is_array($data) || empty($data['rows'])) {
    fwrite(STDERR, "no rows in $jsonPath\n");
    exit(1);
}

// MFL's report labels -> our compact status values.
const STATUS_MAP = [
    'Trade Proposal'      => 'proposal',
    'Trade'               => 'accepted',
    'Trade Rejected'      => 'rejected',
    'Trade Revoked'       => 'revoked',
    'Trade Offer Expired' => 'expired',
];

/**
 * Parse MFL's report timestamps, e.g. "Wed Nov 18 6:24:21 a.m. CT 2020".
 *
 * Non-standard in two ways PHP won't take directly: "a.m."/"p.m." with
 * dots, and "CT" as a zone abbreviation. Every row observed across all
 * 11 seasons uses CT, so the zone is normalised to America/Chicago, which
 * also handles the CST/CDT switch that a fixed offset would get wrong for
 * roughly half the season.
 */
function rotc_parse_mfl_datetime(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $norm = str_ireplace(['a.m.', 'p.m.'], ['AM', 'PM'], $raw);
    $norm = preg_replace('/\s+[A-Z]{2,3}\s+(\d{4})$/', ' $1', $norm);
    $dt = DateTime::createFromFormat('D M j g:i:s A Y', $norm,
                                     new DateTimeZone('America/Chicago'));
    if (!$dt) return null;
    return $dt->format('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------
// Parse pass (runs with or without --dry-run)
// ---------------------------------------------------------------------
$parsed = [];
$badDates = 0;
$badStatus = 0;
foreach ($data['rows'] as $r) {
    $status = STATUS_MAP[$r['status'] ?? ''] ?? null;
    if ($status === null) { $badStatus++; continue; }

    $occurred = rotc_parse_mfl_datetime((string) ($r['date'] ?? ''));
    if ($occurred === null) { $badDates++; continue; }

    $season  = (int) $r['season'];
    $league  = (string) ($data['seasons'][(string) $season]['league_id'] ?? '');
    $expires = rotc_parse_mfl_datetime((string) ($r['expires'] ?? ''));

    $row = [
        'season'           => $season,
        'league_id'        => $league,
        'status'           => $status,
        'proposer_mfl_id'  => (string) ($r['proposer'] ?? ''),
        'recipient_mfl_id' => (string) ($r['recipient'] ?? ''),
        'proposer_gave'    => (string) ($r['proposer_gave'] ?? ''),
        'recipient_gave'   => (string) ($r['recipient_gave'] ?? ''),
        'reason'           => mb_substr((string) ($r['reason'] ?? ''), 0, 1000),
        'occurred_at'      => $occurred,
        'expires_at'       => $expires,
    ];
    // Content hash: identity is season + who + when + what + outcome. Two
    // genuinely distinct offers can share everything but the timestamp,
    // which MFL records to the second, so this is safe to key on.
    $row['row_hash'] = sha1(implode('|', [
        $row['season'], $row['status'], $row['proposer_mfl_id'],
        $row['recipient_mfl_id'], $row['occurred_at'],
        $row['proposer_gave'], $row['recipient_gave'],
    ]));
    $parsed[] = $row;
}

printf("parsed %d rows (%d unparseable dates, %d unknown statuses)\n",
       count($parsed), $badDates, $badStatus);

$hashes = array_column($parsed, 'row_hash');
$dupes  = count($hashes) - count(array_unique($hashes));
if ($dupes > 0) {
    // Not fatal: identical offers repeated within the same second collapse
    // to one row. Reported so it never silently changes a count.
    printf("note: %d rows collapse to an existing hash\n", $dupes);
}

if ($dryRun) {
    $byStatus = [];
    foreach ($parsed as $p) $byStatus[$p['status']] = ($byStatus[$p['status']] ?? 0) + 1;
    foreach ($byStatus as $k => $v) printf("  %-10s %d\n", $k, $v);
    exit(0);
}

// ---------------------------------------------------------------------
// Write pass
// ---------------------------------------------------------------------
foreach (['ROTCHIST_WRITE_DB_HOST', 'ROTCHIST_WRITE_DB_NAME',
          'ROTCHIST_WRITE_DB_USER', 'ROTCHIST_WRITE_DB_PASS'] as $c) {
    if (!defined($c)) {
        fwrite(STDERR, "config.php is missing $c -- see this file's header\n");
        exit(1);
    }
}

$pdo = new PDO(
    'mysql:host=' . ROTCHIST_WRITE_DB_HOST . ';dbname=' . ROTCHIST_WRITE_DB_NAME
        . ';charset=utf8mb4',
    ROTCHIST_WRITE_DB_USER, ROTCHIST_WRITE_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Resolve MFL franchise ids to the stable rotchist_franchises identity the
// rest of the history pages join on. Done here rather than in the page so
// the join stays cheap, and left NULL when unresolvable -- the page falls
// back to the season's own franchise name, same as rotchist_mfl_games.
$resolved = [];
try {
    $q = $pdo->query("SELECT season, mfl_franchise_id, franchise_id
                        FROM rotchist_mfl_franchises");
    foreach ($q as $row) {
        $resolved[$row['season'] . ':' . $row['mfl_franchise_id']] = $row['franchise_id'];
    }
    printf("loaded %d franchise identity mappings\n", count($resolved));
} catch (Throwable $e) {
    // rotchist_mfl_franchises may not carry franchise_id in every install.
    fwrite(STDERR, "warning: identity resolution unavailable ({$e->getMessage()});"
                 . " storing MFL ids only\n");
}

$sql = "INSERT INTO rotchist_trade_offers
          (season, league_id, status, proposer_mfl_id, recipient_mfl_id,
           proposer_id, recipient_id, proposer_gave, recipient_gave,
           reason, occurred_at, expires_at, row_hash)
        VALUES
          (:season, :league_id, :status, :proposer_mfl_id, :recipient_mfl_id,
           :proposer_id, :recipient_id, :proposer_gave, :recipient_gave,
           :reason, :occurred_at, :expires_at, :row_hash)
        ON DUPLICATE KEY UPDATE
           proposer_id    = VALUES(proposer_id),
           recipient_id   = VALUES(recipient_id),
           proposer_gave  = VALUES(proposer_gave),
           recipient_gave = VALUES(recipient_gave),
           reason         = VALUES(reason),
           expires_at     = VALUES(expires_at)";
$stmt = $pdo->prepare($sql);

$pdo->beginTransaction();
$n = 0;
foreach ($parsed as $row) {
    $row['proposer_id']  = $resolved[$row['season'] . ':' . $row['proposer_mfl_id']] ?? null;
    $row['recipient_id'] = $resolved[$row['season'] . ':' . $row['recipient_mfl_id']] ?? null;
    $stmt->execute($row);
    $n++;
}
$pdo->commit();

printf("ingested %d rows into rotchist_trade_offers\n", $n);
$total = $pdo->query("SELECT COUNT(*) FROM rotchist_trade_offers")->fetchColumn();
printf("table now holds %d rows\n", $total);
