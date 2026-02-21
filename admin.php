<?php
// ===================================================================
// admin.php – admin console (PHP 5.6 compatible) with live updates
// Features:
//   • Password box login (session-based)
//   • Auto-refresh UI every 3s by polling logs/submissions.json
//   • "Mark Done" -> remove oldest submission + delete its image
//   • "Clear All" -> wipe submissions.json and delete all images
//   • "Delete #N" -> delete an arbitrary queue position (oldest = #1)
// ===================================================================

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Load admin key from environment (preferred), with a clear fail-closed behavior
$__env_key = getenv('CONNECT_QUEUE_ADMIN_KEY');
if (!$__env_key) { $__env_key = getenv('CONNECT_ADMIN_SECRET'); }

if (!$__env_key) {
  header('HTTP/1.1 500 Internal Server Error');
  echo 'Admin misconfigured: CONNECT_QUEUE_ADMIN_KEY (or CONNECT_ADMIN_SECRET) not set.';
  exit;
}
define('ADMIN_KEY', $__env_key);
unset($__env_key);

define('UPLOAD_DIR', __DIR__ . '/uploadedImages');
define('SUBMISSIONS_FILE', __DIR__ . '/logs/submissions.json');
define('SPIN_FILE', __DIR__ . '/logs/spin.json');
define('ADMIN_LOG', __DIR__ . '/logs/admin_error.log');
define('BUILD_VERSION', '2026-02-17.01');
define('YOUTUBE_API_KEY', getenv('YOUTUBE_API_KEY') ? getenv('YOUTUBE_API_KEY') : '');
define('YOUTUBE_CHANNEL_ID', getenv('YOUTUBE_CHANNEL_ID') ? getenv('YOUTUBE_CHANNEL_ID') : '');

@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('error_log', __DIR__ . '/logs/php_error.log');

// === Discord forum webhook config ===
require_once __DIR__ . '/forumWebhook.php';

$DISCORD_FORUM_WEBHOOK = "https://discord.com/api/webhooks/1431381616294363178/8ZIhEro-OHDSj4MEZ8TsGCdzX2Q5FlRDSLPok0PmeaFaWPfSZR3NFrjMWZ4i3zIw6JQE";

define('OFFICE_HOURS_TAG_ID', '1427946399839813632');
if (!defined('UNSOLVED_TAG_ID')) { define('UNSOLVED_TAG_ID', '1431154094713868368'); }

// PHP 5.x polyfill for timing-safe compare
if (!function_exists('hash_equals')) {
  function hash_equals($a, $b) {
    if (!is_string($a) || !is_string($b)) return false;
    $len = strlen($a);
    if ($len !== strlen($b)) return false;
    $res = 0;
    for ($i = 0; $i < $len; $i++) { $res |= ord($a[$i]) ^ ord($b[$i]); }
    return $res === 0;
  }
}

alog("=== ADMIN LOAD ===");
alog("YT env check: keyLen=" . strlen(getenv('YOUTUBE_API_KEY')) .
     " channelId=" . getenv('YOUTUBE_CHANNEL_ID'));

// === YouTube live timestamp lookup ===

function http_get_json($url, $timeoutSeconds) {
  if (!function_exists('curl_init')) return null;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeoutSeconds);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$timeoutSeconds);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err || $http < 200 || $http >= 300 || !is_string($resp) || $resp === '') return null;

  $data = @json_decode($resp, true);
  return is_array($data) ? $data : null;
}

