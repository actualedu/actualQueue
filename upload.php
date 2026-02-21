<?php
/**
 * upload.php — PHP 5.6 compatible
 * - Saves to uploadedImages/
 * - Appends to submit/logs/submissions.json with *many* compatible keys:
 *   user/name/username, time/ts/date/datetime/iso (+ timestamp unix),
 *   file/filename/image and path
 * - Discord forwarder best-effort via local fwdDiscord.php (HTTP or include),
 *   optional direct webhook if you define DISCORD_WEBHOOK
 * - Guards: queue cap, per-IP cooldown, CSRF, honeypot, size/MIME/image checks, de-dup
 * - Returns a pretty HTML page (restored) on POST; JSON only for ?action=csrf
 */

/* ===================== CONFIG ===================== */
define('MAX_QUEUE', 99);
define('MIN_SECONDS_BETWEEN', 30);

define('BASE_DIR', __DIR__);                         // /.../submit
define('IMAGE_DIR', BASE_DIR . '/uploadedImages');   // images dir
define('LOGS_DIR',  BASE_DIR . '/logs');             // logs dir
define('SUBMISSIONS_JSON', LOGS_DIR . '/submissions.json'); // queue file
define('RATE_DIR',  BASE_DIR . '/rate_limit');       // per-IP stamps
define('HASH_PREFIX', LOGS_DIR . '/.hash-');         // de-dup markers
define('ERROR_LOG_FILE', LOGS_DIR . '/upload_error.log');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', LOGS_DIR . '/php_error.log');

@mkdir(IMAGE_DIR, 0755, true);
@mkdir(LOGS_DIR,  0755, true);
@mkdir(RATE_DIR,  0755, true);

/* Optional direct webhook fallback
// define('DISCORD_WEBHOOK', 'https://discord.com/api/webhooks/XXXX/XXXXX');
*/

/* ===================== UTILS / POLYFILLS ===================== */
function _dbg($msg, $ctx = array()) {
  $line = '['.date('Y-m-d H:i:s')."] ".$msg.(empty($ctx)?'':' '.json_encode($ctx))."\n";
  @file_put_contents(ERROR_LOG_FILE, $line, FILE_APPEND);
}
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('hash_equals')) {
  function hash_equals($a, $b) {
    if (!is_string($a) || !is_string($b)) return false;
    $la = strlen($a); $lb = strlen($b);
    $status = $la ^ $lb; $res = 0;
    for ($i=0; $i<$lb; $i++) $res |= ord($a[$i % $la]) ^ ord($b[$i]);
    return ($status === 0) && ($res === 0);
  }
}

function random_bytes_56($len) {
  if ($len <= 0) return '';
  if (function_exists('openssl_random_pseudo_bytes')) {
    $strong=false; $raw=openssl_random_pseudo_bytes($len,$strong);
    if ($raw !== false && $strong === true) return $raw;
  }
  $buf=''; for($i=0;$i<$len;$i++) $buf.=chr(mt_rand(0,255));
  return $buf;
}

function csrf_token_generate() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes_56(16));
  return $_SESSION['csrf'];
}

function ip_key($ip){ return str_replace(array(':','.'), '-', (string)$ip); }
/** return array(allowed,bool|int retry_after) — fails open if rate_limit dir is unwritable */
function ip_rate_allow($ip,$cooldown){
  $fn=RATE_DIR.'/'.ip_key($ip).'.txt'; $now=time();
  $fh=@fopen($fn,'c+');
  if(!$fh){
    _dbg('rate_limit: cannot open file (permissions?)', array('file'=>$fn, 'dir_writable'=>is_writable(RATE_DIR)));
    return array(true,0); // fail open — let the user through
  }
  if(!flock($fh,LOCK_EX)){ fclose($fh); return array(true,0); }
  $prev=0; $sz=@filesize($fn); if($sz && $sz>0) $prev=(int)trim(fread($fh,$sz));
  if($prev && ($now-$prev)<$cooldown){
    $retry=max(1,$cooldown-($now-$prev)); flock($fh,LOCK_UN); fclose($fh);
    return array(false,$retry);
  }
  ftruncate($fh,0); rewind($fh); fwrite($fh,(string)$now); fflush($fh);
  flock($fh,LOCK_UN); fclose($fh); return array(true,0);
}

