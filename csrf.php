<?php
require __DIR__ . '/compat.php'; // harmless include
header('Content-Type: application/json; charset=UTF-8');

session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(secure_random_bytes(16)); // <<< compat-safe
}
echo json_encode(array('token' => $_SESSION['csrf']));
