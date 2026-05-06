<?php
/**
 * queue.php — serves sanitized queue data and lightweight public actions.
 *
 * Public:  GET  queue.php           -> safe fields only (username, ts, path, winner, upvotes, entry_key)
 * Public:  GET  queue.php?spin=1    -> latest spin event payload
 * Public:  POST queue.php?upvote=1  -> upvote a queue entry (3 min cooldown per entry)
 * Admin:   GET  queue.php?full=1    -> all fields (requires active admin session)
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Server-Time: ' . time());

require_once __DIR__ . '/session_bootstrap.php';

define('SUBMISSIONS_FILE', __DIR__ . '/logs/submissions.json');
define('SPIN_FILE', __DIR__ . '/logs/spin.json');
define('UPVOTE_RATE_FILE', __DIR__ . '/logs/upvote_rate.json');
define('UPVOTE_COOLDOWN_SECONDS', 180);

require_once __DIR__ . '/vote_state.php';

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

$entries = prepare_entries_for_voting(read_json_file(SUBMISSIONS_FILE), time());

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

  $now = time();
  $attempts = isset($entries[$matchIndex]['upvote_attempt_count']) ? (int)$entries[$matchIndex]['upvote_attempt_count'] : 0;
  $entries[$matchIndex]['upvote_attempt_count'] = $attempts + 1;
  if (empty($entries[$matchIndex]['first_upvote_attempt_ts'])) {
    $entries[$matchIndex]['first_upvote_attempt_ts'] = $now;
    $submittedTs = isset($entries[$matchIndex]['ts']) ? (int)$entries[$matchIndex]['ts'] : 0;
    if ($submittedTs > 0) {
      $entries[$matchIndex]['first_upvote_after_submit_seconds'] = max(0, $now - $submittedTs);
    }
  }
  $entries[$matchIndex]['last_upvote_attempt_ts'] = $now;
  $entries[$matchIndex]['last_upvote_user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

  $rate = read_json_file(UPVOTE_RATE_FILE);
  $canonicalKey = entry_key($entries[$matchIndex]);
  $last = isset($rate[$canonicalKey]) ? (int)$rate[$canonicalKey] : 0;
  $elapsed = $now - $last;
  if ($elapsed < UPVOTE_COOLDOWN_SECONDS) {
    write_json_file(SUBMISSIONS_FILE, array_values($entries));
    $remaining = UPVOTE_COOLDOWN_SECONDS - $elapsed;
    echo json_encode(array(
      'ok' => false,
      'error' => 'Cooldown active',
      'cooldown_remaining' => $remaining,
      'next_allowed_ts' => $last + UPVOTE_COOLDOWN_SECONDS,
      'entry_key' => $canonicalKey
    ));
    exit;
  }

  $current = isset($entries[$matchIndex]['upvotes']) ? (int)$entries[$matchIndex]['upvotes'] : 0;
  $entries[$matchIndex]['upvotes'] = $current + 1;
  $entries[$matchIndex]['last_upvote_ts'] = $now;
  $rate[$canonicalKey] = $now;

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
    'entry_key' => $canonicalKey
  ));
  exit;
}

// Admin gets the full data if they have a valid session
codex_session_start();
if (!empty($_GET['full']) && !empty($_SESSION['admin_ok'])) {
  echo json_encode($entries);
  exit;
}

// Public gets only safe fields
$safe = array();
foreach ($entries as $e) {
  $classification = (!empty($e['homework_classification']) && is_array($e['homework_classification'])) ? $e['homework_classification'] : array();
  $publicClassification = array();
  if (!empty($classification)) {
    $publicKeys = array(
      'detected_subject',
      'topic',
      'subtopic',
      'difficulty_1_to_10',
      'estimated_time_minutes',
      'estimated_grade_level',
      'question_type',
      'confidence',
      'reason_for_rating'
    );
    for ($i = 0; $i < count($publicKeys); $i++) {
      $key = $publicKeys[$i];
      if (array_key_exists($key, $classification)) {
        $publicClassification[$key] = $classification[$key];
      }
    }
  }
  $safe[] = array(
    'username' => isset($e['username']) ? $e['username'] : 'Anonymous',
    'ts'       => isset($e['ts']) ? $e['ts'] : 0,
    'path'     => isset($e['path']) ? $e['path'] : '',
    'winner'   => !empty($e['winner']),
    'winner_ts'=> isset($e['winner_ts']) ? (int)$e['winner_ts'] : 0,
    'upvotes'  => isset($e['upvotes']) ? (int)$e['upvotes'] : 0,
    'upvote_attempt_count' => isset($e['upvote_attempt_count']) ? (int)$e['upvote_attempt_count'] : 0,
    'first_upvote_after_submit_seconds' => isset($e['first_upvote_after_submit_seconds']) ? (int)$e['first_upvote_after_submit_seconds'] : 0,
    'last_upvote_ts' => isset($e['last_upvote_ts']) ? (int)$e['last_upvote_ts'] : 0,
    'vote_base_votes' => isset($e['vote_base_votes']) ? (int)$e['vote_base_votes'] : 10,
    'vote_growth_accrued' => isset($e['vote_growth_accrued']) ? (float)$e['vote_growth_accrued'] : 0.0,
    'vote_growth_updated_ts' => isset($e['vote_growth_updated_ts']) ? (int)$e['vote_growth_updated_ts'] : 0,
    'vote_share_divisor' => isset($e['vote_share_divisor']) ? (int)$e['vote_share_divisor'] : 1,
    'vote_speed_multiplier' => isset($e['vote_speed_multiplier']) ? (float)$e['vote_speed_multiplier'] : 1.0,
    'classification_subject' => (
      isset($classification['detected_subject']) && strcasecmp((string)$classification['detected_subject'], 'Mathematics') === 0 && isset($classification['topic']) && (string)$classification['topic'] !== ''
      ? (string)$classification['topic']
      : (isset($classification['detected_subject']) ? (string)$classification['detected_subject'] : '')
    ),
    'classification_difficulty' => isset($classification['difficulty_1_to_10']) ? (int)$classification['difficulty_1_to_10'] : 0,
    'homework_classification_public' => $publicClassification,
    'upvote_cooldown_until' => (
      isset($e['last_upvote_ts']) && (int)$e['last_upvote_ts'] > 0
      ? ((int)$e['last_upvote_ts'] + UPVOTE_COOLDOWN_SECONDS)
      : 0
    ),
    'entry_key'=> entry_key($e)
  );
}
echo json_encode($safe);