/** Purge rate_limit and hash dedup files older than 7 days. Runs at most once per hour. */
function rate_limit_cleanup(){
  $marker = RATE_DIR . '/.last_cleanup';
  $now = time();
  if (file_exists($marker) && ($now - (int)@filemtime($marker)) < 3600) return;
  @touch($marker);
  $maxAge = 7 * 86400;

  // Purge old rate_limit IP files
  $dh = @opendir(RATE_DIR);
  if ($dh) {
    while (($f = readdir($dh)) !== false) {
      if ($f[0] === '.') continue;
      $path = RATE_DIR . '/' . $f;
      if (is_file($path) && ($now - (int)@filemtime($path)) > $maxAge) {
        @unlink($path);
      }
    }
    closedir($dh);
  }

  // Purge old image dedup hash markers
  $dh = @opendir(LOGS_DIR);
  if ($dh) {
    while (($f = readdir($dh)) !== false) {
      if (strpos($f, '.hash-') !== 0) continue;
      $path = LOGS_DIR . '/' . $f;
      if (is_file($path) && ($now - (int)@filemtime($path)) > $maxAge) {
        @unlink($path);
      }
    }
    closedir($dh);
  }
}

function sniff_mime($tmp){
  $fi=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($fi,$tmp); finfo_close($fi); return $mime;
}
function ext_from_mime($m){
  switch($m){ case 'image/jpeg':return '.jpg'; case 'image/png':return '.png'; case 'image/gif':return '.gif'; case 'image/webp':return '.webp'; default:return ''; }
}

/* ===================== QUEUE HELPERS ===================== */
function queue_load_all(){
  if(!file_exists(SUBMISSIONS_JSON)) return array();
  $fh=@fopen(SUBMISSIONS_JSON,'r'); if(!$fh) return array();
  if(!flock($fh,LOCK_SH)){ fclose($fh); return array(); }
  $sz=@filesize(SUBMISSIONS_JSON);
  $raw=($sz && $sz>0)?fread($fh,$sz):''; flock($fh,LOCK_UN); fclose($fh);
  if($raw==='') return array();
  $arr=@json_decode($raw,true); return is_array($arr)?$arr:array();
}
function queue_save_all($list){
  $tmp=SUBMISSIONS_JSON.'.tmp';
  $fh=@fopen($tmp,'w');
  if(!$fh){
    _dbg('queue_save_all: cannot create tmp file', array('tmp'=>$tmp, 'dir_writable'=>is_writable(dirname($tmp))));
    return false;
  }
  if(!flock($fh,LOCK_EX)){ fclose($fh); @unlink($tmp); return false; }
  fwrite($fh,json_encode($list)); fflush($fh); flock($fh,LOCK_UN); fclose($fh);
  if(!@rename($tmp,SUBMISSIONS_JSON)){
    _dbg('queue_save_all: rename failed', array('tmp'=>$tmp, 'dest'=>SUBMISSIONS_JSON));
    @unlink($tmp);
    return false;
  }
  return true;
}
function queue_count(){ $arr=queue_load_all(); return count($arr); }
function queue_append_item($entry){
  $list=queue_load_all(); if(count($list)>=MAX_QUEUE) return false;
  $list[]=$entry; if(!queue_save_all($list)) return false;
  return array($list,count($list));
}

/* ===================== DISCORD FORWARDING ===================== */
function forward_via_http_local($absPath,$username){
  if(!function_exists('curl_init')) return false;
  $url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https://':'http://')
      .$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['REQUEST_URI']),'/').'/fwdDiscord.php';
  $mime=sniff_mime($absPath);
  if(function_exists('curl_file_create')) $file=curl_file_create($absPath,$mime,basename($absPath));
  else $file=new CURLFile($absPath,$mime,basename($absPath));
  $payload=array('username'=>$username,'path'=>$absPath,'file'=>$file);
  $ch=curl_init(); curl_setopt_array($ch,array(
    CURLOPT_URL=>$url, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_HTTPHEADER=>array('Expect:')
  ));
  $res=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  if($res===false || $code>=400){ _dbg('fwdDiscord HTTP failed',array('http'=>$code,'err'=>curl_error($ch),'resp'=>$res)); curl_close($ch); return false; }
  curl_close($ch); return true;
}
function forward_via_include($absPath,$username){
  $f=BASE_DIR.'/fwdDiscord.php'; if(!file_exists($f)) return false;
  include_once $f;
  if(function_exists('forward_to_discord')){
    try{ return (bool)forward_to_discord($absPath,$username); }
    catch(Exception $e){ _dbg('fwd include exception',array('e'=>$e->getMessage())); return false; }
  }
  return false;
}
function forward_via_webhook($absPath,$username){
  if(!defined('DISCORD_WEBHOOK') || !function_exists('curl_init')) return false;
  $mime=sniff_mime($absPath);
  if(function_exists('curl_file_create')) $file=curl_file_create($absPath,$mime,basename($absPath));
  else $file=new CURLFile($absPath,$mime,basename($absPath));
  $payload=array('content'=>'**'.$username.'**','file'=>$file);
  $ch=curl_init(); curl_setopt_array($ch,array(
    CURLOPT_URL=>DISCORD_WEBHOOK, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_HTTPHEADER=>array('Expect:')
  ));
  $res=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  if($res===false || $code>=400){ _dbg('webhook failed',array('http'=>$code,'err'=>curl_error($ch),'resp'=>$res)); curl_close($ch); return false; }
  curl_close($ch); return true;
}

