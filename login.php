<?php
/**
 * login.php
 * Owner login for the Franchise action pages (submit lineup, drop a
 * player, offer a trade, trade bait, pool picks, survivor picks) and the
 * /mobile dashboard. Logs the owner into MFL itself (see
 * includes/mfl-auth.php for the real, documented MFL login API this
 * uses) -- there is no separate "site" account, your MFL username/
 * password IS the login.
 *
 * Rendered as a self-contained, app-style full-screen screen (no desktop
 * header/footer chrome) so it reads the same landing from a phone (where
 * /mobile redirects here) as it does on desktop. All auth logic below is
 * unchanged from the original chrome-wrapped version.
 */

$page_title = 'Log In — Return of the Champions';
$current_tab = '';

$configPath = getenv('ROTC_CONFIG_PATH') ?: (dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php');
$hasConfig = file_exists($configPath);
$loginError = null;
$redirectTo = $_POST['redirect'] ?? $_GET['redirect'] ?? '';

// Site-root base path. This file lives at the repo root, so the site root
// on disk is __DIR__. Used both for the post-login redirect fallback and
// for the asset URLs in the self-contained screen below.
$siteRootFs = rtrim(str_replace('\\', '/', __DIR__), '/');
$docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base = ($docRoot !== '' && strpos($siteRootFs, $docRoot) === 0) ? substr($siteRootFs, strlen($docRoot)) : '';
if ($base === '.') $base = '';

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
                $dest = (is_string($redirectTo) && $redirectTo !== '' && $redirectTo[0] === '/')
                    ? $redirectTo
                    : ($base . '/franchise/');
                header('Location: ' . $dest);
                exit;
            }
            $loginError = $result['error'];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2A1810">
