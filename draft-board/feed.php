<?php
/**
 * draft-board/feed.php
 * JSON poll endpoint for the live draft big board. The board page
 * (draft-board/index.php) fetches this every ~5s. All the work is in
 * includes/draft-board.php; this just emits the state as JSON.
 *
 * Public read (the board is meant to be screen-shared): no login gate.
 * Only non-sensitive draft data is exposed (picks, projections, helmets).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
if (!file_exists($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'unavailable']);
    exit;
}

require_once $configPath;
require_once dirname(__DIR__) . '/includes/mfl-api.php';
require_once dirname(__DIR__) . '/includes/helmets.php';
require_once dirname(__DIR__) . '/includes/draft-board.php';

echo json_encode(rotc_draft_build_state(), JSON_UNESCAPED_SLASHES);