// Returns "" if not live / cannot resolve. Otherwise returns full URL with &t=123s
function youtube_live_timestamp_url($apiKey, $channelId) {
  alog("YT fn start: channel=" . $channelId);

  if (!is_string($apiKey) || $apiKey === '') return '';
  if (!is_string($channelId) || $channelId === '') return '';

  // 1) Find the channel's currently live video (if any)
  $searchUrl = 'https://www.googleapis.com/youtube/v3/search'
    . '?part=snippet'
    . '&channelId=' . rawurlencode($channelId)
    . '&eventType=live'
    . '&type=video'
    . '&maxResults=1'
    . '&key=' . rawurlencode($apiKey);

  $search = http_get_json($searchUrl, 6);
  alog("YT search response: " . json_encode($search));

  if (!$search) {
    alog("YT FAIL: search request returned null");
    return '';
  }
  if (empty($search['items'][0]['id']['videoId'])) {
    alog("YT FAIL: no live video found in search");
    return '';
  }

  $videoId = $search['items'][0]['id']['videoId'];
  alog("YT live videoId=" . $videoId);
  if (!is_string($videoId) || $videoId === '') return '';

  // 2) Get actual start time so we can compute "seconds since live start"
  $vidUrl = 'https://www.googleapis.com/youtube/v3/videos'
    . '?part=liveStreamingDetails'
    . '&id=' . rawurlencode($videoId)
    . '&key=' . rawurlencode($apiKey);

  $vid = http_get_json($vidUrl, 6);
  alog("YT video details: " . json_encode($vid));

  $startIso = '';
  if ($vid && !empty($vid['items'][0]['liveStreamingDetails']['actualStartTime'])) {
    $startIso = $vid['items'][0]['liveStreamingDetails']['actualStartTime'];
  }
  if (!is_string($startIso) || $startIso === '') return '';

  $startTs = @strtotime($startIso);
  if (!$startTs) return '';

  $now = time();
  $seconds = $now - (int)$startTs - 60;
  if ($seconds < 0) $seconds = 0;
  alog("YT SUCCESS: start=" . $startIso . " seconds=" . $seconds);

  // YouTube supports &t=###s
  return 'https://www.youtube.com/watch?v=' . rawurlencode($videoId) . '&t=' . (int)$seconds . 's';
}

function _pick($arr, $keys, $fallback) {
  foreach ($keys as $k) if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
  return $fallback;
}

function alog($msg, $ctx=array()){
  @file_put_contents(ADMIN_LOG, '['.date('c')."] $msg ".(empty($ctx)?'':json_encode($ctx)).PHP_EOL, FILE_APPEND);
}

function read_submissions() {
  if (!is_file(SUBMISSIONS_FILE)) return array();
  $json = @file_get_contents(SUBMISSIONS_FILE);
  $arr  = json_decode($json, true);
  return is_array($arr) ? $arr : array();
}

function write_submissions($arr) {
  $result = @file_put_contents(SUBMISSIONS_FILE, json_encode(array_values($arr), JSON_PRETTY_PRINT));
  if ($result === false) {
    alog('write_submissions FAILED', array(
      'file' => SUBMISSIONS_FILE,
      'writable' => is_writable(SUBMISSIONS_FILE),
      'dir_writable' => is_writable(dirname(SUBMISSIONS_FILE))
    ));
  }
  return $result !== false;
}

function write_spin_event($payload) {
  $result = @file_put_contents(SPIN_FILE, json_encode($payload, JSON_PRETTY_PRINT));
  if ($result === false) {
    alog('write_spin_event FAILED', array(
      'file' => SPIN_FILE,
      'dir_writable' => is_writable(dirname(SPIN_FILE))
    ));
  }
  return $result !== false;
}

function compute_votes_for_entry($entry, $now) {
  $isWinner = !empty($entry['winner']);
  if ($isWinner) return 0;
  $ts = isset($entry['ts']) ? $entry['ts'] : 0;
  $age_min = max(0, ($now - (int)$ts) / 60);
  $baseVotes = 10 + (int)floor($age_min * 2);
  $upvotes = isset($entry['upvotes']) ? (int)$entry['upvotes'] : 0;
  if ($upvotes < 1) return $baseVotes;
  return (int)floor($baseVotes * (1 + log($upvotes + 1)));
}

