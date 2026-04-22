<?php

if (!function_exists('codex_session_start')) {
  function codex_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return true;

    if (session_name() !== 'AOHSESSID') {
      session_name('AOHSESSID');
    }

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');

    $currentPath = (string)session_save_path();
    $needsFallback = ($currentPath === '' || !is_dir($currentPath) || !is_writable($currentPath));
    if ($needsFallback) {
      $fallbackPaths = array(
        rtrim(sys_get_temp_dir(), '/\\') . '/actualofficehours-php-sessions',
        __DIR__ . '/logs/php_sessions'
      );

      for ($i = 0; $i < count($fallbackPaths); $i++) {
        $fallbackPath = $fallbackPaths[$i];
        if (!is_dir($fallbackPath)) {
          @mkdir($fallbackPath, 0777, true);
        }
        @chmod($fallbackPath, 0777);
        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
          session_save_path($fallbackPath);
          break;
        }
      }
    }

    $started = @session_start();
    if ($started) return true;

    if (!headers_sent()) {
      if (isset($_COOKIE[session_name()])) {
        unset($_COOKIE[session_name()]);
        setcookie(session_name(), '', time() - 42000, '/');
      }
    }
    return false;
  }
}