/* ===================== USERNAME BAN LIST & HELPERS (PHP 5.6 safe) ===================== */
/* Keep your lists lowercase */
$BAD_WORDS_EXACT   = array('stfu','kys','nig','fck');
$BAD_WORDS_PARTIAL = array('faggot','retard','penis','vagina','pussy','cum','anal','bitch','jizz','whore','slut','queer','nigger','dildo','fucker','cock','semen','blowjob','dick','dicksuck','rape','fag','nigga','retarded','fags','faggots','ass','fuck','fucking','shit','retarted','jew','simp','hitler','adolf','simping','simp','jerk','masturbate','fap','porn','stfu','nut','cunt','funking','bitches','hoe','p0rn','onlyfans');

// --- Put this right after your $BAD_WORDS_* arrays ---
function bw_sanitize_exact_list($arr) {
  $out = array();
  foreach ($arr as $w) {
    $w = strtolower(trim($w));
    // remove any invisible / non-ASCII crud
    $w = preg_replace('/[^\x20-\x7E]/', '', $w);
    // keep only a-z0-9 tokens for EXACT list
    $w = preg_replace('/[^a-z0-9]+/', '', $w);
    if ($w !== '') $out[$w] = true;
  }
  return array_keys($out); // unique
}
function bw_sanitize_partial_list($arr) {
  $out = array();
  foreach ($arr as $w) {
    $w = strtolower(trim($w));
    $w = preg_replace('/[^\x20-\x7E]/', '', $w);           // strip non-ASCII
    $w = preg_replace('/[^a-z0-9]+/', '', $w);             // flatten like usernames
    if ($w !== '') $out[$w] = true;
  }
  return array_keys($out);
}

// Sanitize in-place:
$BAD_WORDS_EXACT   = bw_sanitize_exact_list($BAD_WORDS_EXACT);
$BAD_WORDS_PARTIAL = bw_sanitize_partial_list($BAD_WORDS_PARTIAL);

/* Lowercase + very light accent strip (ASCII fallback) */
function bw_lower($s) { return strtolower($s); }

/* Basic leet replacements to catch obfuscation */
function bw_leetmap($s) {
  $map = array(
    '4'=>'a','@'=>'a',
    '8'=>'b',
    '3'=>'e',
    '6'=>'g',
    '1'=>'i','!'=>'i','|'=>'i',
    '0'=>'o',
    '$'=>'s','5'=>'s',
    '7'=>'t',
    '2'=>'z'
  );
  return strtr($s, $map);
}

/* Collapse long repeats: fuuuuu -> fuu */
function bw_collapse_repeats($s) {
  return preg_replace('/([a-z0-9])\\1{2,}/', '$1$1', $s);
}

/* Strip all non-alphanumerics completely for partial matching */
function bw_strip_non_alnum($s) {
  return preg_replace('/[^a-z0-9]+/', '', $s);
}

/* Tokenize on non-alphanumerics for exact matching */
function bw_tokens($s) {
  $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
  return $parts ? $parts : array();
}

/**
 * Returns array(true, $word, $mode) if banned, else array(false, '', '')
 */
function username_is_banned($rawName, $BAD_WORDS_EXACT, $BAD_WORDS_PARTIAL) {
  // normalize
  $name = bw_lower($rawName);
  $name = bw_leetmap($name);
  $name = bw_collapse_repeats($name);

  // EXACT: by tokens (so "ass hat" hits "ass" in exact if present)
  $tokens = bw_tokens($name);
  if ($tokens) {
    $set = array();
    foreach ($tokens as $t) $set[$t] = true;
    foreach ($BAD_WORDS_EXACT as $w) {
      if (isset($set[$w])) return array(true, $w, 'exact');
    }
  }

  // PARTIAL: remove all non-alnum and search
  $flat = bw_strip_non_alnum($name);
  foreach ($BAD_WORDS_PARTIAL as $w) {
    if ($w !== '' && strpos($flat, $w) !== false) {
      return array(true, $w, 'partial');
    }
  }

  return array(false, '', '');
}


