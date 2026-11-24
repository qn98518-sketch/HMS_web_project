<?php
session_start();

/*
|--------------------------------------------------------------------------
| CHECK PATIENT SESSION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "patient"
) {
    http_response_code(403);
    exit();
}

/*
|--------------------------------------------------------------------------
| AVATAR DIRECTORY
|--------------------------------------------------------------------------
*/

$userId = (string) $_SESSION["user_id"];

$avatarFolder = __DIR__ . "/uploads/avatars/";

$avatarBaseName = hash("sha256", $userId);

$allowedFiles = [
    "jpg"  => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png"  => "image/png",
    "webp" => "image/webp"
];

/*
|--------------------------------------------------------------------------
| FIND CURRENT USER AVATAR
|--------------------------------------------------------------------------
*/

foreach ($allowedFiles as $extension => $mimeType) {

    $filePath = $avatarFolder . $avatarBaseName . "." . $extension;

    if (!is_file($filePath)) {
        continue;
    }

    // Verify actual MIME type
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = finfo_file($fileInfo, $filePath);
    finfo_close($fileInfo);

    if ($actualMime !== $mimeType) {
        continue;
    }

    // Return image to browser
    header("Content-Type: " . $actualMime);
    header("Content-Length: " . filesize($filePath));
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");

    readfile($filePath);
    exit();
}

/*
|--------------------------------------------------------------------------
| NO AVATAR FOUND
|--------------------------------------------------------------------------
*/

http_response_code(404);
exit();