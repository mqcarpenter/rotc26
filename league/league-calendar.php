<?php
/**
 * league-calendar.php
 * Matches League -> League Calendar. TYPE=calendar. Confirmed live
 * with real data: event[] = {id, type, title, start_time, end_time}.
 * type is a code (TRADE, DRAFT_START, AUCTION_START, CUSTOM,
 * WAIVER_NONE, etc) -- translated to a readable label below since
 * MFL doesn't expose a separate lookup for these the way allRules does
 * for scoring events.
 */

$page_title = 'League Calendar — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$events = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';

    $raw = mfl_cached_get('calendar', 3600, []);
    $events = mfl_normalize_list($raw['calendar']['event'] ?? null);
    usort($events, fn($a, $b) => (int) ($a['start_time'] ?? 0) <=> (int) ($b['start_time'] ?? 0));
}

$TYPE_LABEL = [
    'DRAFT_START' => 'Draft Starts', 'AUCTION_START' => 'Auction Starts', 'TRADE' => 'Trade Deadline',
    'WAIVER_REVERSE' => 'Waiver Order Reverses', 'WAIVER_BBID' => 'Blind Bid Waivers Process',
    'WAIVER_UNLOCK' => 'Waivers Unlock', 'WAIVER_LOCK' => 'Waivers Lock', 'WAIVER_NONE' => 'Free Agency Opens',
    'CUSTOM' => 'League Event',
];
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>League calendar isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">League Calendar</h2>
        <?php if (!$events): ?>
          <p>No calendar events set up.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
          <table class="data-table">
            <thead><tr><th>Event</th><th>Starts</th><th>Ends</th></tr></thead>
            <tbody>
              <?php foreach ($events as $i => $e):
                $label = $TYPE_LABEL[$e['type'] ?? ''] ?? ($e['type'] ?? '');
                if (!empty($e['title'])) $label = $e['title'];
              ?>
                <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
                  <td><?= htmlspecialchars($label) ?></td>
                  <td><?= !empty($e['start_time']) ? htmlspecialchars(date('M j, Y g:i a', (int) $e['start_time'])) : '—' ?></td>
                  <td><?= !empty($e['end_time']) ? htmlspecialchars(date('M j, Y g:i a', (int) $e['end_time'])) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
