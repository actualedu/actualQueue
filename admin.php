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

require_once __DIR__ . '/session_bootstrap.php';

codex_session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function env_config($key, $default) {
  $value = getenv($key);
  if ($value !== false && $value !== '') return $value;
  if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
  if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

  static $envFile = null;
  if ($envFile === null) {
    $envFile = array();
    $paths = array(__DIR__ . '/.env', dirname(__DIR__) . '/.env');
    for ($i = 0; $i < count($paths); $i++) {
      $path = $paths[$i];
      if (!is_file($path) || !is_readable($path)) continue;
      $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!is_array($lines)) continue;
      for ($j = 0; $j < count($lines); $j++) {
        $line = trim($lines[$j]);
        if ($line === '' || $line[0] === '#') continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $name = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        if ($name === '') continue;
        if (strlen($val) >= 2) {
          $first = $val[0];
          $last = $val[strlen($val) - 1];
          if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
          }
        }
        $envFile[$name] = $val;
      }
    }
  }

  if (isset($envFile[$key]) && $envFile[$key] !== '') return $envFile[$key];
  return $default;
}

// Load admin key from environment (preferred), with a clear fail-closed behavior
$__env_key = env_config('CONNECT_QUEUE_ADMIN_KEY', '');
if (!$__env_key) { $__env_key = env_config('CONNECT_ADMIN_SECRET', ''); }
define('ADMIN_KEY', $__env_key);
unset($__env_key);

define('UPLOAD_DIR', __DIR__ . '/uploadedImages');
define('SUBMISSIONS_FILE', __DIR__ . '/logs/submissions.json');
define('SPIN_FILE', __DIR__ . '/logs/spin.json');
define('ADMIN_LOG', __DIR__ . '/logs/admin_error.log');
define('BUILD_VERSION', '2026-04-22.03');
define('YOUTUBE_API_KEY', env_config('YOUTUBE_API_KEY', ''));
define('YOUTUBE_CHANNEL_ID', env_config('YOUTUBE_CHANNEL_ID', ''));
define('SUSPICIOUS_RECENT_WINDOW_SECONDS', 2700);
define('SUSPICIOUS_BEHAVIOR_WINDOW_SECONDS', 2700);
define('SUSPICIOUS_BROWSER_UA', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36');

@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('error_log', __DIR__ . '/logs/php_error.log');

// === Discord forum webhook config ===
require_once __DIR__ . '/forumWebhook.php';
require_once __DIR__ . '/vote_state.php';

$DISCORD_FORUM_WEBHOOK = env_config('DISCORD_FORUM_WEBHOOK', '');

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
if ($DISCORD_FORUM_WEBHOOK === '') {
  alog("Discord forum webhook missing: DISCORD_FORUM_WEBHOOK is not set");
}
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
  return prepare_entries_for_voting(is_array($arr) ? $arr : array(), time());
}

