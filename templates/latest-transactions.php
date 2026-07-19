<?php
/**
 * templates/latest-transactions.php
 * "Latest Transactions" sidebar card, below the Top Free Agents/Draft
 * Trends tabbed widget (templates/free-agent-pulse.php). $latest_txns
 * comes from rotc_fetch_latest_transactions() in includes/transactions.php
 * -- rows already include 'badge'/'detailsHtml'/'franchiseName'
 * precomputed, detailsHtml already using the shared player hover card
 * (includes/player-hover.php) same as every other player list on the
 * site. Including page must call rotc_player_hover_widget() once
 * (index.php already does).
 */
$latest_txns = $latest_txns ?? [];
?>
<div class="card">
  <h2 class="card-title">Latest Transactions</h2>
  <?php if (!$latest_txns): ?>
    <p style="color:var(--muted);font-size:13px;">No transactions yet.</p>
  <?php else: ?>
    <div class="rotc-latest-txns">
      <?php foreach ($latest_txns as $t): ?>
        <div class="rotc-latest-txn-row">
          <div class="rotc-latest-txn-top">
            <span class="rotc-txn-pill <?= htmlspecialchars($t['badge']['class']) ?>"><?= htmlspecialchars($t['badge']['label']) ?></span>
            <span class="rotc-latest-txn-franchise"><?= htmlspecialchars($t['franchiseName']) ?></span>
            <span class="rotc-latest-txn-date"><?= htmlspecialchars(date('M j', (int) ($t['timestamp'] ?? 0))) ?></span>
          </div>
          <div class="rotc-latest-txn-details"><?= $t['detailsHtml'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <a href="<?= $base ?>/transactions/transactions" class="rotc-latest-txns-link">Full Transactions Report &rarr;</a>
</div>
