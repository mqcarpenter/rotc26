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
 * Second tab used to be "Draft Trends" (external MFL-wide ADP, not this
 * league's own data) -- replaced with "Draft Value": THIS league's real
 * snake-draft + auction results compared against season points actually
 * scored, rendered as steal/bust bars instead of a plain ranked list.
 *
 * $top_free_agents (array) - rows from
 *   includes/free-agent-pulse.php's rotc_fetch_top_free_agents().
 * $draft_value / $auction_value (array{steals,busts}) - rows from
 *   includes/draft-value.php's rotc_fetch_draft_value() /
 *   rotc_fetch_auction_value().
 * Player names use rotc_player_hover_span() (same hover card every
 * other player list on the site uses) -- the including page must call
 * rotc_player_hover_widget() once (index.php already does, for
 * sidefeed.php's rows).
 */
$top_free_agents = $top_free_agents ?? [];
$draft_value = $draft_value ?? ['steals' => [], 'busts' => []];
$auction_value = $auction_value ?? ['steals' => [], 'busts' => []];

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

/**
 * One draft/auction-value bar row: name/position/team + acquisition
 * cost label (caller-supplied: "Rd X, Pick Y" or "$Z"), a horizontal
 * bar whose fill width is this row's |value| relative to $maxVal (the
 * biggest |value| across BOTH the steals and busts shown in this
 * group, so a huge steal doesn't get visually dwarfed by an even
 * bigger bust or vice versa), and the signed points-vs-baseline delta.
 * $kind is 'steal' (green fill, positive delta) or 'bust' (red fill).
 */
function rotc_value_row(array $r, string $kind, float $maxVal, string $costLabel): void {
    $pct = $maxVal > 0 ? max(6, min(100, round(abs($r['value']) / $maxVal * 100))) : 0;
    $sign = $r['value'] > 0 ? '+' : ($r['value'] < 0 ? '−' : '');
?>
  <div class="rotc-value-row">
    <div class="rotc-value-head">
      <?= rotc_team_logo_img($r['team'], 18) ?>
      <span class="rotc-value-name"><?= rotc_player_hover_span($r['name'], $r['pd'], ['Season Pts' => number_format($r['points'], 1)]) ?></span>
      <span class="rotc-trending-pos"><?= htmlspecialchars($r['position']) ?></span>
    </div>
    <div class="rotc-value-bartrack">
      <div class="rotc-value-barfill <?= $kind ?>" style="width:<?= $pct ?>%;"></div>
    </div>
    <div class="rotc-value-meta">
      <span><?= htmlspecialchars($costLabel) ?></span>
      <span class="rotc-value-delta <?= $kind ?>"><?= $sign ?><?= number_format(abs($r['value']), 1) ?> pts vs. expected</span>
    </div>
  </div>
<?php
}

/** Renders one steals+busts group (a heading plus its bar rows), or a placeholder if empty. */
function rotc_value_group(string $heading, array $steals, array $busts, callable $costLabelFor): void {
    $all = array_merge($steals, $busts);
    $maxVal = 0.0;
    foreach ($all as $r) $maxVal = max($maxVal, abs($r['value']));
?>
  <div class="rotc-trending-group">
    <div class="rotc-trending-heading"><?= htmlspecialchars($heading) ?></div>
    <?php if (!$all): ?>
      <div class="rotc-sidefeed-item"><div class="desc">No value data available yet this season.</div></div>
    <?php else: ?>
      <?php foreach ($steals as $r) rotc_value_row($r, 'steal', $maxVal, $costLabelFor($r)); ?>
      <?php foreach ($busts as $r) rotc_value_row($r, 'bust', $maxVal, $costLabelFor($r)); ?>
    <?php endif; ?>
  </div>
<?php
}
?>
<div class="rotc-sidefeed">
  <div class="rotc-sidefeed-tabs">
    <button class="rotc-sidefeed-tab active" data-tab="topfa">Top Free Agents</button>
    <button class="rotc-sidefeed-tab" data-tab="draftvalue">Draft Value</button>
  </div>

  <div class="rotc-sidefeed-panel active" data-panel="topfa">
    <div class="rotc-trending-group">
      <div class="rotc-trending-heading">Top 20 Available &mdash; Week 1 Projection</div>
      <?php if (!$top_free_agents): ?>
        <div class="rotc-sidefeed-item"><div class="desc">No free agent data available.</div></div>
      <?php else: foreach ($top_free_agents as $r) rotc_fa_pulse_row($r); endif; ?>
    </div>
  </div>

  <div class="rotc-sidefeed-panel" data-panel="draftvalue">
    <?php
      $costLabelDraft = fn(array $r) => 'Rd ' . $r['round'] . ', Pick ' . $r['pick'] . ($r['franchise'] !== '' ? ' — ' . $r['franchise'] : '');
      $costLabelAuction = fn(array $r) => '$' . number_format($r['price'], 0) . ($r['franchise'] !== '' ? ' — ' . $r['franchise'] : '');
      rotc_value_group('Draft Steals & Busts — ' . (int) MFL_YEAR . ' Snake Draft', $draft_value['steals'], $draft_value['busts'], $costLabelDraft);
      rotc_value_group('Auction Steals & Busts — ' . (int) MFL_YEAR, $auction_value['steals'], $auction_value['busts'], $costLabelAuction);
    ?>
  </div>
</div>
