<?php
// ---------------------------
// Minimal “Creator Connect” one-file app
// ---------------------------

// 1) Storage path (CSV)
$CSV_PATH = __DIR__ . '/logs/signups.csv';

// 2) Simple rate limit (per IP per 20s)
session_start();
$now = time();
if (!isset($_SESSION['last_submit'])) $_SESSION['last_submit'] = 0;

// 3) CSRF token
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// 4) Handle POST
$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF check
  if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $error = 'Invalid session. Please refresh and try again.';
  }
  // Rate limit check
  elseif ($now - (int)$_SESSION['last_submit'] < 20) {
    $error = 'Please wait a moment before submitting again.';
  }
  // Honeypot (should stay empty)
  elseif (!empty($_POST['website'])) {
    $error = 'Bot detected.';
  } else {
    // Sanitize
    $name   = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $handle = trim(filter_input(INPUT_POST, 'handle', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $email  = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $stream = trim(filter_input(INPUT_POST, 'stream', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $w_guest = isset($_POST['w_guest']) ? 'yes' : 'no';
    $w_collab = isset($_POST['w_collab']) ? 'yes' : 'no';

    // Required
    if ($name === '' || $handle === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Please add your name, handle, and a valid email.';
    } else {
      // Ensure CSV exists with header
      if (!file_exists($CSV_PATH)) {
        file_put_contents($CSV_PATH, "timestamp,name,handle,email,stream,guest,collab,ip\n", FILE_APPEND | LOCK_EX);
      }
      // Append row
      $row = [
        date('c'),
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], $name),
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], $handle),
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], $email),
        str_replace(['"', "\n", "\r"], ['""', ' ', ' '], $stream),
        $w_guest,
        $w_collab,
        $_SERVER['REMOTE_ADDR'] ?? ''
      ];
      $csvLine = '"' . implode('","', $row) . '"' . "\n";
      $ok = file_put_contents($CSV_PATH, $csvLine, FILE_APPEND | LOCK_EX);

      if ($ok === false) {
        $error = 'Could not save. Please try again.';
      } else {
        $_SESSION['last_submit'] = $now;
        $success = true;

        // Optional: send yourself a lightweight notification (uncomment if your server is mail-ready)
        /*
        @mail(
          'you@example.com',
          'New Creator Connect submission',
          "Name: $name\nHandle: $handle\nEmail: $email\nStream: $stream\nGuest: $w_guest\nCollab: $w_collab\n"
        );
        */
      }
    }
  }
}

