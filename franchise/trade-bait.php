<?php
/**
 * trade-bait.php
 * Matches Franchise -> Trade Bait. TYPE=tradeBait for the public
 * listing (view-only, no login needed -- confirmed live with real
 * data: tradeBait[] = {franchise_id, willGiveUp (comma-separated
 * player ids), inExchangeFor (free text), timestamp}). If the visitor
 * is logged in (see includes/mfl-auth.php), also shows a form to set
 * THEIR OWN trade bait via import?TYPE=tradeBait (WILL_GIVE_UP,
 * IN_EXCHANGE_FOR) -- confirmed live in MFL's own Import API
 * reference. That import call fully overwrites the owner's previous
 * trade bait, matching the "Import an owner's trade bait, which will
 * overwrite his previously entered trade bait" behavior documented
 * there.
 */

$page_title = 'Trade Bait — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$fetchError = !file_exists($configPath);

$siteRootFs = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$pageBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($pageBase === '.') $pageBase = '';

$franchises = [];
$rows = [];
$isLoggedIn = false;
$myRoster = [];
$submitResult = null;

if (!$fetchError) {
    require_once $configPath;
    require_once __DIR__ . '/../includes/mfl-api.php';
    require_once __DIR__ . '/../includes/mfl-auth.php';
    rotc_session_start();
    $isLoggedIn = rotc_mfl_logged_in() && rotc_mfl_resolve_franchise_id() !== null;

    if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $submitResult = ['ok' => false, 'error' => 'Your session expired -- reload the page and try again.'];
        } else {
            $giveUp = array_filter((array) ($_POST['give_up'] ?? []));
            $wants = trim((string) ($_POST['wants'] ?? ''));
            if (!$giveUp) {
                $submitResult = ['ok' => false, 'error' => 'Pick at least one player to offer.'];
            } else {
                $params = ['WILL_GIVE_UP' => implode(',', $giveUp)];
                if ($wants !== '') $params['IN_EXCHANGE_FOR'] = $wants;
                $resp = rotc_mfl_authed_request('import', 'tradeBait', $params);
                if ($resp === null) {
                    $submitResult = ['ok' => false, 'error' => 'Could not reach MyFantasyLeague. Try again in a moment.' . (rotc_mfl_last_error() ? ' [' . rotc_mfl_last_error() . ']' : '')];
                } elseif (isset($resp['error'])) {
                    $submitResult = ['ok' => false, 'error' => is_array($resp['error']) ? ($resp['error']['message'] ?? json_encode($resp['error'])) : (string) $resp['error']];
                } else {
                    $submitResult = ['ok' => true];
                }
            }
        }
    }

    if ($isLoggedIn) {
        $myRosterResp = rotc_mfl_authed_request('export', 'rosters', ['FRANCHISE' => rotc_mfl_franchise_id()]);
        $myRoster = mfl_normalize_list($myRosterResp['rosters']['franchise']['player'] ?? null);
    }

    $franchises = mfl_franchises();
    $raw = mfl_cached_get('tradeBait', 900, []);
    $rows = mfl_normalize_list($raw['tradeBaits']['tradeBait'] ?? null);
    usort($rows, fn($a, $b) => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));

    $allIds = [];
    foreach ($rows as $r) { foreach (explode(',', $r['willGiveUp'] ?? '') as $id) { if ($id !== '') $allIds[] = $id; } }
    foreach ($myRoster as $p) { if (!empty($p['id'])) $allIds[] = $p['id']; }
    $players = [];
    if ($allIds) {
        foreach (array_chunk(array_unique($allIds), 150) as $chunk) {
            $resp = mfl_cached_get('players', 3600, ['PLAYERS' => implode(',', $chunk)], false);
            foreach (mfl_normalize_list($resp['players']['player'] ?? null) as $p) { $players[$p['id']] = $p; }
        }
    }
}

include __DIR__ . '/../templates/header.php';
?>

<div class="home-grid">
  <main class="home-main" style="width:100%;">
    <?php if ($fetchError): ?>
      <div class="card"><p>Trade bait isn't available right now — check back soon.</p></div>
    <?php else: ?>
      <?php if ($isLoggedIn): ?>
      <div class="card">
        <h2 class="card-title">Set Your Trade Bait</h2>
        <?php if ($submitResult && $submitResult['ok']): ?>
          <p class="rotc-login-success">Trade bait updated.</p>
        <?php elseif ($submitResult && !$submitResult['ok']): ?>
          <p class="rotc-login-error"><?= htmlspecialchars($submitResult['error']) ?></p>
        <?php endif; ?>
        <?php if (!$myRoster): ?>
          <p>No roster found.</p>
        <?php else: ?>
          <form method="post" class="rotc-lineup-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
            <div class="rotc-trade-bait-players">
              <?php foreach ($myRoster as $p): $pd = $players[$p['id']] ?? []; ?>
                <label class="rotc-trade-player"><input type="checkbox" name="give_up[]" value="<?= htmlspecialchars($p['id']) ?>"> <?= htmlspecialchars($pd['name'] ?? ('Player #' . $p['id'])) ?> <span class="rotc-login-blurb" style="display:inline;">(<?= htmlspecialchars($pd['position'] ?? '') ?> <?= htmlspecialchars($pd['team'] ?? '') ?>)</span></label>
              <?php endforeach; ?>
            </div>
            <label for="rotc-tb-wants">Looking for (optional)</label>
            <input type="text" id="rotc-tb-wants" name="wants" maxlength="256">
            <button type="submit" class="rotc-btn">Update Trade Bait</button>
          </form>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="card">
        <p class="rotc-login-blurb"><a href="<?= htmlspecialchars($pageBase) ?>/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '') ?>">Log in</a> to set your own trade bait.</p>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2 class="card-title">Trade Bait</h2>
        <?php if (!$rows): ?>
          <p>No one has put up trade bait yet.</p>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
            <?php foreach ($rows as $r):
              $fname = $franchises[$r['franchise_id'] ?? '']['name'] ?? ($r['franchise_id'] ?? '');
              $names = [];
              foreach (explode(',', $r['willGiveUp'] ?? '') as $id) {
                if ($id === '') continue;
                $names[] = $players[$id]['name'] ?? ('Player #' . $id);
              }
            ?>
              <div style="border:1px solid var(--line);border-radius:var(--radius);padding:12px;">
                <h3 style="margin:0 0 8px;font-family:'Roboto Condensed',sans-serif;text-transform:uppercase;"><?= htmlspecialchars($fname) ?></h3>
                <p style="margin:0 0 6px;font-size:13px;color:var(--muted);">Will give up:</p>
                <ul style="margin:0 0 8px;padding-left:18px;">
                  <?php foreach ($names as $n): ?><li><?= htmlspecialchars($n) ?></li><?php endforeach; ?>
                </ul>
                <?php if (!empty($r['inExchangeFor'])): ?>
                  <p style="margin:0;font-size:13px;color:var(--muted);">Looking for: <?= htmlspecialchars($r['inExchangeFor']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
