<?php
// ---- compat.php ----

// Timing-safe compare (exists in PHP >=5.6, but polyfill just in case)
if (!function_exists('hash_equals')) {
  function hash_equals($known_string, $user_string) {
    if (!is_string($known_string) || !is_string($user_string)) return false;
    $len = strlen($known_string);
    if ($len !== strlen($user_string)) return false;
    $res = 0;
    for ($i = 0; $i < $len; $i++) {
      $res |= ord($known_string[$i]) ^ ord($user_string[$i]);
    }
    return $res === 0;
  }
}

// Cryptographically decent bytes (prefer OpenSSL; fallback to mt_rand)
if (!function_exists('secure_random_bytes')) {
  function secure_random_bytes($length) {
    // Try OpenSSL (PHP 5.3+; may be disabled)
    if (function_exists('openssl_random_pseudo_bytes')) {
      $strong = false;
      $bytes = openssl_random_pseudo_bytes($length, $strong);
      if ($bytes !== false && $strong === true) return $bytes;
    }
    // Fallback (not crypto-strong, but better than nothing on old hosts)
    $buf = '';
    for ($i = 0; $i < $length; $i++) { $buf .= chr(mt_rand(0, 255)); }
    return $buf;
  }
}