function lottery_pick($arr) {
  $now = time();
  $votes = array();
  $totalVotes = 0;
  for ($i = 0; $i < count($arr); $i++) {
    $v = compute_votes_for_entry($arr[$i], $now);
    $votes[$i] = $v;
    $totalVotes += $v;
  }
  if ($totalVotes <= 0) {
    return array('index' => 0, 'votes' => 0, 'total' => 0, 'all_votes' => $votes);
  }
  $draw = mt_rand(1, $totalVotes);
  $running = 0;
  for ($i = 0; $i < count($votes); $i++) {
    $running += $votes[$i];
    if ($draw <= $running) {
      return array('index' => $i, 'votes' => $votes[$i], 'total' => $totalVotes, 'all_votes' => $votes);
    }
  }
  return array('index' => 0, 'votes' => isset($votes[0]) ? $votes[0] : 0, 'total' => $totalVotes, 'all_votes' => $votes);
}

function queue_has_winner($arr) {
  for ($i = 0; $i < count($arr); $i++) {
    if (!empty($arr[$i]['winner'])) return true;
  }
  return false;
}

function safe_unlink($path) {
  // Only delete files inside UPLOAD_DIR
  $real = @realpath($path);
  $root = @realpath(UPLOAD_DIR);
  if ($real && $root && strpos($real, $root) === 0 && is_file($real)) {
    if (!@unlink($real)) {
      alog('safe_unlink FAILED', array('path'=>$real, 'writable'=>is_writable($real)));
      return false;
    }
    return true;
  }
  alog('safe_unlink skipped (path outside UPLOAD_DIR or missing)', array('path'=>$path, 'real'=>$real, 'root'=>$root));
  return false;
}

// Extract username from a submission entry (checks multiple key aliases)
function entry_username($entry) {
  if (!empty($entry['username'])) return $entry['username'];
  if (!empty($entry['user']))     return $entry['user'];
  if (!empty($entry['author']))   return $entry['author'];
  return 'Anonymous';
}

// Resolve absolute image path from a submission entry
function entry_abs_image($entry) {
  if (!empty($entry['path'])) {
    $p = $entry['path'];
    if ($p[0] === '/' || preg_match('~^[A-Za-z]:\\\\~', $p)) {
      return $p;
    }
    return __DIR__ . '/' . ltrim($p, '/\\');
  }
  if (!empty($entry['file'])) {
    return UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($entry['file']);
  }
  if (!empty($entry['filename'])) {
    return UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($entry['filename']);
  }
  if (!empty($entry['image'])) {
    return UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($entry['image']);
  }
  if (!empty($entry['name'])) {
    return UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($entry['name']);
  }
  return '';
}

function entry_image_name($entry) {
  if (!empty($entry['path'])) return basename($entry['path']);
  if (!empty($entry['file'])) return basename($entry['file']);
  if (!empty($entry['filename'])) return basename($entry['filename']);
  if (!empty($entry['image'])) return basename($entry['image']);
  if (!empty($entry['name']) && preg_match('/\.[a-z0-9]{2,5}$/i', $entry['name'])) return basename($entry['name']);
  return '';
}

function entry_thumb_url($entry) {
  $filename = entry_image_name($entry);
  if ($filename === '') return '';
  return 'uploadedImages/' . rawurlencode($filename);
}

// Build Discord content with optional YouTube timestamp
function build_discord_content() {
  $content = "Forwarded from the submissions queue.";
  $yt = youtube_live_timestamp_url(YOUTUBE_API_KEY, YOUTUBE_CHANNEL_ID);
  if (is_string($yt) && $yt !== '') {
    $content .= "\nYouTube (timestamped): " . $yt;
  }
  return $content;
}

// Delete the image file associated with a submission entry
function delete_entry_image($entry) {
  if (isset($entry['path']) && $entry['path'] !== '') {
    safe_unlink($entry['path']);
  } elseif (isset($entry['name']) && $entry['name'] !== '') {
    $fallback = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($entry['name']);
    if (is_file($fallback)) @unlink($fallback);
  }
}

