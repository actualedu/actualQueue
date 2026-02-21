<?php
/**
 * queue.php — serves sanitized queue data and lightweight public actions.
 *
 * Public:  GET  queue.php           -> safe fields only (username, ts, path, winner, upvotes, entry_key)
 * Public:  GET  queue.php?spin=1    -> latest spin event payload
 * Public:  POST queue.php?upvote=1  -> upvote a queue entry (3 min cooldown per IP)
 * Admin:   GET  queue.php?full=1    -> all fields (requires active admin session)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Server-Time: ' . time());

define('SUBMISSIONS_FILE', __DIR__ . '/logs/submissions.json');
define('SPIN_FILE', __DIR__ . '/logs/spin.json');
define('UPVOTE_RATE_FILE', __DIR__ . '/logs/upvote_rate.json');
define('UPVOTE_COOLDOWN_SECONDS', 180);

function read_json_file($path) {
  $raw = @file_get_contents($path);
  $data = is_string($raw) ? @json_decode($raw, true) : array();
  return is_array($data) ? $data : array();
}

function write_json_file($path, $data) {
  return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

function entry_key($e) {
  $username = isset($e['username']) ? $e['username'] : 'Anonymous';
  $ts = isset($e['ts']) ? (string)$e['ts'] : '0';
  $path = isset($e['path']) ? $e['path'] : '';
  $name = isset($e['name']) ? $e['name'] : '';
  return sha1($username . '|' . $ts . '|' . $path . '|' . $name);
}

if (!empty($_GET['spin'])) {
  echo json_encode(read_json_file(SPIN_FILE));
  exit;
}

$entries = read_json_file(SUBMISSIONS_FILE);

if (!empty($_GET['upvote'])) {
  if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed'));
    exit;
  }

  $bodyRaw = @file_get_contents('php://input');
  $body = is_string($bodyRaw) ? @json_decode($bodyRaw, true) : array();
  $entryKey = (is_array($body) && !empty($body['entry_key'])) ? (string)$body['entry_key'] : '';
  if ($entryKey === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Missing entry key'));
    exit;
  }

  $rate = read_json_file(UPVOTE_RATE_FILE);
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
  $now = time();
  $last = isset($rate[$ip]) ? (int)$rate[$ip] : 0;
  $elapsed = $now - $last;
  if ($elapsed < UPVOTE_COOLDOWN_SECONDS) {
    $remaining = UPVOTE_COOLDOWN_SECONDS - $elapsed;
    echo json_encode(array(
      'ok' => false,
      'error' => 'Cooldown active',
      'cooldown_remaining' => $remaining,
      'next_allowed_ts' => $last + UPVOTE_COOLDOWN_SECONDS,
      'entry_key' => $entryKey
    ));
    exit;
  }

  $matchIndex = -1;
  for ($i = 0; $i < count($entries); $i++) {
    if (entry_key($entries[$i]) === $entryKey) {
      $matchIndex = $i;
      break;
    }
  }

  if ($matchIndex < 0) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'Entry not found'));
    exit;
  }

  if (!empty($entries[$matchIndex]['winner'])) {
    http_response_code(409);
    echo json_encode(array('ok' => false, 'error' => 'Winner cannot be upvoted'));
    exit;
  }

  $current = isset($entries[$matchIndex]['upvotes']) ? (int)$entries[$matchIndex]['upvotes'] : 0;
  $entries[$matchIndex]['upvotes'] = $current + 1;
  $rate[$ip] = $now;

  $savedQueue = write_json_file(SUBMISSIONS_FILE, array_values($entries));
  $savedRate = write_json_file(UPVOTE_RATE_FILE, $rate);

  if (!$savedQueue || !$savedRate) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Could not save upvote'));
    exit;
  }

  echo json_encode(array(
    'ok' => true,
    'cooldown_remaining' => UPVOTE_COOLDOWN_SECONDS,
    'next_allowed_ts' => $now + UPVOTE_COOLDOWN_SECONDS,
    'entry_key' => $entryKey
  ));
  exit;
}

// Admin gets the full data if they have a valid session
session_start();
if (!empty($_GET['full']) && !empty($_SESSION['admin_ok'])) {
  echo json_encode($entries);
  exit;
}

// Public gets only safe fields
$safe = array();
foreach ($entries as $e) {
  $safe[] = array(
    'username' => isset($e['username']) ? $e['username'] : 'Anonymous',
    'ts'       => isset($e['ts']) ? $e['ts'] : 0,
    'path'     => isset($e['path']) ? $e['path'] : '',
    'winner'   => !empty($e['winner']),
    'winner_ts'=> isset($e['winner_ts']) ? (int)$e['winner_ts'] : 0,
    'upvotes'  => isset($e['upvotes']) ? (int)$e['upvotes'] : 0,
    'entry_key'=> entry_key($e)
  );
}
echo json_encode($safe);
