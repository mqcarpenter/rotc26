<?php
/**
 * templates/auction-summary.php
 * Front-page auction status strip -- four pills, nothing else. Replaced
 * the on-the-clock draft module (index.php) once the league moved from
 * the snake draft to the live auction; the draft module's code is gone
 * rather than commented out, since includes/draft-board.php still backs
 * the full /draft-board page if it's ever wanted back here.
 *
 * Expects $auctionSummary (from rotc_auction_summary(), see
 * includes/auction.php) and $base to already be set by the caller.
 */
$s = $auctionSummary ?? null;
if (!$s) return;
$auctionPageUrl = ($base ?? '') . '/draft-auction/auction-bid';
?>
<div class="rotc-auction-strip">
  <div class="rotc-auction-strip-head">
    <p class="rotc-auction-eyebrow">💰 <?= (int) (defined('MFL_YEAR') ? MFL_YEAR : date('Y')) ?> Auction</p>
    <h2 class="rotc-auction-title">
      <?php if (!$s['live']): ?>Auction feed unavailable
      <?php elseif ($s['paused']): ?>Paused
      <?php elseif ($s['active'] > 0): ?>Bidding is open
      <?php elseif ($s['completed'] > 0): ?>No open auctions
      <?php else: ?>Nothing up for bid yet<?php endif; ?>
    </h2>
    <?php // The next hard deadline. Auctions stop 24h after their opening
          // bid with no extension, so this is the one number worth putting
          // in front of someone who hasn't opened the auction page. ?>
    <?php if ($s['active'] > 0 && !empty($s['soonestEnd'])): ?>
      <p class="rotc-auction-closes<?= rotc_auction_is_closing($s['soonestEnd']) ? ' closing' : '' ?>">
        Next closes in <?= htmlspecialchars(rotc_auction_time_left($s['soonestEnd'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="rotc-auction-pills">
    <span class="rotc-auction-pill<?= $s['active'] > 0 ? ' hot' : '' ?>">
      <b><?= (int) $s['active'] ?></b> Active
    </span>
    <span class="rotc-auction-pill">
      <b><?= htmlspecialchars(rotc_auction_fmt_money($s['bidTotal'])) ?></b> Bid
    </span>
    <span class="rotc-auction-pill">
      <b><?= (int) $s['completed'] ?></b> Closed
    </span>
    <span class="rotc-auction-pill">
      <b><?= htmlspecialchars(rotc_auction_fmt_money($s['spent'])) ?></b> Spent
    </span>
  </div>

  <div class="rotc-auction-actions">
    <a class="rotc-draft-cta" href="<?= htmlspecialchars($auctionPageUrl) ?>">Bid &amp; Nominate &rarr;</a>
    <a class="rotc-onclock-secondary" href="<?= htmlspecialchars(($base ?? '') . '/draft-auction/auction-results') ?>">Results</a>
  </div>
</div>