function render_login($error_msg) {
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      :root { --bg:#0b1020; --card:#121933; --text:#e9eefc; --muted:#a4b1d1; --accent:#4c79ff; }
      html,body{height:100%}
      body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;display:flex;align-items:center;justify-content:center}
      .card{background:var(--card);border-radius:14px;padding:22px 24px;box-shadow:0 12px 28px rgba(0,0,0,.35);min-width:320px}
      h1{margin:0 0 10px 0;font-size:22px}
      label{display:block;margin:12px 0 6px 0}
      input[type=password]{width:100%;padding:10px;border-radius:8px;border:1px solid #394069;background:#0b1020;color:var(--text)}
      button{margin-top:12px;border:none;background:var(--accent);color:#fff;padding:10px 14px;border-radius:10px;cursor:pointer}
      .err{margin-top:10px;color:#ff8a8a;font-size:14px}
      .build-version{margin-top:10px;text-align:right;color:rgba(164,177,209,.7);font-size:11px;letter-spacing:.2px}
    </style>
  </head>
  <body>
    <div class="card">
      <h1>Admin Console</h1>
      <form method="post">
        <label for="key">Admin Key</label>
        <input type="password" id="key" name="key" autofocus>
        <button type="submit" name="action" value="login">Enter</button>
        <?php if ($error_msg): ?><div class="err"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>
      </form>
      <div class="build-version">build <?php echo htmlspecialchars(BUILD_VERSION); ?></div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ---------- Login / Logout handling ----------
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($action === 'login') {
  $k = isset($_POST['key']) ? $_POST['key'] : '';
  if ($k && hash_equals(ADMIN_KEY, $k)) {
    $_SESSION['admin_ok'] = true;
  } else {
    render_login('Incorrect key.');
    exit;
  }

} elseif ($action === 'logout') {
  $_SESSION = array();
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
  }
  session_destroy();
  render_login('');
  exit;
}

if (empty($_SESSION['admin_ok'])) {
  render_login('');
  exit;
}


// ---------- Action handlers (requires login) ----------
$message = '';
$type = 'info';

if ($action === 'clear_all') {
  $errors = array();

  // Delete every file in uploadedImages/
  if (is_dir(UPLOAD_DIR)) {
    if (!is_writable(UPLOAD_DIR)) {
      $errors[] = 'uploadedImages/ is not writable';
    } else {
      $dh = opendir(UPLOAD_DIR);
      if ($dh) {
        while (($f = readdir($dh)) !== false) {
          if ($f === '.' || $f === '..') continue;
          $full = UPLOAD_DIR . DIRECTORY_SEPARATOR . $f;
          if (is_file($full) && !@unlink($full)) {
            $errors[] = 'Could not delete ' . $f;
          }
        }
        closedir($dh);
      }
    }
  }

  // Reset submissions file
  if (!write_submissions(array())) {
    $errors[] = 'Could not write submissions.json';
  }

  if (empty($errors)) {
    $message = 'All submissions and images cleared.';
    $type = 'success';
  } else {
    $message = 'Partial failure: ' . implode('; ', $errors);
    $type = 'warning';
  }
  alog('Admin clear_all', $errors);
}
elseif ($action === 'mark_done') {
  $arr = read_submissions();
  if (count($arr) === 0) {
    $message = 'No submissions to clear.';
    $type = 'warning';
  } else {
    // Forward oldest to Discord before removing
    $peek = $arr[0];
    $username = entry_username($peek);
    $absImage = entry_abs_image($peek);
    $content = build_discord_content();
    alog("Discord post content:\n" . $content);

    post_to_discord_forum(
      $DISCORD_FORUM_WEBHOOK, $username, $absImage, OFFICE_HOURS_TAG_ID, $content
    );

    // Remove oldest and delete its image
    $first = array_shift($arr);
    delete_entry_image($first);
    write_submissions($arr);
    $message = 'Oldest submission cleared' . (isset($first['username']) ? ' ('.$first['username'].')' : '') . '.';
    $type = 'success';
    alog('Admin mark_done', $first);
  }
}
elseif ($action === 'post_all') {
  $arr = read_submissions();
  $total = count($arr);

  if ($total === 0) {
    $message = 'No submissions to post.';
    $type = 'warning';
  } else {
    $tags = array();
    if (OFFICE_HOURS_TAG_ID !== '') $tags[] = OFFICE_HOURS_TAG_ID;
    $tags[] = UNSOLVED_TAG_ID;

    $okCount = 0; $failCount = 0;
    for ($i = 0; $i < $total; $i++) {
      $it = $arr[$i];
      $ok = post_to_discord_forum(
        $DISCORD_FORUM_WEBHOOK, entry_username($it), entry_abs_image($it), $tags
      );
      if ($ok) $okCount++; else $failCount++;
    }

    $message = "Posted {$okCount} of {$total} to Discord forum" . ($failCount ? " ({$failCount} failed)" : "") . ".";
    $type = ($failCount === 0) ? 'success' : 'warning';
    alog('Admin post_all', array('total'=>$total,'ok'=>$okCount,'fail'=>$failCount));
  }
}
elseif ($action === 'post_number') {
  $num = isset($_POST['n']) ? intval($_POST['n']) : 0;
  $arr = read_submissions();

  if ($num >= 1 && $num <= count($arr)) {
    $it = $arr[$num - 1];
    $content = build_discord_content();
    $ok = post_to_discord_forum(
      $DISCORD_FORUM_WEBHOOK, entry_username($it), entry_abs_image($it),
      OFFICE_HOURS_TAG_ID, $content
    );
    $message = $ok ? "Posted #$num to Discord forum." : "Tried to post #$num, but Discord call failed.";
    $type = $ok ? 'success' : 'warning';
    alog('Admin post_number', array('index'=>$num,'ok'=>$ok));
  } else {
    $message = 'Invalid queue number.';
    $type = 'warning';
  }
}
elseif ($action === 'delete_n') {
  $n = isset($_POST['n']) ? (int)$_POST['n'] : 0;
  $arr = read_submissions();
  $total = count($arr);

  if ($n < 1 || $n > $total) {
    $message = 'Invalid number. Enter a value between 1 and ' . $total . '.';
    $type = 'warning';
  } else {
    $idx = $n - 1;
    $entry = $arr[$idx];
    delete_entry_image($entry);
    array_splice($arr, $idx, 1);
    write_submissions($arr);
    $message = 'Deleted entry #' . $n . (isset($entry['username']) ? ' ('.$entry['username'].')' : '') . '.';
    $type = 'success';
    alog('Admin delete_n', array('n'=>$n, 'entry'=>$entry));
  }
}
elseif ($action === 'choose_winner') {
  $arr = read_submissions();
  if (count($arr) === 0) {
    $message = 'No submissions available for lottery.';
    $type = 'warning';
  } elseif (queue_has_winner($arr)) {
    $message = 'A winner is already selected. Remove them first before spinning again.';
    $type = 'warning';
  } else {
    $pick = lottery_pick($arr);
    $idx = isset($pick['index']) ? (int)$pick['index'] : -1;
    if ($idx < 0 || $idx >= count($arr)) {
      $message = 'Lottery failed to pick a valid winner.';
      $type = 'warning';
    } else {
      $before = $arr;
      for ($i = 0; $i < count($arr); $i++) {
        if (isset($arr[$i]['winner'])) unset($arr[$i]['winner']);
        if (isset($arr[$i]['winner_ts'])) unset($arr[$i]['winner_ts']);
      }

      $winnerTs = time();
      $arr[$idx]['winner'] = true;
      $arr[$idx]['winner_ts'] = $winnerTs;
      $winner = $arr[$idx];
      array_splice($arr, $idx, 1);
      array_unshift($arr, $winner);

      $spinEntries = array();
      $allVotes = isset($pick['all_votes']) && is_array($pick['all_votes']) ? $pick['all_votes'] : array();
      for ($i = 0; $i < count($before); $i++) {
        $spinEntries[] = array(
          'username' => entry_username($before[$i]),
          'votes' => isset($allVotes[$i]) ? (int)$allVotes[$i] : 0,
          'ts' => isset($before[$i]['ts']) ? (int)$before[$i]['ts'] : 0
        );
      }

      $winnerName = entry_username($winner);
      $spinPayload = array(
        'ts' => $winnerTs,
        'winner_username' => $winnerName,
        'winner_index' => $idx,
        'entries' => $spinEntries
      );

      $savedQueue = write_submissions($arr);
      $savedSpin = write_spin_event($spinPayload);

      if ($savedQueue && $savedSpin) {
        $chance = ($pick['total'] > 0) ? round(((float)$pick['votes'] * 100) / (float)$pick['total'], 1) : 0;
        $message = 'Spinning for winner... Drew ' . $winnerName . ' (had ' . (int)$pick['votes'] . ' votes, ' . $chance . '% chance)!';
        $type = 'success';
      } else {
        $message = 'Picked a winner, but saving failed. Check log permissions in logs/.';
        $type = 'warning';
      }

      alog('Admin choose_winner', array('winner' => $winnerName, 'pick' => $pick));
    }
  }
}

// Initial values for immediate render; JS will live-update afterward
$subs = read_submissions();
$count = count($subs);
$preview = array_slice($subs, 0, 20);
$has_winner = queue_has_winner($subs);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Console — Submissions</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root { --bg:#0b1020; --card:#121933; --text:#e9eefc; --muted:#a4b1d1; --accent:#4c79ff; --ok:#42c17b; --warn:#f5a623; }
  body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:var(--bg); color:var(--text); }
  .wrap { max-width: 900px; margin: 32px auto; background:var(--card); border-radius:16px; padding: 24px 28px; box-shadow:0 12px 28px rgba(0,0,0,.35); }
  h1 { margin:0 0 12px 0; font-size:24px; }
  .meta { color:var(--muted); margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
  .row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
  .btn { border:none; border-radius:10px; padding:10px 14px; font-weight:600; cursor:pointer; }
  .btn-ok { background:var(--ok); color:#07290f; }
  .btn-warn { background:#c43d3d; color:#fff; }
  .btn-accent { background:var(--accent); color:#fff; }
  .btn-ghost { background:transparent; color:#9fb3ff; border:1px solid #394069; }
  .btn[disabled] { opacity:.45; cursor:not-allowed; }
  .btn:hover { opacity:.92; }
  .msg { padding:10px 14px; border-radius:10px; margin-bottom:10px; display:inline-block; }
  .success { background:rgba(66,193,123,.15); color:var(--ok); }
  .warning { background:rgba(245,166,35,.15); color:var(--warn); }
  table { width:100%; border-collapse:collapse; margin-top:12px; }
  th, td { padding:8px 10px; text-align:left; border-bottom: 1px solid rgba(255,255,255,.07); }
  th { color:var(--muted); font-weight:600; }
  .small { color:var(--muted); font-size:12px; }
  input[type=number]{
    width:140px; padding:10px; border-radius:8px; border:1px solid #394069;
    background:#0b1020; color:var(--text);
  }
  .build-version{margin-top:10px;text-align:right;color:rgba(164,177,209,.7);font-size:11px;letter-spacing:.2px}
  .thumb-cell { width:64px; }
  .thumb {
    width:48px;
    height:48px;
    border-radius:8px;
    border:1px solid rgba(255,255,255,.14);
    object-fit:cover;
    background:#0b1020;
    display:block;
  }
</style>
</head>
<body>
  <div class="wrap">
    <h1>Admin Console</h1>
    <div class="meta">
      <div>
        Total submissions: <strong id="count"><?php echo (int)$count; ?></strong>
        <span class="small" id="updated" style="margin-left:8px;">—</span>
      </div>
      <form method="post">
        <button class="btn btn-ghost" name="action" value="logout">Log out</button>
      </form>
    </div>

    <?php if ($message): ?>
      <div class="msg <?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" class="row">
      <button name="action" value="mark_done" class="btn btn-ok">Mark Done (oldest)</button>
      <button name="action" value="clear_all" class="btn btn-warn" onclick="return confirm('Delete ALL submissions and images? This cannot be undone.');">Clear All</button>
      <button name="action" value="post_all" class="btn btn-accent" onclick="return confirm('Post ALL items in the queue to the Discord forum (no deletions)?')">Post All</button>
      <span class="small">or</span>
      <input type="number" name="n" min="1" max="<?php echo (int)$count; ?>" placeholder="Queue # (oldest=1)">
      <button name="action" value="post_number" class="btn btn-ok">Post #</button>
      <button name="action" value="delete_n" class="btn btn-warn">Delete #</button>
      <button id="chooseWinnerBtn" name="action" value="choose_winner" class="btn btn-accent"<?php echo $has_winner ? ' disabled' : ''; ?>>Choose Winner</button>
    </form>

    <h2 style="margin:14px 0 6px 0; font-size:18px;">Queue Preview (top 20)</h2>
    <table>
      <thead><tr><th>#</th><th>User</th><th>Time</th><th>File</th><th>Thumb</th><th>Votes</th><th>Odds</th></tr></thead>
      <tbody id="tbody">
        <?php
        $now = time();
        $previewVotes = array();
        $previewTotalVotes = 0;
        for ($i=0; $i<count($preview); $i++) {
          $votes = compute_votes_for_entry($preview[$i], $now);
          $previewVotes[$i] = $votes;
          $previewTotalVotes += $votes;
        }
        if (empty($preview)) {
          echo '<tr><td colspan="7" class="small">No submissions.</td></tr>';
        } else {
          for ($i=0; $i<count($preview); $i++) {
            $e = $preview[$i];
            $t = isset($e['ts']) ? date('Y-m-d H:i:s', (int)$e['ts']) : '-';
            $u = isset($e['username']) ? $e['username'] : 'Anonymous';
            if (!empty($e['winner'])) $u = '⭐ ' . $u;
            $n = entry_image_name($e);
            $thumb = entry_thumb_url($e);
            $v = isset($previewVotes[$i]) ? (int)$previewVotes[$i] : 0;
            $od = ($previewTotalVotes > 0) ? round(($v * 100) / $previewTotalVotes, 1) : 0;
            echo '<tr>';
            echo '<td>'.($i+1).'</td>';
            echo '<td>'.htmlspecialchars($u).'</td>';
            echo '<td class="small">'.htmlspecialchars($t).'</td>';
            echo '<td class="small">'.htmlspecialchars($n).'</td>';
            echo '<td class="thumb-cell">';
            if ($thumb !== '') {
              echo '<img class="thumb" src="'.htmlspecialchars($thumb).'" alt="Submission thumbnail" loading="lazy">';
            } else {
              echo '<span class="small">-</span>';
            }
            echo '</td>';
            echo '<td>'.(int)$v.'</td>';
            echo '<td>'.htmlspecialchars($od . '%').'</td>';
            echo '</tr>';
          }
        }
        ?>
      </tbody>
    </table>

    <p class="small" style="margin-top:10px;">This page auto-updates every few seconds by reading <code>logs/submissions.json</code>. Queue position #1 is processed first unless a winner is moved to the top.</p>
    <div class="build-version">build <?php echo htmlspecialchars(BUILD_VERSION); ?></div>
  </div>

<script>
// --- Live updater: poll logs/submissions.json every 3s and refresh the table/count ---
(function(){
  function fmt(ts){
    var d = new Date((ts||0)*1000);
    if (!ts) return '-';
    function pad(n){ return (n<10?'0':'')+n; }
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+' '+
           pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }

  function render(entries){
    var countEl = document.getElementById('count');
    var updEl   = document.getElementById('updated');
    var tbody   = document.getElementById('tbody');
    var chooseBtn = document.getElementById('chooseWinnerBtn');
    var nInput = document.querySelector('input[name="n"]');

    function computeVotes(entry){
      if (entry && entry.winner) return 0;
      var tsUnix = entry && entry.ts ? entry.ts : 0;
      var ageMin = Math.max(0, (Date.now()/1000 - tsUnix) / 60);
      var baseVotes = 10 + Math.floor(ageMin * 2);
      var upvotes = entry && entry.upvotes ? parseInt(entry.upvotes, 10) : 0;
      if (!(upvotes > 0)) return baseVotes;
      return Math.floor(baseVotes * (1 + Math.log(upvotes + 1)));
    }

    function thumbUrl(entry){
      var raw = '';
      if (entry && entry.path) raw = String(entry.path).split(/[\\/]/).pop();
      else if (entry && entry.file) raw = String(entry.file);
      else if (entry && entry.filename) raw = String(entry.filename);
      else if (entry && entry.image) raw = String(entry.image);
      else if (entry && entry.name && /\.[a-z0-9]{2,5}$/i.test(String(entry.name))) raw = String(entry.name);
      if (!raw) return '';
      var base = raw.split(/[\\/]/).pop();
      if (!base) return '';
      return 'uploadedImages/' + encodeURIComponent(base);
    }

    var totalVotes = 0;
    var hasWinner = false;
    for (var j=0; j<entries.length; j++){
      totalVotes += computeVotes(entries[j]);
      if (entries[j] && entries[j].winner) hasWinner = true;
    }

    countEl.textContent = entries.length.toString();
    updEl.textContent   = 'Updated ' + new Date().toLocaleTimeString();
    if (chooseBtn) {
      chooseBtn.disabled = hasWinner;
      chooseBtn.title = hasWinner ? 'A winner is already in queue' : '';
    }
    if (nInput) nInput.max = Math.max(1, entries.length);

    // Build first 20 rows (queue order)
    var limit = Math.min(entries.length, 20);
    var html = '';
    for (var i=0; i<limit; i++){
      var e = entries[i] || {};
      var user = (e.username || 'Anonymous').toString().replace(/[<>]/g,'');
      var star = e.winner ? '⭐ ' : '';
      var name = (e.path ? (''+e.path).split(/[\\/]/).pop() : (e.file || e.filename || e.image || '')).toString().replace(/[<>]/g,'');
      var thumb = thumbUrl(e);
      var votes = computeVotes(e);
      var odds = totalVotes > 0 ? ((votes * 100) / totalVotes).toFixed(1) : '0.0';
      html += '<tr>'+
              '<td>'+(i+1)+'</td>'+
              '<td>'+star+user+'</td>'+
              '<td class="small">'+fmt(e.ts)+'</td>'+
              '<td class="small">'+name+'</td>'+
              '<td class="thumb-cell">'+(thumb ? '<img class="thumb" src="'+thumb+'" alt="Submission thumbnail" loading="lazy">' : '<span class="small">-</span>')+'</td>'+
              '<td>'+votes+'</td>'+
              '<td>'+odds+'%</td>'+
              '</tr>';
    }
    if (limit === 0){
      html = '<tr><td colspan="7" class="small">No submissions.</td></tr>';
    }
    tbody.innerHTML = html;
  }

  var tickBusy = false;

  function tick(){
    if (tickBusy || document.hidden) return;
    tickBusy = true;
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeout = controller ? setTimeout(function(){ controller.abort(); }, 7000) : null;

    fetch('queue.php?full=1', {
      cache: 'no-store',
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    })
      .then(function(res){ if (!res.ok) throw new Error('HTTP '+res.status); return res.json(); })
      .then(function(data){ if (!Array.isArray(data)) data = []; render(data); })
      .catch(function(_e){ /* silent */ })
      .then(function(){
        if (timeout) clearTimeout(timeout);
        tickBusy = false;
      });
  }

  tick();
  setInterval(tick, 3000);
  document.addEventListener('visibilitychange', function(){
    if (!document.hidden) tick();
  });
})();
</script>
</body>
</html>