function write_submissions($arr) {
  $prepared = prepare_entries_for_voting(is_array($arr) ? $arr : array(), time());
  $result = @file_put_contents(SUBMISSIONS_FILE, json_encode(array_values($prepared), JSON_PRETTY_PRINT));
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

function lottery_pick($arr) {
  $now = time();
  $arr = prepare_entries_for_voting($arr, $now);
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
    $p = trim((string)$entry['path']);
    if ($p === '') return '';
    $p = str_replace('\\', '/', $p);

    // Legacy absolute web path from old deployment under /submit.
    if (strpos($p, '/submit/') === 0) {
      return __DIR__ . '/' . ltrim(substr($p, strlen('/submit/')), '/');
    }

    if (strpos($p, '/uploadedImages/') === 0) {
      return __DIR__ . $p;
    }

    if ($p[0] === '/' || preg_match('~^[A-Za-z]:\\\\~', $p)) {
      return $p;
    }

    if (strpos($p, 'uploadedImages/') === 0) {
      return __DIR__ . '/' . ltrim($p, '/');
    }

    $uploadPos = strpos($p, '/uploadedImages/');
    if ($uploadPos !== false) {
      return UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($p);
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
  $abs = entry_abs_image($entry);
  if ($abs !== '') {
    safe_unlink($abs);
    return;
  }

  $filename = entry_image_name($entry);
  if ($filename !== '') {
    $fallback = UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
    if (is_file($fallback)) safe_unlink($fallback);
  }
}

function suspicious_username_score($raw) {
  $name = trim((string)$raw);
  if ($name === '') return 10;

  $score = 0;
  $lower = strtolower($name);
  $compact = preg_replace('/[^a-z0-9]/', '', $lower);
  $lettersOnly = preg_replace('/[^a-z]/', '', $lower);
  $len = strlen($compact);

  if ($compact !== '' && preg_match('/^\d+$/', $compact)) $score += 6;
  if ($len >= 8 && preg_match('/^[a-z0-9]+$/', $compact) && !preg_match('/[aeiou]/', $compact)) $score += 4;
  if (strlen($name) >= 30 && preg_match('/\s/', $name) && !preg_match('/[aeiou]{2}/', $lower)) $score += 4;
  if ($lettersOnly !== '' && preg_match('/[asdfjkl;qwertyuiopzxcvbnm]{8,}/', $lettersOnly)) $score += 4;
  if ($len >= 10) {
    preg_match_all('/[aeiou]/', $compact, $vowelMatches);
    $vowelCount = isset($vowelMatches[0]) ? count($vowelMatches[0]) : 0;
    if ($vowelCount <= 1) $score += 3;
  }
  if (preg_match('/(.)\1{3,}/i', $name)) $score += 2;

  return $score;
}

function suspicious_entry_field($entry, $key) {
  return (is_array($entry) && isset($entry[$key])) ? trim((string)$entry[$key]) : '';
}

function suspicious_entry_behavior_reasons($entry, $entries, $index, $now) {
  $reasons = array();
  if (!is_array($entry) || !empty($entry['winner'])) return $reasons;

  $ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
  if ($ts < 1) return $reasons;

  $ua = suspicious_entry_field($entry, 'user_agent');
  if ($ua === '') $ua = suspicious_entry_field($entry, 'last_upvote_user_agent');
  $ip = suspicious_entry_field($entry, 'ip');
  $client = suspicious_entry_field($entry, 'client_id');
  $attempts = isset($entry['upvote_attempt_count']) ? (int)$entry['upvote_attempt_count'] : 0;
  $upvotes = isset($entry['upvotes']) ? (int)$entry['upvotes'] : 0;
  $firstUpvoteDelta = isset($entry['first_upvote_after_submit_seconds']) ? (int)$entry['first_upvote_after_submit_seconds'] : 0;

  if ($ua === SUSPICIOUS_BROWSER_UA) {
    $reasons[] = 'same suspicious browser signature';
  }
  if ($firstUpvoteDelta > 0 && $firstUpvoteDelta <= 15) {
    $reasons[] = 'upvoted within ' . $firstUpvoteDelta . 's';
  }
  if ($attempts >= 8) {
    $reasons[] = $attempts . ' upvote attempts';
  } elseif ($upvotes >= 7) {
    $reasons[] = $upvotes . ' stored upvotes';
  }

  $clusterCount = 0;
  $distinctIps = array();
  $distinctClients = array();
  if ($ua !== '' && is_array($entries)) {
    for ($i = 0; $i < count($entries); $i++) {
      $other = $entries[$i];
      if (!is_array($other) || !empty($other['winner'])) continue;
      $otherTs = isset($other['ts']) ? (int)$other['ts'] : 0;
      if ($otherTs < 1 || abs($otherTs - $ts) > SUSPICIOUS_BEHAVIOR_WINDOW_SECONDS) continue;

      $otherUa = suspicious_entry_field($other, 'user_agent');
      if ($otherUa === '') $otherUa = suspicious_entry_field($other, 'last_upvote_user_agent');
      if ($otherUa !== $ua) continue;

      $clusterCount++;
      $otherIp = suspicious_entry_field($other, 'ip');
      $otherClient = suspicious_entry_field($other, 'client_id');
      if ($otherIp !== '') $distinctIps[$otherIp] = true;
      if ($otherClient !== '') $distinctClients[$otherClient] = true;
    }
  }

  if ($clusterCount >= 5 && count($distinctIps) >= 5 && count($distinctClients) >= 5) {
    $reasons[] = 'same-browser cluster across ' . count($distinctIps) . ' IPs';
  }

  return $reasons;
}

function is_suspicious_recent_entry($entry, $now, $entries = null, $index = -1) {
  if (!is_array($entry)) return false;
  if (!empty($entry['winner'])) return false;

  $ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
  if ($ts < 1) return false;

  $behaviorReasons = suspicious_entry_behavior_reasons($entry, $entries, $index, $now);
  $hasBrowserSignal = false;
  $hasClusterSignal = false;
  for ($i = 0; $i < count($behaviorReasons); $i++) {
    if ($behaviorReasons[$i] === 'same suspicious browser signature') $hasBrowserSignal = true;
    if (strpos($behaviorReasons[$i], 'same-browser cluster across ') === 0) $hasClusterSignal = true;
  }
  if (count($behaviorReasons) >= 2 && ($hasBrowserSignal || $hasClusterSignal)) return true;

  if (($now - $ts) <= SUSPICIOUS_RECENT_WINDOW_SECONDS) {
    $score = suspicious_username_score(entry_username($entry));
    if ($score >= 6) return true;
  }

  return false;
}

function count_suspicious_recent_entries($entries, $now) {
  $count = 0;
  for ($i = 0; $i < count($entries); $i++) {
    if (is_suspicious_recent_entry($entries[$i], $now, $entries, $i)) $count++;
  }
  return $count;
}

function entry_selection_key($entry) {
  $parts = array(
    isset($entry['ts']) ? (string)$entry['ts'] : '',
    entry_username($entry),
    entry_image_name($entry),
    isset($entry['hash']) ? (string)$entry['hash'] : '',
    isset($entry['ip']) ? (string)$entry['ip'] : ''
  );
  return sha1(implode('|', $parts));
}

function render_login($error_msg) {
  if ($error_msg === '' && ADMIN_KEY === '') {
    $error_msg = 'Admin key is not configured. Set CONNECT_QUEUE_ADMIN_KEY or CONNECT_ADMIN_SECRET, or add it to a local .env file.';
  }
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
  if (ADMIN_KEY === '') {
    render_login('Admin key is not configured. Set CONNECT_QUEUE_ADMIN_KEY or CONNECT_ADMIN_SECRET, or add it to a local .env file.');
    exit;
  }
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
elseif ($action === 'delete_selected') {
  $arr = read_submissions();
  $selected = isset($_POST['selected']) && is_array($_POST['selected']) ? $_POST['selected'] : array();
  $selectedMap = array();
  for ($i = 0; $i < count($selected); $i++) {
    $k = preg_replace('/[^a-f0-9]/', '', strtolower((string)$selected[$i]));
    if ($k !== '') $selectedMap[$k] = true;
  }

  if (empty($selectedMap)) {
    $message = 'No entries selected.';
    $type = 'warning';
  } else {
    $kept = array();
    $deleted = array();
    for ($i = 0; $i < count($arr); $i++) {
      $entry = $arr[$i];
      $key = entry_selection_key($entry);
      if (isset($selectedMap[$key])) {
        delete_entry_image($entry);
        $deleted[] = entry_username($entry);
        continue;
      }
      $kept[] = $entry;
    }

    if (count($deleted) === 0) {
      $message = 'No selected entries matched the current queue.';
      $type = 'warning';
    } elseif (!write_submissions($kept)) {
      $message = 'Matched ' . count($deleted) . ' selected entr' . (count($deleted) === 1 ? 'y' : 'ies') . ', but saving the queue failed.';
      $type = 'warning';
    } else {
      $previewNames = array_slice($deleted, 0, 5);
      $message = 'Deleted ' . count($deleted) . ' selected entr' . (count($deleted) === 1 ? 'y' : 'ies') . ': ' . implode(', ', $previewNames);
      if (count($deleted) > count($previewNames)) $message .= ', ...';
      $message .= '.';
      $type = 'success';
    }

    alog('Admin delete_selected', array('selected_count'=>count($selectedMap), 'deleted_count'=>count($deleted), 'deleted_users'=>$deleted));
  }
}
elseif ($action === 'delete_suspicious_recent') {
  $arr = read_submissions();
  $now = time();
  $kept = array();
  $deleted = array();

  for ($i = 0; $i < count($arr); $i++) {
    $entry = $arr[$i];
    if (is_suspicious_recent_entry($entry, $now, $arr, $i)) {
      delete_entry_image($entry);
      $deleted[] = entry_username($entry);
      continue;
    }
    $kept[] = $entry;
  }

  if (count($deleted) === 0) {
    $message = 'No suspicious entries matched the cleanup rule.';
    $type = 'warning';
  } elseif (!write_submissions($kept)) {
    $message = 'Matched ' . count($deleted) . ' suspicious entries, but saving the queue failed.';
    $type = 'warning';
  } else {
    $previewNames = array_slice($deleted, 0, 5);
    $message = 'Deleted ' . count($deleted) . ' suspicious entr' . (count($deleted) === 1 ? 'y' : 'ies') . ': ' . implode(', ', $previewNames);
    if (count($deleted) > count($previewNames)) $message .= ', ...';
    $message .= '.';
    $type = 'success';
  }

  alog('Admin delete_suspicious_recent', array('deleted_count'=>count($deleted), 'deleted_users'=>$deleted));
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
$preview = $subs;
$has_winner = queue_has_winner($subs);
$suspicious_recent_count = count_suspicious_recent_entries($subs, time());
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
  .select-cell { width:44px; text-align:center; }
  .flag-cell { width:140px; }
  .thumb-cell { width:64px; }
  .table-actions{
    margin:10px 0 6px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }
  .table-actions .small{ margin:0; }
  .queue-table-form{ margin:0; }
  .thumb {
    width:48px;
    height:48px;
    border-radius:8px;
    border:1px solid rgba(255,255,255,.14);
    object-fit:cover;
    background:#0b1020;
    display:block;
  }
  .badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 9px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.2px;
    white-space:nowrap;
  }
  .badge-warn{
    background:rgba(245,166,35,.18);
    color:#ffd27a;
    border:1px solid rgba(245,166,35,.35);
  }
  .row-check, .check-all{
    width:16px;
    height:16px;
    cursor:pointer;
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
      <button id="deleteSuspiciousRecentBtn" name="action" value="delete_suspicious_recent" class="btn btn-warn" onclick="return confirm('Delete entries flagged by suspicious username or submission/upvote behavior?');">Delete Suspicious<?php if ($suspicious_recent_count > 0): ?> (<?php echo (int)$suspicious_recent_count; ?>)<?php endif; ?></button>
      <button name="action" value="post_all" class="btn btn-accent" onclick="return confirm('Post ALL items in the queue to the Discord forum (no deletions)?')">Post All</button>
      <span class="small">or</span>
      <input type="number" name="n" min="1" max="<?php echo (int)$count; ?>" placeholder="Queue # (oldest=1)">
      <button name="action" value="post_number" class="btn btn-ok">Post #</button>
      <button name="action" value="delete_n" class="btn btn-warn">Delete #</button>
      <button id="chooseWinnerBtn" name="action" value="choose_winner" class="btn btn-accent"<?php echo $has_winner ? ' disabled' : ''; ?>>Choose Winner</button>
    </form>

    <h2 style="margin:14px 0 6px 0; font-size:18px;">Queue Preview</h2>
    <form method="post" class="queue-table-form" id="queueTableForm">
      <input type="hidden" name="action" value="delete_selected">
      <div class="table-actions">
        <button id="deleteSelectedBtn" type="submit" class="btn btn-warn" disabled onclick="return confirm('Delete the selected entries and their images?');">Delete Selected</button>
        <span class="small" id="selectedCount">0 selected</span>
      </div>
    <table>
      <thead><tr><th class="select-cell"><input class="check-all" id="checkAllRows" type="checkbox" aria-label="Select all rows"></th><th>#</th><th>User</th><th>Flags</th><th>Time</th><th>File</th><th>Thumb</th><th>Votes</th><th>Supers</th><th>Rate</th><th>Odds</th></tr></thead>
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
          echo '<tr><td colspan="9" class="small">No submissions.</td></tr>';
        } else {
          for ($i=0; $i<count($preview); $i++) {
            $e = $preview[$i];
            $t = isset($e['ts']) ? date('Y-m-d H:i:s', (int)$e['ts']) : '-';
            $u = isset($e['username']) ? $e['username'] : 'Anonymous';
            if (!empty($e['winner'])) $u = '⭐ ' . $u;
            $n = entry_image_name($e);
            $thumb = entry_thumb_url($e);
            $v = isset($previewVotes[$i]) ? (int)$previewVotes[$i] : 0;
            $upvotes = isset($e['upvotes']) ? (int)$e['upvotes'] : 0;
            $rate = round(entry_vote_growth_rate_per_hour($e, $now));
            $od = ($previewTotalVotes > 0) ? round(($v * 100) / $previewTotalVotes, 1) : 0;
            $selectKey = entry_selection_key($e);
            $flagReasons = suspicious_entry_behavior_reasons($e, $preview, $i, $now);
            $flagTitle = !empty($flagReasons) ? implode('; ', $flagReasons) : 'Suspicious username';
            $flagHtml = is_suspicious_recent_entry($e, $now, $preview, $i)
              ? '<span class="badge badge-warn" title="'.htmlspecialchars($flagTitle).'">Suspicious</span>'
              : '<span class="small">-</span>';
            echo '<tr>';
            echo '<td class="select-cell"><input class="row-check" type="checkbox" name="selected[]" value="'.htmlspecialchars($selectKey).'"></td>';
            echo '<td>'.($i+1).'</td>';
            echo '<td>'.htmlspecialchars($u).'</td>';
            echo '<td class="flag-cell">'.$flagHtml.'</td>';
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
            echo '<td>'.(int)$upvotes.'</td>';
            echo '<td>'.htmlspecialchars('+' . $rate . '/hr').'</td>';
            echo '<td>'.htmlspecialchars($od . '%').'</td>';
            echo '</tr>';
          }
        }
        ?>
      </tbody>
    </table>
    </form>

    <p class="small" style="margin-top:10px;">This page auto-updates every few seconds by reading <code>logs/submissions.json</code>. Queue position #1 is processed first unless a winner is moved to the top.</p>
    <div class="build-version">build <?php echo htmlspecialchars(BUILD_VERSION); ?></div>
  </div>

<script>
// --- Live updater: poll logs/submissions.json every 3s and refresh the table/count ---
(function(){
  var selectedKeys = {};

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
    var deleteSuspiciousBtn = document.getElementById('deleteSuspiciousRecentBtn');
    var deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    var selectedCountEl = document.getElementById('selectedCount');
    var checkAllRows = document.getElementById('checkAllRows');
    var nInput = document.querySelector('input[name="n"]');

    function growthVotesAt(tsUnix, atUnix){
      if (!(tsUnix > 0) || !(atUnix > tsUnix)) return 0;
      var ageMin = (atUnix - tsUnix) / 60;
      if (ageMin <= 45) return ageMin * 2;
      if (ageMin <= 90) return 90 + ((ageMin - 45) * 4);
      return 270 + ((ageMin - 90) * 6);
    }

    function computeVotes(entry){
      if (entry && entry.winner) return 0;
      var nowUnix = Date.now() / 1000;
      var tsUnix = entry && entry.ts ? parseInt(entry.ts, 10) : 0;
      var updatedTs = entry && entry.vote_growth_updated_ts ? parseInt(entry.vote_growth_updated_ts, 10) : tsUnix;
      if (!(updatedTs >= tsUnix)) updatedTs = tsUnix;
      if (updatedTs > nowUnix) updatedTs = nowUnix;
      var divisor = entry && entry.vote_share_divisor ? parseInt(entry.vote_share_divisor, 10) : 1;
      if (!(divisor >= 1)) divisor = 1;
      var accrued = entry && entry.vote_growth_accrued != null ? parseFloat(entry.vote_growth_accrued) : 0;
      if (!(accrued >= 0)) accrued = 0;
      if (nowUnix > updatedTs) {
        accrued += (growthVotesAt(tsUnix, nowUnix) - growthVotesAt(tsUnix, updatedTs)) / divisor;
      }
      var baseStart = entry && entry.vote_base_votes != null ? parseInt(entry.vote_base_votes, 10) : 10;
      if (!(baseStart >= 0)) baseStart = 10;
      var baseVotes = baseStart + Math.floor(accrued);
      var upvotes = entry && entry.upvotes ? parseInt(entry.upvotes, 10) : 0;
      if (!(upvotes > 0)) return baseVotes;
      return Math.floor(baseVotes * (1 + Math.log(upvotes + 1)));
    }

    function growthRatePerHour(entry){
      if (entry && entry.winner) return 0;
      var nowUnix = Date.now() / 1000;
      var tsUnix = entry && entry.ts ? parseInt(entry.ts, 10) : 0;
      if (!(tsUnix > 0) || !(nowUnix > tsUnix)) return 0;
      var ageMin = (nowUnix - tsUnix) / 60;
      var hourly = 0;
      if (ageMin <= 45) hourly = 120;
      else if (ageMin <= 90) hourly = 240;
      else hourly = 360;
      var divisor = entry && entry.vote_share_divisor ? parseInt(entry.vote_share_divisor, 10) : 1;
      if (!(divisor >= 1)) divisor = 1;
      return hourly / divisor;
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

    function suspiciousUsernameScore(raw){
      var name = ((raw == null ? '' : String(raw))).trim();
      if (!name) return 10;

      var score = 0;
      var lower = name.toLowerCase();
      var compact = lower.replace(/[^a-z0-9]/g, '');
      var lettersOnly = lower.replace(/[^a-z]/g, '');
      var len = compact.length;

      if (compact && /^\d+$/.test(compact)) score += 6;
      if (len >= 8 && /^[a-z0-9]+$/.test(compact) && !/[aeiou]/.test(compact)) score += 4;
      if (name.length >= 30 && /\s/.test(name) && !/[aeiou]{2}/.test(lower)) score += 4;
      if (lettersOnly && /[asdfjkl;qwertyuiopzxcvbnm]{8,}/.test(lettersOnly)) score += 4;
      if (len >= 10) {
        var vowels = compact.match(/[aeiou]/g);
        var vowelCount = vowels ? vowels.length : 0;
        if (vowelCount <= 1) score += 3;
      }
      if (/(.)\1{3,}/i.test(name)) score += 2;

      return score;
    }

    var suspiciousBrowserUa = <?php echo json_encode(SUSPICIOUS_BROWSER_UA); ?>;
    var suspiciousBehaviorWindowSeconds = <?php echo (int)SUSPICIOUS_BEHAVIOR_WINDOW_SECONDS; ?>;

    function entryUa(entry){
      if (!entry) return '';
      if (entry.user_agent) return String(entry.user_agent);
      if (entry.last_upvote_user_agent) return String(entry.last_upvote_user_agent);
      return '';
    }

    function suspiciousBehaviorReasons(entry, entries){
      var reasons = [];
      if (!entry || entry.winner) return reasons;
      var ts = entry.ts ? parseInt(entry.ts, 10) : 0;
      if (!(ts > 0)) return reasons;

      var ua = entryUa(entry);
      var attempts = entry.upvote_attempt_count ? parseInt(entry.upvote_attempt_count, 10) : 0;
      var upvotes = entry.upvotes ? parseInt(entry.upvotes, 10) : 0;
      var firstDelta = entry.first_upvote_after_submit_seconds ? parseInt(entry.first_upvote_after_submit_seconds, 10) : 0;

      if (ua && ua === suspiciousBrowserUa) reasons.push('same suspicious browser signature');
      if (firstDelta > 0 && firstDelta <= 15) reasons.push('upvoted within ' + firstDelta + 's');
      if (attempts >= 8) reasons.push(attempts + ' upvote attempts');
      else if (upvotes >= 7) reasons.push(upvotes + ' stored upvotes');

      var clusterCount = 0;
      var ips = {};
      var clients = {};
      if (ua && entries && entries.length) {
        for (var i=0; i<entries.length; i++) {
          var other = entries[i] || {};
          if (other.winner) continue;
          var otherTs = other.ts ? parseInt(other.ts, 10) : 0;
          if (!(otherTs > 0) || Math.abs(otherTs - ts) > suspiciousBehaviorWindowSeconds) continue;
          if (entryUa(other) !== ua) continue;
          clusterCount++;
          if (other.ip) ips[String(other.ip)] = true;
          if (other.client_id) clients[String(other.client_id)] = true;
        }
      }

      if (clusterCount >= 5 && Object.keys(ips).length >= 5 && Object.keys(clients).length >= 5) {
        reasons.push('same-browser cluster across ' + Object.keys(ips).length + ' IPs');
      }

      return reasons;
    }

    function isSuspiciousRecent(entry, nowSec, entries){
      if (!entry || entry.winner) return false;
      var ts = entry.ts ? parseInt(entry.ts, 10) : 0;
      if (!(ts > 0)) return false;
      var reasons = suspiciousBehaviorReasons(entry, entries);
      var hasBrowserSignal = reasons.indexOf('same suspicious browser signature') !== -1;
      var hasClusterSignal = false;
      for (var i=0; i<reasons.length; i++) {
        if (reasons[i].indexOf('same-browser cluster across ') === 0) hasClusterSignal = true;
      }
      if (reasons.length >= 2 && (hasBrowserSignal || hasClusterSignal)) return true;
      if ((nowSec - ts) <= <?php echo (int)SUSPICIOUS_RECENT_WINDOW_SECONDS; ?>) {
        var user = entry.username || entry.user || entry.author || 'Anonymous';
        return suspiciousUsernameScore(user) >= 6;
      }
      return false;
    }

    function entrySelectionKey(entry){
      var parts = [
        entry && entry.ts ? String(entry.ts) : '',
        entry && (entry.username || entry.user || entry.author || 'Anonymous') ? String(entry.username || entry.user || entry.author || 'Anonymous') : '',
        entry && (entry.path ? String(entry.path).split(/[\\/]/).pop() : (entry.file || entry.filename || entry.image || entry.name || '')) ? String(entry.path ? String(entry.path).split(/[\\/]/).pop() : (entry.file || entry.filename || entry.image || entry.name || '')) : '',
        entry && entry.hash ? String(entry.hash) : '',
        entry && entry.ip ? String(entry.ip) : ''
      ];
      return sha1(parts.join('|'));
    }

    var totalVotes = 0;
    var hasWinner = false;
    var suspiciousRecentCount = 0;
    var nowSec = Math.floor(Date.now() / 1000);
    for (var j=0; j<entries.length; j++){
      totalVotes += computeVotes(entries[j]);
      if (entries[j] && entries[j].winner) hasWinner = true;
      if (isSuspiciousRecent(entries[j], nowSec, entries)) suspiciousRecentCount++;
    }

    countEl.textContent = entries.length.toString();
    updEl.textContent   = 'Updated ' + new Date().toLocaleTimeString();
    if (chooseBtn) {
      chooseBtn.disabled = hasWinner;
      chooseBtn.title = hasWinner ? 'A winner is already in queue' : '';
    }
    if (deleteSuspiciousBtn) {
      deleteSuspiciousBtn.textContent = 'Delete Suspicious' + (suspiciousRecentCount > 0 ? ' (' + suspiciousRecentCount + ')' : '');
      deleteSuspiciousBtn.disabled = suspiciousRecentCount === 0;
      deleteSuspiciousBtn.title = suspiciousRecentCount > 0 ? '' : 'No suspicious recent entries matched the cleanup rule';
    }
    if (nInput) nInput.max = Math.max(1, entries.length);

    // Build the full queue in order and let the page scroll naturally.
    var limit = entries.length;
    var html = '';
    for (var i=0; i<limit; i++){
      var e = entries[i] || {};
      var user = (e.username || 'Anonymous').toString().replace(/[<>]/g,'');
      var star = e.winner ? '⭐ ' : '';
      var name = (e.path ? (''+e.path).split(/[\\/]/).pop() : (e.file || e.filename || e.image || '')).toString().replace(/[<>]/g,'');
      var thumb = thumbUrl(e);
      var votes = computeVotes(e);
      var odds = totalVotes > 0 ? ((votes * 100) / totalVotes).toFixed(1) : '0.0';
      var reasons = suspiciousBehaviorReasons(e, entries);
      var suspicious = isSuspiciousRecent(e, nowSec, entries);
      var selectionKey = entrySelectionKey(e);
      var checkedAttr = selectedKeys[selectionKey] ? ' checked' : '';
      var flagTitle = reasons.length ? reasons.join('; ').replace(/"/g, '&quot;') : 'Suspicious username';
      var flagHtml = suspicious ? '<span class="badge badge-warn" title="'+flagTitle+'">Suspicious</span>' : '<span class="small">-</span>';
      html += '<tr>'+
              '<td class="select-cell"><input class="row-check" type="checkbox" name="selected[]" value="'+selectionKey+'"'+checkedAttr+'></td>'+
              '<td>'+(i+1)+'</td>'+
              '<td>'+star+user+'</td>'+
              '<td class="flag-cell">'+flagHtml+'</td>'+
              '<td class="small">'+fmt(e.ts)+'</td>'+
              '<td class="small">'+name+'</td>'+
              '<td class="thumb-cell">'+(thumb ? '<img class="thumb" src="'+thumb+'" alt="Submission thumbnail" loading="lazy">' : '<span class="small">-</span>')+'</td>'+
              '<td>'+votes+'</td>'+
              '<td>'+(e.upvotes ? parseInt(e.upvotes, 10) : 0)+'</td>'+
              '<td>+'+growthRatePerHour(e).toFixed(0)+'/hr</td>'+
              '<td>'+odds+'%</td>'+
              '</tr>';
    }
    if (limit === 0){
      html = '<tr><td colspan="11" class="small">No submissions.</td></tr>';
    }
    tbody.innerHTML = html;
    syncSelectionUi();
  }

  function sha1(msg) {
    function rotl(n, s) { return (n << s) | (n >>> (32 - s)); }
    function tohex(i) {
      var h = '';
      for (var s = 28; s >= 0; s -= 4) h += ((i >>> s) & 0xf).toString(16);
      return h;
    }
    var bytes = unescape(encodeURIComponent(msg));
    var words = [];
    var i;
    for (i = 0; i < bytes.length; i++) {
      words[i >> 2] |= bytes.charCodeAt(i) << (24 - (i % 4) * 8);
    }
    words[i >> 2] |= 0x80 << (24 - (i % 4) * 8);
    words[(((i + 8) >> 6) + 1) * 16 - 1] = bytes.length * 8;

    var w = new Array(80);
    var h0 = 0x67452301, h1 = 0xefcdab89, h2 = 0x98badcfe, h3 = 0x10325476, h4 = 0xc3d2e1f0;
    for (var b = 0; b < words.length; b += 16) {
      for (i = 0; i < 16; i++) w[i] = words[b + i] || 0;
      for (i = 16; i < 80; i++) w[i] = rotl(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
      var a = h0, c = h2, d = h3, e = h4, f, k, temp;
      var bb = h1;
      for (i = 0; i < 80; i++) {
        if (i < 20) { f = (bb & c) | ((~bb) & d); k = 0x5a827999; }
        else if (i < 40) { f = bb ^ c ^ d; k = 0x6ed9eba1; }
        else if (i < 60) { f = (bb & c) | (bb & d) | (c & d); k = 0x8f1bbcdc; }
        else { f = bb ^ c ^ d; k = 0xca62c1d6; }
        temp = (rotl(a, 5) + f + e + k + (w[i] || 0)) | 0;
        e = d; d = c; c = rotl(bb, 30); bb = a; a = temp;
      }
      h0 = (h0 + a) | 0;
      h1 = (h1 + bb) | 0;
      h2 = (h2 + c) | 0;
      h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0;
    }
    return tohex(h0) + tohex(h1) + tohex(h2) + tohex(h3) + tohex(h4);
  }

  function syncSelectionUi(){
    var deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    var selectedCountEl = document.getElementById('selectedCount');
    var checkAllRows = document.getElementById('checkAllRows');
    var rowChecks = document.querySelectorAll('.row-check');
    var selectedCount = 0;
    var visibleCount = rowChecks.length;
    var allChecked = visibleCount > 0;

    for (var i = 0; i < rowChecks.length; i++) {
      var box = rowChecks[i];
      if (box.checked) selectedCount++;
      else allChecked = false;
    }

    if (selectedCountEl) selectedCountEl.textContent = selectedCount + ' selected';
    if (deleteSelectedBtn) deleteSelectedBtn.disabled = selectedCount === 0;
    if (checkAllRows) {
      checkAllRows.checked = visibleCount > 0 && allChecked;
      checkAllRows.indeterminate = selectedCount > 0 && selectedCount < visibleCount;
    }
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

  document.addEventListener('change', function(evt){
    var target = evt.target;
    if (!target) return;

    if (target.id === 'checkAllRows') {
      var rowChecks = document.querySelectorAll('.row-check');
      for (var i = 0; i < rowChecks.length; i++) {
        rowChecks[i].checked = !!target.checked;
        if (target.checked) selectedKeys[rowChecks[i].value] = true;
        else delete selectedKeys[rowChecks[i].value];
      }
      syncSelectionUi();
      return;
    }

    if (target.classList && target.classList.contains('row-check')) {
      if (target.checked) selectedKeys[target.value] = true;
      else delete selectedKeys[target.value];
      syncSelectionUi();
    }
  });

  var queueTableForm = document.getElementById('queueTableForm');
  if (queueTableForm) {
    queueTableForm.addEventListener('submit', function(){
      selectedKeys = {};
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
