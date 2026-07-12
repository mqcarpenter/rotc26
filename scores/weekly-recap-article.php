<?php
/**
 * scores/weekly-recap-article.php
 * The "go another level" version of the front-page recap -- a full,
 * long-form article per matchup, matching the structure of MFL's own
 * Fantasy Recaps page (myfantasyleague.com/options?O=177&W=N) as
 * closely as the API allows. See includes/weekly-recap.php's doc
 * comment on rotc_weekly_recap_article() for exactly what could and
 * couldn't be reproduced and why (short version: individual raw NFL
 * stat lines and real attributed quotes aren't available through the
 * API; full box scores, bench-mismanagement callouts, positional
 * week-rank, and next-week opponents are, and this uses all of them).
 *
 * PLACEHOLDER DATA: 2026 has no completed weeks yet, so this defaults
 * to the fully-completed 2025 season (?year=2025) with a week selector
 * across all 17 weeks. Once 2026 has real completed weeks, default
 * $year to (int) MFL_YEAR instead -- see the TODO below.
 */

$page_title = 'Weekly Recap — Return of the Champions XXVI';
$current_tab = '';

include __DIR__ . '/../templates/header.php';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

// TODO once 2026 Week 1 is complete: default $year to (int) MFL_YEAR
// (after config is loaded) instead of hardcoding 2025.
$year = max(2020, (int) ($_GET['year'] ?? 2025));
$week = max(1, min(17, (int) ($_GET['week'] ?? 17)));
$recap = null;

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/helmets.php';
    require_once __DIR__ . '/../includes/player-hover.php';
    require_once __DIR__ . '/../includes/weekly-recap.php';

    $recap = rotc_weekly_recap_article($year, $week);
}

/** Verb + score line, same margin bands the homepage hub uses. */
function rotc_article_result_line(array $winner, array $loser, float $margin): string {
    if ($margin < 3) return "{$winner['name']} edged out {$loser['name']} by just {$margin} points";
    if ($margin > 40) return "{$winner['name']} blew past {$loser['name']} by {$margin} points";
    return "{$winner['name']} beat {$loser['name']} " . number_format($winner['score'], 2) . "\u{2013}" . number_format($loser['score'], 2);
}

function rotc_article_performer_line(array $side, bool $isWinner): string {
    $tp = $side['topPerformer'];
    if (!$tp) return '';
    $rankTxt = '';
    if ($tp['positionRank']) {
        $rankTxt = ' &mdash; the ' . rotc_ordinal($tp['positionRank']['rank']) . '-best ' . htmlspecialchars($tp['pd']['position'] ?? '') . ' performance in the league this week';
    }
    $lead = $isWinner ? "{$side['name']} were led by " : "{$side['name']} got a strong effort from ";
    return htmlspecialchars($lead) . rotc_player_hover_span($tp['name'], $tp['pd'], ['Week ' . $GLOBALS['week'] . ' Score' => number_format($tp['score'], 1) . ' pts'])
        . ' with ' . htmlspecialchars(number_format($tp['score'], 1)) . ' fantasy points' . $rankTxt . '.';
}

function rotc_ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
}

function rotc_article_bench_line(array $side): string {
    if (!$side['benchMiss'] || !$side['optPts']) return '';
    $gap = round($side['optPts'] - $side['score'], 2);
    if ($gap < 3) return '';
    return htmlspecialchars($side['name'] . ' could have scored ' . number_format($side['optPts'], 2) . ' points with their best possible lineup -- ')
        . rotc_player_hover_span($side['benchMiss']['name'], $side['benchMiss']['pd'], ['Left on Bench' => number_format($side['benchMiss']['score'], 1) . ' pts'])
        . ' and ' . htmlspecialchars(number_format($side['benchMiss']['score'], 1)) . ' points stayed on the bench.';
}