/* ===================== ROUTING ===================== */
session_start();
rate_limit_cleanup();

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'GET') {
  $action = isset($_GET['action']) ? $_GET['action'] : '';

  // CSRF token (existing behavior)
  if ($action === 'csrf') {
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('token' => csrf_token_generate()));
    exit;
  }

  // Default GET response
  header('Content-Type: text/plain; charset=UTF-8');
  echo "upload.php ready. POST to upload, GET ?action=csrf for a token.\n";
  exit;
}

/* ===================== POST: UPLOAD FLOW ===================== */
$success = false; $msg = '';

// Honeypot
if (!empty($_POST['website'])) {
  http_response_code(400); $msg = 'Bad request.'; return render();
}

// CSRF
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  http_response_code(403); $msg = 'Invalid security token.'; return render();
}

// Queue cap
if (queue_count() >= MAX_QUEUE) {
  http_response_code(503); $msg = 'Queue is full right now. Please try again later.'; return render();
}

// Per-IP cooldown
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
list($okRL, $retryAfter) = ip_rate_allow($ip, MIN_SECONDS_BETWEEN);
if (!$okRL) {
  header('Retry-After: ' . (int)$retryAfter);
  http_response_code(429); $msg = 'Please wait '.(int)$retryAfter.' seconds before submitting again.'; return render();
}

// Inputs
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
if ($username === '') { http_response_code(400); $msg = 'Missing username.'; return render(); }

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400); $msg = 'No image uploaded.'; return render();
}

// Validate
if ($_FILES['image']['size'] > 10*1024*1024) {
  http_response_code(413); $msg = 'File too large (max 10MB).'; return render();
}
$mime = sniff_mime($_FILES['image']['tmp_name']);
$allowed = array('image/jpeg','image/png','image/gif','image/webp');
if (!in_array($mime, $allowed, true)) {
  http_response_code(415); $msg = 'Unsupported file type.'; return render();
}
$img = @getimagesize($_FILES['image']['tmp_name']);
if ($img === false) {
  http_response_code(415); $msg = 'File is not a valid image.'; return render();
}

// De-dup during queue lifetime
$hash = sha1_file($_FILES['image']['tmp_name']);
$hashMarker = HASH_PREFIX.$hash;
if (file_exists($hashMarker)) {
  http_response_code(409); $msg = 'This image was already submitted.'; return render();
}
@touch($hashMarker);

// Check banlist on the raw input before sanitizing
$rawUsername = isset($_POST['username']) ? $_POST['username'] : '';
list($blocked, $hit, $mode) = username_is_banned($rawUsername, $BAD_WORDS_EXACT, $BAD_WORDS_PARTIAL);
_dbg('ban decision', array('blocked'=>$blocked, 'hit'=>$hit, 'mode'=>$mode));

if ($blocked) {
  _dbg('username blocked', array('username'=>$rawUsername));
  http_response_code(400);
  $msg = 'That username is not allowed. Please choose a different one.';
  return render();
}

// Sanitize for storage
$username = substr(preg_replace('/[^\w\s\.\-\@\#]/', '', $rawUsername), 0, 60);

if ($username === '') {
  http_response_code(400);
  $msg = 'Please enter a valid username.';
  return render();
}

// Save file
$ext = ext_from_mime($mime); if ($ext==='') $ext='.jpg';
$stamp = date('Ymd_His');
$rand  = bin2hex(random_bytes_56(6));
$base  = $stamp.'_'.$rand.$ext;
$dest  = rtrim(IMAGE_DIR,'/').'/'.$base;

if (!@move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
  @unlink($hashMarker); http_response_code(500); $msg='Could not save file.'; return render();
}
@chmod($dest, 0644);

// Build entry with LOTS of compatible keys
$nowUnix = time();
$timeStr = date('Y-m-d H:i:s', $nowUnix);
$isoStr  = date('c', $nowUnix); // ISO-8601 with T

