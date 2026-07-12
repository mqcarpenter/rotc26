<?php
/**
 * playoff-brackets.php
 * Matches Scores -> Playoff Brackets. TYPE=playoffBrackets lists each
 * bracket (ROTC Championship, consolation, etc), TYPE=playoffBracket
 * (BRACKET_ID=n) gives that bracket's rounds/games -- each game is
 * either seeded (home/away have a "seed") or references a prior game's
 * winner ("winner_of_game"). Confirmed live: a week with a single game
 * collapses "playoffGame" to a bare object instead of a one-item list,
 * same MFL quirk as everywhere else -- run through mfl_normalize_list().
 */

$page_title = 'Playoff Brackets — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$brackets = [];
$franchises = [];
$bracketDetails = [];

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('playoffBrackets', 3600, []);
    $brackets = mfl_normalize_list($raw['playoffBrackets']['playoffBracket'] ?? null);

    foreach ($brackets as $b) {
        $detail = mfl_cached_get('playoffBracket', 3600, ['BRACKET_ID' => $b['id']]);
        $bracketDetails[$b['id']] = mfl_normalize_list($detail['playoffBracket']['playoffRound'] ?? null);
    }
}

function rotc_seed_label(?array $side, array $franchises): string {
    if (!$side) return 'TBD';
    if (isset($side['seed'])) return '#' . $side['seed'] . ' Seed';
    if (isset($side['winner_of_game'])) return 'Winner of Game #' . $side['winner_of_game'];
    if (isset($side['id'])) return $franchises[$side['id']]['name'] ?? $side['id'];
    return 'TBD';
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Playoff brackets aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Playoff Brackets</h2>
        <?php if (!$brackets): ?>
          <p>No playoff brackets have been set up yet.</p>
        <?php endif; ?>
      </div>

      <?php foreach ($brackets as $b): ?>
        <div class="card">
          <h2 class="card-title"><?= htmlspecialchars($b['name'] ?? '') ?></h2>
          <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Winner receives: <?= htmlspecialchars($b['bracketWinnerTitle'] ?? '') ?></p>

          <?php foreach ($bracketDetails[$b['id']] ?? [] as $round): ?>
            <h3 style="margin:14px 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;">Week <?= htmlspecialchars($round['week'] ?? '') ?></h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
              <?php foreach (mfl_normalize_list($round['playoffGame'] ?? null) as $g): ?>
                <div style="border:1px solid var(--line);border-radius:var(--radius);padding:10px;">
                  <div>Game #<?= htmlspecialchars($g['game_id'] ?? '') ?></div>
                  <div style="font-weight:600;"><?= htmlspecialchars(rotc_seed_label($g['home'] ?? null, $franchises)) ?></div>
                  <div style="color:var(--muted);font-size:12px;">vs</div>
                  <div style="font-weight:600;"><?= htmlspecialchars(rotc_seed_label($g['away'] ?? null, $franchises)) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
