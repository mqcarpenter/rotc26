<?php
/**
 * rotchist-db.php
 * Shared read-only PDO connection to the rotchist_ history database (see
 * rotchist_schema.sql / rotchist_mfl_schema.sql, populated by
 * rotchist_ingest.php / rotchist_mfl_ingest.php). Every history/*.php page
 * calls rotchist_db() to get a connection instead of opening its own.
 *
 * Expects these four constants to already be defined — add them to the
 * site's existing (git-ignored) config.php alongside the MFL config:
 *
 *   define('ROTCHIST_DB_HOST', 'localhost');
 *   define('ROTCHIST_DB_NAME', '...');
 *   define('ROTCHIST_DB_USER', '...');
 *   define('ROTCHIST_DB_PASS', '...');
 *
 * Same values you put in rotchist_ingest.php / rotchist_mfl_ingest.php when
 * you ran the backfill — this just lets the live site pages query the same
 * database those scripts populated.
 */

function rotchist_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($pdo !== null || $tried) return $pdo;
    $tried = true;

    if (!defined('ROTCHIST_DB_HOST') || !defined('ROTCHIST_DB_NAME')
        || !defined('ROTCHIST_DB_USER') || !defined('ROTCHIST_DB_PASS')) {
        return null; // config.php hasn't been updated with the rotchist_ DB constants yet
    }

    try {
        $dsn = 'mysql:host=' . ROTCHIST_DB_HOST . ';dbname=' . ROTCHIST_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, ROTCHIST_DB_USER, ROTCHIST_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}