<title><?= htmlspecialchars($page_title) ?></title>
<link rel="icon" type="image/png" href="<?= $base ?>/assets/img/rotc-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css?family=Roboto+Condensed:400,700|Roboto:400,400i,700" rel="stylesheet">
<style>
  :root{ --accent:#E0531B; --ink:#2A1810; --paper:#FDFBF7; }
  *{ box-sizing:border-box; }
  html,body{ margin:0; }
  .rotc-lgn-body{
    min-height:100vh; min-height:100dvh;
    font-family:"Roboto",system-ui,-apple-system,sans-serif; color:var(--paper);
    background:
      radial-gradient(900px 520px at 50% -10%, rgba(224,83,27,.30), rgba(224,83,27,0) 60%),
      linear-gradient(165deg, #3a2013 0%, #2A1810 44%, #150c05 100%);
    display:flex; align-items:center; justify-content:center;
    padding:24px 20px calc(24px + env(safe-area-inset-bottom));
    -webkit-font-smoothing:antialiased;
  }
  .rotc-lgn-card{
    width:100%; max-width:380px;
    background:rgba(253,251,247,.055);
    border:1px solid rgba(253,251,247,.14); border-radius:22px;
    padding:32px 24px 26px;
    box-shadow:0 26px 64px -22px rgba(0,0,0,.65), inset 0 1px 0 rgba(253,251,247,.08);
    -webkit-backdrop-filter:blur(16px) saturate(1.1); backdrop-filter:blur(16px) saturate(1.1);
  }
  .rotc-lgn-logo{ width:56px; height:56px; display:block; margin:0 auto 14px; filter:drop-shadow(0 6px 14px rgba(0,0,0,.45)); }
  .rotc-lgn-title{
    font-family:"Roboto Condensed",sans-serif; text-transform:uppercase; letter-spacing:.06em;
    text-align:center; font-size:18px; font-weight:700; margin:0; line-height:1.2;
  }
  .rotc-lgn-sub{ text-align:center; color:rgba(253,251,247,.6); font-size:12px; margin:5px 0 24px; letter-spacing:.05em; text-transform:uppercase; }
  .rotc-lgn-error{
    display:flex; gap:8px; align-items:flex-start;
    background:rgba(224,83,27,.15); border:1px solid rgba(224,83,27,.42); color:#ffd9c9;
    font-size:12.5px; line-height:1.4; padding:10px 12px; border-radius:10px; margin-bottom:16px;
  }
  .rotc-lgn-error span:first-child{ flex:none; }
  .rotc-lgn-field{ position:relative; margin-bottom:14px; }
  .rotc-lgn-field input{
    width:100%; padding:22px 14px 8px;
    background:rgba(0,0,0,.24); border:1px solid rgba(253,251,247,.16); border-radius:12px;
    color:var(--paper); font-size:15px; font-family:inherit; outline:none;
    transition:border-color .15s, background .15s, box-shadow .15s;
  }
  .rotc-lgn-field input:focus{ border-color:var(--accent); background:rgba(0,0,0,.34); box-shadow:0 0 0 3px rgba(224,83,27,.22); }
  .rotc-lgn-field label{
    position:absolute; left:14px; top:15px; color:rgba(253,251,247,.55); font-size:14px;
    pointer-events:none; transform-origin:left top; transition:transform .15s, color .15s;
  }
  .rotc-lgn-field input:focus + label,
  .rotc-lgn-field input:not(:placeholder-shown) + label{
    transform:translateY(-9px) scale(.72); color:var(--accent); font-weight:700; letter-spacing:.04em;
  }
  .rotc-lgn-eye{
    position:absolute; right:6px; top:50%; transform:translateY(-50%);
    background:none; border:0; color:rgba(253,251,247,.55); cursor:pointer; padding:9px; font-size:16px; line-height:1;
  }
  .rotc-lgn-eye:hover{ color:var(--paper); }
  .rotc-lgn-btn{
    width:100%; margin-top:6px; padding:15px; border:0; border-radius:12px; cursor:pointer;
    font-family:"Roboto Condensed",sans-serif; text-transform:uppercase; letter-spacing:.08em; font-weight:700; font-size:15px;
    color:#fff; background:linear-gradient(135deg,#F0692F,#E0531B 58%,#C0410F);
    box-shadow:0 10px 26px -8px rgba(224,83,27,.7);
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:transform .08s, box-shadow .15s, filter .15s;
  }
  .rotc-lgn-btn:hover{ filter:brightness(1.06); }
  .rotc-lgn-btn:active{ transform:translateY(1px); box-shadow:0 6px 16px -8px rgba(224,83,27,.7); }
  .rotc-lgn-note{ text-align:center; color:rgba(253,251,247,.42); font-size:10.5px; line-height:1.55; margin:16px 4px 0; }
  .rotc-lgn-back{
    display:block; text-align:center; margin-top:18px; color:rgba(253,251,247,.55);
    font-size:11px; text-decoration:none; letter-spacing:.05em; text-transform:uppercase; font-family:"Roboto Condensed",sans-serif;
  }
  .rotc-lgn-back:hover{ color:var(--paper); }
  @media (prefers-reduced-motion:reduce){ .rotc-lgn-field input, .rotc-lgn-field label, .rotc-lgn-btn{ transition:none; } }
</style>
</head>
<body class="rotc-lgn-body">
  <main class="rotc-lgn-card">
    <img class="rotc-lgn-logo" src="<?= $base ?>/assets/img/rotc-icon.png" alt="">
    <h1 class="rotc-lgn-title">Return of the Champions</h1>
    <p class="rotc-lgn-sub">Owner Login</p>

    <?php if (!$hasConfig): ?>
      <p class="rotc-lgn-note" style="font-size:13px;color:rgba(253,251,247,.72)">Login isn’t available right now — check back soon.</p>
    <?php else: ?>
      <?php if ($loginError): ?>
        <div class="rotc-lgn-error"><span aria-hidden="true">⚠</span><span><?= htmlspecialchars($loginError) ?></span></div>
      <?php endif; ?>
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(rotc_csrf_token()) ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars(is_string($redirectTo) ? $redirectTo : '') ?>">

        <div class="rotc-lgn-field">
          <input type="text" id="rotc-login-user" name="username" placeholder=" " autocomplete="username" autocapitalize="none" spellcheck="false" required>
          <label for="rotc-login-user">MFL Username</label>
        </div>

        <div class="rotc-lgn-field">
          <input type="password" id="rotc-login-pass" name="password" placeholder=" " autocomplete="current-password" required>
          <label for="rotc-login-pass">MFL Password</label>
          <button type="button" class="rotc-lgn-eye" id="rotc-login-eye" hidden aria-label="Show password">👁</button>
        </div>

        <button type="submit" class="rotc-lgn-btn">Log In <span aria-hidden="true">→</span></button>
      </form>
      <p class="rotc-lgn-note">Your MyFantasyLeague credentials go straight to MFL over a secure connection and are never stored on this site — only your login session is kept, until you log out.</p>
    <?php endif; ?>

    <a class="rotc-lgn-back" href="<?= $base ?: '/' ?>">← Full Site</a>
  </main>

  <script>
    // Progressive enhancement only: reveal-password toggle. With JS off
    // the button stays hidden (never a dead control) and login works fine.
    (function () {
      var eye = document.getElementById('rotc-login-eye'),
          pass = document.getElementById('rotc-login-pass');
      if (!eye || !pass) return;
      eye.hidden = false;
      eye.addEventListener('click', function () {
        var show = pass.type === 'password';
        pass.type = show ? 'text' : 'password';
        eye.textContent = show ? '🙈' : '👁';
        eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    })();
  </script>
</body>
</html>