// Simple helper to keep input after error
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actual Education — Creator Connect</title>
<meta name="color-scheme" content="dark light">
<style>
  :root{
    --bg:#0b1020; --card:#121933; --text:#e9eefc; --muted:#a4b1d1; --accent:#4c79ff; --ok:#24d17e; --err:#ff6b6b;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:var(--bg); color:var(--text);}
  .wrap{max-width:560px; margin:0 auto; padding:24px 16px 56px;}
  .hero{display:flex; align-items:center; gap:12px; padding:20px 0;}
  .logo{width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg,#4c79ff, #8ea2ff);}
  h1{margin:0; font-size:1.35rem;}
  p.lead{color:var(--muted); margin:.4rem 0 1rem; font-size:.98rem;}

  .card{background:var(--card); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:16px; box-shadow:0 6px 22px rgba(0,0,0,.25);}
  label{display:block; font-size:.9rem; color:var(--muted); margin:8px 4px 6px;}
  input[type=text], input[type=email]{
    width:100%; padding:12px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.08);
    background:#0f1530; color:var(--text); outline:none;
  }
  .row{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
  .checks{display:flex; gap:12px; flex-wrap:wrap; margin-top:10px;}
  .checks label{display:flex; align-items:center; gap:8px; margin:0; color:var(--text);}
  .submit{margin-top:14px; width:100%; padding:12px 14px; border:0; border-radius:12px; background:var(--accent); color:white; font-weight:600; font-size:1rem; cursor:pointer;}
  .submit:active{transform:translateY(1px)}
  .note{color:var(--muted); font-size:.85rem; margin-top:10px}

  .buttons{display:flex; gap:10px; flex-wrap:wrap; margin-top:14px}
  .btn{
    display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:12px; text-decoration:none; color:white; font-weight:600;
    border:1px solid rgba(255,255,255,.08); background:#20284d;
  }
  .btn svg{width:18px; height:18px}
  .ok{background:#123d2a; border-color:#1f6f47; color:#c9ffe6; padding:10px 12px; border-radius:12px; margin-top:12px}
  .err{background:#3a1515; border-color:#6b1f1f; color:#ffd3d3; padding:10px 12px; border-radius:12px; margin-top:12px}
  .foot{margin-top:18px; color:var(--muted); font-size:.8rem; text-align:center}
  /* hide honeypot */
  .hp{position:absolute; left:-5000px; width:1px; height:1px; overflow:hidden}
</style>
</head>
<body>
  <div class="wrap">
    <div class="hero">
      <div class="logo" aria-hidden="true"></div>
      <div>
        <h1>Actual Education — Creator Connect</h1>
        <p class="lead">Great meeting you at TwitchCon 👾 — drop your info to collab or be a podcast guest.</p>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="card ok">Thanks! I’ll follow up by email with a quick scheduling link. — Dr. Gold</div>
      <div class="buttons" style="margin-top:16px">
        <a class="btn" href="https://twitch.tv/ActualEducation" target="_blank" rel="noopener">
          <!-- twitch icon -->
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 3h17v11.5l-4 4h-3.5L11 21H8.5v-2.5H4V3zm3.5 2v9h3V8h2v6h3V5h-8z"/></svg>
          Follow on Twitch
        </a>
        <a class="btn" href="https://youtube.com/@ActualEducation" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.5 6.2s-.2-1.7-.8-2.4c-.8-.8-1.7-.8-2.1-.9C17.6 2.5 12 2.5 12 2.5h0s-5.6 0-8.6.4c-.4 0-1.3.1-2.1.9-.6.7-.8 2.4-.8 2.4S0 8.2 0 10.2v1.6c0 2 .2 4 0 6 0 0 .2 1.7.8 2.4.8.8 1.8.8 2.3.9 1.7.2 7 .4 8.9.4 0 0 5.6 0 8.6-.4.4 0 1.3-.1 2.1-.9.6-.7.8-2.4.8-2.4s.4-2 .4-4v-1.6c0-2-.4-4-.4-4zM9.6 14.9V7.9l6.2 3.5-6.2 3.5z"/></svg>
          Subscribe on YouTube
        </a>
        <a class="btn" href="https://tiktok.com/@ActualEducation" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.7 2h2.1a6.9 6.9 0 0 0 .3 1.7c.5 1.5 1.5 2.8 3 3.6v2.4a9.3 9.3 0 0 1-5.4-1.8v7.8a6.4 6.4 0 1 1-5.3-6.4v2.6a3.8 3.8 0 1 0 2.7 3.6V2h1.9z"/></svg>
          TikTok
        </a>
      </div>
      <div class="foot">Want to chat now? actualdiscord.com</div>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="card err"><?=h($error)?></div>
      <?php endif; ?>

      <form class="card" method="post" action="">
        <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
        <!-- Honeypot -->
        <input class="hp" type="text" name="website" autocomplete="off" tabindex="-1">
        <div class="row">
          <div>
            <label>Name</label>
            <input type="text" name="name" required value="<?=h($_POST['name'] ?? '')?>">
          </div>
          <div>
            <label>Twitch / Handle</label>
            <input type="text" name="handle" required placeholder="@YourHandle" value="<?=h($_POST['handle'] ?? '')?>">
          </div>
        </div>
        <label>Email</label>
        <input type="email" name="email" required placeholder="name@example.com" value="<?=h($_POST['email'] ?? '')?>">
        <label>What do you stream?</label>
        <input type="text" name="stream" placeholder="e.g., Just Chatting, Science, IRL" value="<?=h($_POST['stream'] ?? '')?>">

        <div class="checks">
          <label><input type="checkbox" name="w_guest" <?=(isset($_POST['w_guest'])?'checked':'')?>> Podcast guest</label>
          <label><input type="checkbox" name="w_collab" <?=(isset($_POST['w_collab'])?'checked':'')?>> Collaboration stream</label>
        </div>

        <button class="submit" type="submit">Send &amp; Connect</button>
        <div class="note">Takes ~20 seconds. I’ll email a scheduling link after TwitchCon. — Dr. Gold</div>
      </form>

      <div class="buttons">
        <a class="btn" href="https://twitch.tv/ActualEducation" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 3h17v11.5l-4 4h-3.5L11 21H8.5v-2.5H4V3zm3.5 2v9h3V8h2v6h3V5h-8z"/></svg>
          Follow on Twitch
        </a>
        <a class="btn" href="https://youtube.com/@ActualEducation" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M23.5 6.2s-.2-1.7-.8-2.4c-.8-.8-1.7-.8-2.1-.9C17.6 2.5 12 2.5 12 2.5h0s-5.6 0-8.6.4c-.4 0-1.3.1-2.1.9-.6.7-.8 2.4-.8 2.4S0 8.2 0 10.2v1.6c0 2 .2 4 0 6 0 0 .2 1.7.8 2.4.8.8 1.8.8 2.3.9 1.7.2 7 .4 8.9.4 0 0 5.6 0 8.6-.4.4 0 1.3-.1 2.1-.9.6-.7.8-2.4.8-2.4s.4-2 .4-4v-1.6c0-2-.4-4-.4-4zM9.6 14.9V7.9l6.2 3.5-6.2 3.5z"/></svg>
          Subscribe on YouTube
        </a>
        <a class="btn" href="https://tiktok.com/@ActualEducation" target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.7 2h2.1a6.9 6.9 0 0 0 .3 1.7c.5 1.5 1.5 2.8 3 3.6v2.4a9.3 9.3 0 0 1-5.4-1.8v7.8a6.4 6.4 0 1 1-5.3-6.4v2.6a3.8 3.8 0 1 0 2.7 3.6V2h1.9z"/></svg>
          TikTok
        </a>
      </div>
      <div class="foot">Prefer Discord? Join at actualdiscord.com</div>
    <?php endif; ?>
  </div>
</body>
</html>

