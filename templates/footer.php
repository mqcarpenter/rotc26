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
        <li><a href="#">Rules</a></li>
        <li><a href="#">League FAQ</a></li>
        <li><a href="#">Constitution</a></li>
        <li><a href="#">League Scoring</a></li>
        <li><a href="#">Settings</a></li>
        <li><a href="#">History</a></li>
      </ul>
    </div>

    <div class="rotc-foot-group">
      <div class="rotc-foot-group">
        <h4>Team Management</h4>
        <ul>
          <li><a href="#">Submit Lineup</a></li>
          <li><a href="#">Add / Drop</a></li>
        </ul>
      </div>
      <div class="rotc-foot-group">
        <h4>Quick Links</h4>
        <ul>
          <li><a href="#">Rosters</a></li>
          <li><a href="#">Standings</a></li>
          <li><a href="#">Rankings</a></li>
          <li><a href="#">Schedule</a></li>
          <li><a href="#">News</a></li>
          <li><a href="#">Free Agents</a></li>
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
