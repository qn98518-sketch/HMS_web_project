<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/avatar_image.php';
try {
    if (!extension_loaded('gd')) throw new RuntimeException('GD is unavailable.');
    if (!isset($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Image not found.');
    echo renderAvatarJpeg($argv[1]);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