$entry = array(
  // user aliases
  'user'      => $username,
  'name'      => $username,
  'username'  => $username,

  // time aliases (strings + unix)
  'time'      => $timeStr,        // STRING for display
  'ts'        => $nowUnix,        // <-- unix seconds (what your JS expects)
  'date'      => $timeStr,
  'datetime'  => $timeStr,
  'iso'       => $isoStr,
  'timestamp' => $nowUnix,        // unix seconds (redundant alias)
  // file aliases
  'file'      => $base,
  'filename'  => $base,
  'image'     => $base,
  'path'      => 'uploadedImages/'.$base,

  // extras
  'mime'      => $mime,
  'ip'        => $ip,
  'hash'      => $hash
);

// Append to queue
$res = queue_append_item($entry);
if ($res === false) {
  @unlink($dest); @unlink($hashMarker);
  http_response_code(500); $msg='Could not record submission.'; return render();
}
list($newList, $position) = $res;

// Forward to Discord (best-effort)
$abs = $dest;
$okFwd = forward_via_http_local($abs,$username) || forward_via_include($abs,$username) || forward_via_webhook($abs,$username);
if (!$okFwd) { _dbg('Discord forward failed', array('file'=>$base,'user'=>$username)); }

// Success
$success = true;
http_response_code(200);
$msg = 'Submission received. You are #'.$position.' in the queue.';
return render();


/* ===================== VIEW: PRETTY RESPONSE ===================== */
function render(){
  global $success, $msg;
  // --------------------------- RESPONSE --------------------------------
  // (Your original HTML, kept verbatim)
  ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo $success ? 'Success' : 'Error'; ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { --bg:#0b1020; --card:#121933; --ok:#42c17b; --err:#ff6b6b; --text:#e9eefc; --muted:#a4b1d1; }
    html,body { height:100%;}
    body {
      margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:var(--bg); color:var(--text); display:flex; align-items:center; justify-content:center;
    }
    .wrap {
      width:min(720px, 92vw); background:var(--card); border-radius:14px; padding:24px 28px;
      box-shadow:0 12px 28px rgba(0,0,0,.35);
    }
    h1 { margin:0 0 6px 0; font-size:26px; color: <?php echo $success ? 'var(--ok)' : 'var(--err)'; ?>; }
    p  { margin:6px 0 14px 0; color:var(--muted);}
    a.btn { display:inline-block; background:#4c79ff; color:#fff; padding:12px 16px; border-radius:12px; text-decoration:none; text-align: center; font-weight: 800; }
    a.btn:hover { opacity:.9; }
    a.btn2 { display:inline-block; background:#42c17b; color:#fff; padding:12px 16px; border-radius:12px; text-decoration:none; text-align: center; font-weight: 800; }
    a.btn2:hover { opacity:.9; }
    a.btn3 { display:inline-block; background:#42c1e0; color:#fff; padding:12px 16px; border-radius:12px; text-decoration:none; text-align: center; font-weight: 800; }
    a.btn3:hover { opacity:.9; }
    a.btn3.pulse {
      box-shadow: 0 0 0 0 rgba(66,193,224,.55);
      animation: uploadPulse 2s ease-out infinite;
    }
    @keyframes uploadPulse {
      0% {
        box-shadow: 0 0 0 0 rgba(66,193,224,.55), 0 0 10px rgba(66,193,224,.45);
      }
      70% {
        box-shadow: 0 0 0 14px rgba(66,193,224,0), 0 0 22px rgba(66,193,224,.15);
      }
      100% {
        box-shadow: 0 0 0 0 rgba(66,193,224,0), 0 0 10px rgba(66,193,224,.35);
      }
    }
    @media (prefers-reduced-motion: reduce) {
      a.btn3.pulse { animation: none; }
    }
    .small { font-size:12px; color:#8fa0cc; margin-top:8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1><?php echo $success ? 'Thanks! Your screenshot was received.' : 'Upload failed'; ?></h1>
    <p><?php echo h($msg); ?></p>
    <?php if ($success): ?>
      <p>Tip: Click <strong>View Queue</strong> and upvote your own question to improve your chances of getting picked live.</p>
    <?php endif; ?>
    <p>Check our Discord to download a copy of the solution after the stream</p>
    <?php if (!$success): ?>
      <p class="small">See error logs for details.</p>
    <?php endif; ?>
    <a class="btn2" href="index.html">Return</a>
    <a class="btn" href="https://discord.actual.education">Discord</a>
    <a class="btn3<?php echo $success ? ' pulse' : ''; ?>" href="stats.php">View Queue</a>
  </div>
</body>
</html>
<?php
  exit;
}
