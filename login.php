<?php
/**
 * login.php
 * Owner login for the Franchise action pages (submit lineup, drop a
 * player, offer a trade, trade bait, pool picks, survivor picks). Logs
 * the owner into MFL itself (see includes/mfl-auth.php for the real,
 * documented MFL login API this uses) -- there is no separate "site"
 * account, your MFL username/password IS the login.
 */

$page_title = 'Log In — Return of the Champions XXVI';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);
$loginError = null;
$redirectTo = $_POST['redirect'] ?? $_GET['redirect'] ?? '';

if ($hasConfig) {
    require_once $configPath;
    require_once __DIR__ . '/includes/mfl-api.php';
    require_once __DIR__ . '/includes/mfl-auth.php';
    rotc_session_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rotc_csrf_check($_POST['csrf'] ?? null)) {
            $loginError = 'Your session expired -- please try again.';
        } else {
            $result = rotc_mfl_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($result['ok']) {
                // Only ever redirect to a local, site-relative path
                // (starts with '/') -- never follow an externally
                // supplied absolute URL from the redirect param.
                $siteRootFs = rtrim(str_replace('\\', '/', __DIR__), '/');
                $docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
                $loginBase = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0)
                    ? substr($siteRootFs, strlen($docRoot))
                    : '';
                if ($loginBase === '.') $loginBase = '';
                $dest = (is_string($redirectTo) && $redirectTo !== '' && $redirectTo[0] === '/')
                    ? $redirectTo
                    : ($loginBase . '/franchise/');
                header('Location: ' . $dest);
                exit;
            }
            $loginError = $result['error'];
        }
    }
}

include __DIR__ . '/templates/header.php';
?>
<div class="home-grid">
  <main class="home-main rotc-login-main">
    <div class="card">
      <h2 class="card-title">Owner Login</h2>
      <?php if (!$hasConfig): ?>
        <p>Login isn't available right now — check back soon.</p>
      <?php else: ?>
        <p class="rotc-login-blurb">
          Log in with your MyFantasyLeague username and password to submit your lineup, offer trades, drop players, and make pool/survivor picks right here. Your credentials go straight to MyFantasyLeague over a secure connection and are never stored on this site — only your login session is kept, until you log out.
        </p>
        <?php if ($loginError): ?>
          <p class="rotc-login-error"><?= htmlspecialchars($loginError) ?></p>
        <?php endif; ?>
        <form method="post" class="rotc-login-form">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
          <input type="hidden" name="redirect" value="<?= htmlspecialchars(is_string($redirectTo) ? $redirectTo : '') ?>">
          <label for="rotc-login-user">MFL Username</label>
          <input type="text" id="rotc-login-user" name="username" autocomplete="username" required>
          <label for="rotc-login-pass">MFL Password</label>
          <input type="password" id="rotc-login-pass" name="password" autocomplete="current-password" required>
          <button type="submit" class="rotc-btn">Log In</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>
<?php include __DIR__ . '/templates/footer.php'; ?>
