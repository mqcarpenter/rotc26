<?php
/**
 * rotchist-db.php
 * Shared PDO connection to the rotchist_ history database (see
 * rotchist_schema.sql / rotchist_mfl_schema.sql, populated by
 * rotchist_ingest.php / rotchist_mfl_ingest.php). Every history/*.php page
 * calls rotchist_db() to get a connection instead of opening its own.
 *
 * Deliberately uses a SEPARATE, read-only MySQL user from the one the
 * ingest scripts use -- the live site only ever needs SELECT, so it never
 * even holds credentials capable of writing to this data. Create that user
 * in phpMyAdmin (User accounts -> add user -> grant only SELECT on the
 * database), then add these four constants to the site's existing
 * (git-ignored) config.php alongside the MFL config:
 *
 *   define('ROTCHIST_READ_DB_HOST', 'localhost');
 *   define('ROTCHIST_READ_DB_NAME', '...');       // same DB as the ingest scripts write to
 *   define('ROTCHIST_READ_DB_USER', '...');       // the new SELECT-only user, NOT the ingest user
 *   define('ROTCHIST_READ_DB_PASS', '...');
 */

function rotchist_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($pdo !== null || $tried) return $pdo;
    $tried = true;

    if (!defined('ROTCHIST_READ_DB_HOST') || !defined('ROTCHIST_READ_DB_NAME')
        || !defined('ROTCHIST_READ_DB_USER') || !defined('ROTCHIST_READ_DB_PASS')) {
        return null; // config.php hasn't been updated with the rotchist_ read-only DB constants yet
    }

    try {
        $dsn = 'mysql:host=' . ROTCHIST_READ_DB_HOST . ';dbname=' . ROTCHIST_READ_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, ROTCHIST_READ_DB_USER, ROTCHIST_READ_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}
