<?php
require __DIR__ . '/compat.php'; // <<< add this

define('MAX_QUEUE', 20);
define('MIN_SECONDS_BETWEEN', 60);
define('RATE_DIR', __DIR__ . '/rate_limit');
define('QUEUE_DIR', __DIR__ . '/submissions');
define('IMAGE_DIR', __DIR__ . '/uploadedImages');

$dirs = array(RATE_DIR, QUEUE_DIR, IMAGE_DIR);
foreach ($dirs as $d) { if (!is_dir($d)) @mkdir($d, 0755, true); }
