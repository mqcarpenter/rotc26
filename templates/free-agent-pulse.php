<?php
/**
 * templates/free-agent-pulse.php
 * Second sidebar tabbed widget, below templates/sidefeed.php's Smack
 * Feed / Top Adds-Drops. Reuses the SAME .rotc-sidefeed/.rotc-sidefeed-
 * tab(s)/.rotc-sidefeed-panel CSS + tab-switching JS as sidefeed.php --
 * those classes aren't Smack-Feed-specific, and the JS in sidefeed.php
 * now waits for DOMContentLoaded before wiring up tabs, so a second
 * widget added later in the page works with no JS/CSS changes needed.
 *
 * $top_free_agents / $adp_trends (array) - rows from
 *   includes/free-agent-pulse.php's rotc_fetch_top_free_agents() /
 *   rotc_fetch_adp_trends(). Player names use rotc_player_hover_span()
 *   (same hover card every other player list on the site uses) -- the
 *   including page must call rotc_player_hover_widget() once (index.php
 *   already does, for sidefeed.php's rows).
 */
$top_free_agents = $top_free_agents ?? [];
$adp_trends = $adp_trends ?? [];

/** One free-agent row: logo, hoverable name, position, Week 1 projected points. */
function rotc_fa_pulse_row(array $r): void {
?>
  <div class="rotc-trending-row">
    <?= rotc_team_logo_img($r['team'], 18) ?>
    <span class="rotc-trending-name"><?= rotc_player_hover_span($r['name'], $r['pd'], ['Wk 1 Proj' => number_format($r['proj'], 1) . ' pts']) ?></span>
    <span class="rotc-trending-pos"><?= htmlspecialchars($r['position']) ?></span>
    <span class="rotc-trending-pct"><?= number_format($r['proj'], 1) ?></span>
  </div>
<?php
}

/** One ADP-trend row: same as above, plus an up/down/flat arrow (blank if no baseline to compare against). */
function rotc_adp_trend_row(array $r): void {
    $arrow = '';
    $arrowClass = '';
    if ($r['trend'] === 'up') { $arrow = '&#9650;'; $arrowClass = 'rotc-trend-up'; }
    elseif ($r['trend'] === 'down') { $arrow = '&#9660;'; $arrowClass = 'rotc-trend-down'; }
    elseif ($r['trend'] === 'flat') { $arrow = '&#8212;'; $arrowClass = 'rotc-trend-flat'; }
?>
  <div class="rotc-trending-row">
    <?= rotc_team_logo_img($r['team'], 18) ?>
    <span class="rotc-trending-name"><?= rotc_player_hover_span($r['name'], $r['pd'], ['ADP' => $r['avgPick'] !== '' ? number_format((float) $r['avgPick'], 1) : '']) ?></span>
    <span class="rotc-trending-pos"><?= htmlspecialchars($r['position']) ?></span>
    <?php if ($arrow !== ''): ?>
      <?php
        $titleWord = $r['trend'] === 'up' ? 'Up' : ($r['trend'] === 'down' ? 'Down' : 'No significant change');
        $title = $r['trend'] === 'flat' ? $titleWord . ' vs. earlier this month' : $titleWord . ' ' . number_format(abs($r['trendAmount']), 1) . ' picks vs. earlier this month';
      ?>
      <span class="rotc-trend-arrow <?= $arrowClass ?>" title="<?= htmlspecialchars($title) ?>"><?= $arrow ?></span>
    <?php endif; ?>
    <span class="rotc-trending-pct"><?= $r['rank'] !== '' ? '#' . htmlspecialchars($r['rank']) : '' ?></span>
  </div>
<?php
}
?>
<div class="rotc-sidefeed">
  <div class="rotc-sidefeed-tabs">
    <button class="rotc-sidefeed-tab active" data-tab="topfa">Top Free Agents</button>
    <button class="rotc-sidefeed-tab" data-tab="adptrends">Draft Trends</button>
  </div>

  <div class="rotc-sidefeed-panel active" data-panel="topfa">
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 20 Available &mdash; Week 1 Projection</div>
      <?php if (!$top_free_agents): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No free agent data available.</div></div>
      <?php else: foreach ($top_free_agents as $r) rotc_fa_pulse_row($r); endif; ?>
    </div>
  </div>

  <div class="rotc-sidefeed-panel" data-panel="adptrends">
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 20 Draft Picks &mdash; Trend vs. Earlier This Month</div>
      <?php if (!$adp_trends): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No draft trend data available.</div></div>
      <?php else: foreach ($adp_trends as $r) rotc_adp_trend_row($r); endif; ?>
    </div>
  </div>
</div>