function rotc_article_box_score(array $side): void {
    if (!$side['boxScore']) { echo '<p style="color:var(--muted);font-size:13px;">No starter data available.</p>'; return; }
    ?>
    <table class="data-table" style="margin-top:8px;">
      <thead><tr><th></th><th>Player</th><th>Pos</th><th>Pts</th></tr></thead>
      <tbody>
        <?php foreach ($side['boxScore'] as $i => $b): ?>
          <tr class="<?= $i % 2 === 0 ? 'odd' : 'even' ?>">
            <td><?= rotc_team_logo_img($b['pd']['team'] ?? '', 18) ?></td>
            <td><?= rotc_player_hover_span($b['name'], $b['pd'], ['This Week' => number_format($b['score'], 1) . ' pts']) ?></td>
            <td><?= htmlspecialchars($b['position']) ?></td>
            <td><?= htmlspecialchars(number_format($b['score'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Recaps aren't available right now — check back soon.</p></div>
    <?php else: ?>
      <div class="card">
        <h2 class="card-title">Week <?= htmlspecialchars($week) ?> Recap &mdash; <?= htmlspecialchars($year) ?> Season</h2>
        <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Full recaps generated from real scoring data. Hover a player's name for their stat line.</p>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 4px;">
          <?php for ($w = 1; $w <= 17; $w++): ?>
            <a href="?year=<?= $year ?>&week=<?= $w ?>" style="padding:4px 9px;border-radius:6px;border:1px solid var(--line);font-size:13px;<?= $week === $w ? 'background:var(--ink);color:var(--on-ink);' : '' ?>">Wk <?= $w ?></a>
          <?php endfor; ?>
        </div>
      </div>

      <?php if (!$recap || !$recap['games']): ?>
        <div class="card"><p>No results for Week <?= htmlspecialchars($week) ?> yet.</p></div>
      <?php else: ?>
        <?php foreach ($recap['games'] as $game):
          $winner = $game['a']['score'] >= $game['b']['score'] ? $game['a'] : $game['b'];
          $loser  = $game['a']['score'] >= $game['b']['score'] ? $game['b'] : $game['a'];
        ?>
          <div class="card">
            <?php if ($game['isGameOfWeek']): ?>
              <div class="rotc-recap-kicker">Game of the Week</div>
            <?php endif; ?>
            <h2 class="card-title" style="margin-top:<?= $game['isGameOfWeek'] ? '2px' : '0' ?>;"><?= htmlspecialchars($winner['name']) ?> Defeats <?= htmlspecialchars($loser['name']) ?></h2>
            <p style="color:var(--muted);font-size:13px;margin-top:-8px;">Final: <?= htmlspecialchars(number_format($winner['score'], 2)) ?>&ndash;<?= htmlspecialchars(number_format($loser['score'], 2)) ?> &middot; <?= htmlspecialchars($winner['name']) ?> (<?= htmlspecialchars($winner['record']) ?>) &middot; <?= htmlspecialchars($loser['name']) ?> (<?= htmlspecialchars($loser['record']) ?>)</p>

            <p><?= htmlspecialchars(rotc_article_result_line($winner, $loser, $game['margin'])) ?>.</p>
            <p><?= rotc_article_performer_line($winner, true) ?></p>
            <p><?= rotc_article_performer_line($loser, false) ?></p>
            <?php $winnerBench = rotc_article_bench_line($winner); if ($winnerBench): ?><p><?= $winnerBench ?></p><?php endif; ?>
            <?php $loserBench = rotc_article_bench_line($loser); if ($loserBench): ?><p><?= $loserBench ?></p><?php endif; ?>

            <?php if ($winner['nextOpponent'] || $loser['nextOpponent']): ?>
              <p style="color:var(--muted);">
                Up next in Week <?= htmlspecialchars($week + 1) ?>:
                <?php if ($winner['nextOpponent']): ?><?= htmlspecialchars($winner['name']) ?> face the (<?= htmlspecialchars($winner['nextOpponent']['record']) ?>) <?= htmlspecialchars($winner['nextOpponent']['name']) ?>.<?php endif; ?>
                <?php if ($loser['nextOpponent']): ?> <?= htmlspecialchars($loser['name']) ?> face the (<?= htmlspecialchars($loser['nextOpponent']['record']) ?>) <?= htmlspecialchars($loser['nextOpponent']['name']) ?>.<?php endif; ?>
              </p>
            <?php endif; ?>

            <p style="font-style:italic;color:var(--muted);font-size:13px;"><?= htmlspecialchars($winner['flavor']) ?></p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px;margin-top:12px;">
              <div>
                <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;margin:0;"><?= htmlspecialchars($winner['name']) ?></h3>
                <?php rotc_article_box_score($winner); ?>
              </div>
              <div>
                <h3 style="font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;font-size:14px;margin:0;"><?= htmlspecialchars($loser['name']) ?></h3>
                <?php rotc_article_box_score($loser); ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php if ($recap) rotc_player_hover_widget(); ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
