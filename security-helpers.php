<?php
function queue_count() {
  $files = glob(QUEUE_DIR . '/*');
  return $files ? count($files) : 0;
}
function queue_is_full() { return queue_count() >= MAX_QUEUE; }
function ip_key($ip) { return str_replace(array(':','.'), '-', $ip); }

// Per-IP cooldown with flock (all 5.6-safe)
function ip_rate_allow($ip, $cooldown = MIN_SECONDS_BETWEEN) {
  $fn  = RATE_DIR . '/' . ip_key($ip) . '.txt';
  $now = time();
  $fh  = @fopen($fn, 'c+');
  if (!$fh) return array(false, $cooldown);
  if (!flock($fh, LOCK_EX)) { fclose($fh); return array(false, $cooldown); }

  $prev = 0;
  $size = @filesize($fn);
  if ($size > 0) { $raw = fread($fh, $size); $prev = (int)trim($raw); }

  if ($prev && ($now - $prev) < $cooldown) {
    $retry = $cooldown - ($now - $prev);
    flock($fh, LOCK_UN); fclose($fh);
    return array(false, ($retry > 0 ? $retry : 1));
  }
  ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)$now); fflush($fh);
  flock($fh, LOCK_UN); fclose($fh);
  return array(true, 0);
}

function ext_from_mime($mime) {
  switch ($mime) {
    case 'image/jpeg': return '.jpg';
    case 'image/png':  return '.png';
    case 'image/gif':  return '.gif';
    case 'image/webp': return '.webp';
    default: return '';
  }
}
