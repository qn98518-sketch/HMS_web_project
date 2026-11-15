<?php
if (!defined('AVATAR_DIR')) define('AVATAR_DIR', __DIR__ . '/../../uploads/avatars');

function avatarPath(?string $file): ?string {
    return ($file && preg_match('/^[a-f0-9]{32}\.jpg$/D', $file)) ? AVATAR_DIR . '/' . $file : null;
}

function removeAvatarFile(?string $file): void {
    $path = avatarPath($file);
    if ($path && is_file($path)) @unlink($path);
}

/*==============================================================
  avatar_helpers.php - dùng chung cho account_api.php, avatar.php
  và users_api.php (khi muốn trả avatar trong danh sách user)
==============================================================*/

// Số phiên bản gắn vào URL ảnh: đổi ảnh (hoặc đổi tên với avatar mặc định) thì URL đổi,
// trình duyệt tự tải ảnh mới dù ảnh cũ đã được lưu cache.
function avatarVersion(?string $avatar, string $fullName): string {
    return $avatar ? substr($avatar, 0, 8) : dechex(crc32($fullName));
}

// URL tương đối so với thư mục admin_form/
function avatarUrl(string $userId, ?string $avatar, string $fullName): string {
    return "php/avatar.php?id=" . rawurlencode($userId) . "&v=" . avatarVersion($avatar, $fullName);
}
