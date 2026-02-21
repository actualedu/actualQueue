<?php
header('Content-Type: text/plain; charset=UTF-8');

echo "=== Permission Diagnostics ===\n\n";

echo "PHP user:       " . get_current_user() . "\n";
echo "Effective UID:  " . (function_exists('posix_geteuid') ? posix_geteuid() : 'n/a') . "\n";
echo "open_basedir:   " . (ini_get('open_basedir') ?: '(none)') . "\n\n";

$checks = array(
  'docroot'         => __DIR__,
  'logs/'           => __DIR__ . '/logs',
  'submissions.json'=> __DIR__ . '/logs/submissions.json',
  'uploadedImages/' => __DIR__ . '/uploadedImages',
  'rate_limit/'     => __DIR__ . '/rate_limit',
);

foreach ($checks as $label => $path) {
  $exists   = file_exists($path) ? 'yes' : 'NO';
  $writable = is_writable($path) ? 'yes' : 'NO';
  $perms    = file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : '----';
  $owner    = file_exists($path) ? (function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : fileowner($path)) : '-';
  $group    = file_exists($path) ? (function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($path))['name'] : filegroup($path)) : '-';

  printf("%-20s exists=%-3s writable=%-3s perms=%s owner=%s group=%s\n",
    $label, $exists, $writable, $perms, $owner, $group);
}

echo "\n=== Write Tests ===\n\n";

// Test actual write to each critical location
$writeTests = array(
  'logs/'           => __DIR__ . '/logs/.permtest',
  'uploadedImages/' => __DIR__ . '/uploadedImages/.permtest',
  'rate_limit/'     => __DIR__ . '/rate_limit/.permtest',
);

foreach ($writeTests as $label => $path) {
  $ok = @file_put_contents($path, 'test');
  if ($ok !== false) {
    @unlink($path);
    echo "$label  write OK\n";
  } else {
    echo "$label  WRITE FAILED\n";
  }
}

// Test overwriting submissions.json specifically
$subFile = __DIR__ . '/logs/submissions.json';
if (file_exists($subFile)) {
  $content = @file_get_contents($subFile);
  $ok = @file_put_contents($subFile, $content);
  echo "submissions.json  " . ($ok !== false ? "write OK" : "WRITE FAILED") . "\n";
} else {
  echo "submissions.json  does not exist yet\n";
}
