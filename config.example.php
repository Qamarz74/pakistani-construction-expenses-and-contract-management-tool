<?php
return [
    'dsn' => 'mysql:host=127.0.0.1;dbname=construction_tracker;charset=utf8mb4',
    'username' => 'construction_user',
    'password' => '',
    // Prefer a directory outside your public web root on shared hosting.
    'upload_dir' => __DIR__ . '/storage/uploads',
    'max_upload_bytes' => 5 * 1024 * 1024,
];
