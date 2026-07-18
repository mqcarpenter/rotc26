<?php
/**
 * footer.php
 * Three-column dark footer (rotc-foot). Direct port of the live
 * mfl26.css grid (1.6fr / 1fr / 1fr).
 *
 * Column 3 groups two link sets ("Team Management" + "League")
 * stacked — the live footer text had three link groups but the
 * CSS grid only defines three columns total (one is the brand
 * block), so this is my best read of the intended layout.
 * Reshuffle freely if you had something else in mind.
 */
?>
<footer class="rotc-foot">
  <div class="rotc-foot-grid">
    <div class="rotc-foot-brand">
      <b>Return of the <span>Champions</span></b>
      <p>The quest to be the best. A 26-season dynasty keeper IDP auction league.</p>
    </div>

    <div class="rotc-foot-group">
      <h4>League</h4>
      <ul>
        <li><a href="https://www.returnofthechampions.com/faq/keeper-requirements/">Keeper Rules</a></li>
        <li><a href="https://www.returnofthechampions.com/faq/">League FAQ</a></li>
        <li><a href="https://www.returnofthechampions.com/faq/league-scoring/">League Scoring</a></li>
        <li><a href="<?= $base ?>/history/">History</a></li>
      </ul>
    </div>

    <div class="rotc-foot-group">
      <div class="rotc-foot-group">
        <h4>Team Management</h4>
        <ul>
          <li><a href="<?= $base ?>/franchise/submit-lineup.php">Submit Lineup</a></li>
          <li><a href="<?= $base ?>/franchise/drop-player.php">Add / Drop</a></li>
        </ul>
      </div>
      <div class="rotc-foot-group">
        <h4>Quick Links</h4>
        <ul>
          <li><a href="<?= $base ?>/transactions/rosters">Rosters</a></li>
          <li><a href="<?= $base ?>/scores/standings">Standings</a></li>
          <li><a href="<?= $base ?>/scores/power-rank">Rankings</a></li>
          <li><a href="<?= $base ?>/scores/fantasy-schedule">Schedule</a></li>
          <li><a href="<?= htmlspecialchars($mfl . '/news_articles?L=67102&P=*') ?>">News</a></li>
          <li><a href="<?= $base ?>/players/free-agents">Free Agents</a></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="rotc-foot-bar">
    <div>
      <span>&copy; <?= date('Y') ?> Return of the Champions. Not affiliated with the NFL.</span>
      <span><a href="#">Privacy Policy</a> &middot; <a href="#">Terms</a></span>
    </div>
  </div>
</footer>
</body>
</html>
