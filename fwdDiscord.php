<?php
// =====================================================================
// fwdDiscord.php — forwards image + username to Discord with filename
// Preserves original filename on multipart so Discord previews images.
// Accepts EITHER:
//   A) multipart:  $_FILES['image'] + $_POST['username']
//   B) path post:  $_POST['path']   + $_POST['username']
// Logs to logs/fwd_error.log
// =====================================================================

define('WEBHOOK_URL', 'https://discord.com/api/webhooks/1344288823328374855/UJnpt0K0yGe3fA0XsTF4142nmlvoBsDV6J9bDq2g8by4Es_wqIu1wKC2VJGkrJSXcLsP'); // <-- paste your Discord webhook URL
define('FWD_LOG', __DIR__ . '/logs/fwd_error.log');

function respond($code, $arr) {
  header('Content-Type: application/json');
  http_response_code($code);
  echo json_encode($arr);
  exit;
}
function flog($msg, $ctx = array()) {
  $line = '['.date('c').'] '.$msg.(empty($ctx)?'':' '.json_encode($ctx)).PHP_EOL;
  @file_put_contents(FWD_LOG, $line, FILE_APPEND);
}
function sniff_mime($path) {
  if (class_exists('finfo')) { $fi=new finfo(FILEINFO_MIME_TYPE); $m=$fi->file($path); if ($m) return $m; }
  if (function_exists('mime_content_type')) { $m=@mime_content_type($path); if ($m) return $m; }
  return 'application/octet-stream';
}
function make_curl_file($path, $mime, $postname) {
  if (function_exists('curl_file_create')) {
    return curl_file_create($path, $mime, $postname);
  } else {
    // PHP 5.6 CURLFile supports ctor($path,$mime,$postname)
    return new CURLFile($path, $mime, $postname);
  }
}
function send_to_discord_webhook($filePath, $postNameForDiscord, $usernameForMessage) {
  if (!WEBHOOK_URL) { flog('WEBHOOK_URL missing'); return array(false,'WEBHOOK_URL not set'); }
  if (!is_file($filePath) || !is_readable($filePath)) { flog('File missing/unreadable',array('path'=>$filePath)); return array(false,'File not found'); }
  if (!function_exists('curl_init')) { flog('cURL not available'); return array(false,'cURL not available'); }

  $mime = sniff_mime($filePath);
  $cfile = make_curl_file($filePath, $mime, $postNameForDiscord);

  $payload = array(
    'content' => ($usernameForMessage !== '' ? '**'.$usernameForMessage.'**' : 'New submission'),
    'file'    => $cfile
  );

  $ch = curl_init();
  curl_setopt_array($ch, array(
    CURLOPT_URL            => WEBHOOK_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => array('Expect:')
  ));
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  if ($resp === false || $code >= 400) {
    $err = curl_error($ch);
    curl_close($ch);
    flog('Webhook post failed', array('http'=>$code,'curl'=>$err,'resp'=>$resp));
    return array(false,'Discord webhook failed: HTTP '.$code);
  }
  curl_close($ch);
  return array(true,'ok');
}

// --------------- MAIN ---------------
$method   = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
if ($username === '') $username = 'Anonymous';

// A) Multipart upload directly to this endpoint
if ($method === 'POST' && isset($_FILES['image']) && is_array($_FILES['image'])) {
  $f = $_FILES['image'];
  if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    flog('Invalid uploaded image', array('err'=>$f['error']));
    respond(400, array('ok'=>false, 'error'=>'No valid uploaded image'));
  }
  // IMPORTANT: pass original filename (with extension) so Discord previews it
  $originalName = isset($f['name']) && $f['name'] !== '' ? $f['name'] : 'image.png';
  list($ok,$msg) = send_to_discord_webhook($f['tmp_name'], $originalName, $username);
  if (!$ok) respond(500, array('ok'=>false,'error'=>$msg));
  respond(200, array('ok'=>true,'msg'=>'forwarded (multipart)','name'=>$originalName));
}

// B) Saved path provided (upload.php already saved file)
if ($method === 'POST' && !empty($_POST['path'])) {
  $path = (string)$_POST['path'];
  $postname = basename($path); // already has extension from your saver
  list($ok,$msg) = send_to_discord_webhook($path, $postname, $username);
  if (!$ok) respond(500, array('ok'=>false,'error'=>$msg));
  respond(200, array('ok'=>true,'msg'=>'forwarded (path)','name'=>$postname));
}

respond(400, array('ok'=>false,'error'=>'No file provided'));

