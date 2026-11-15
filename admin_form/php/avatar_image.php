<?php
const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_MAX_PIXELS = 16000000;
const AVATAR_SIZE = 256;

function avatarImageInfo(string $path): array {
    $size = filesize($path);
    if ($size === false || $size > AVATAR_MAX_BYTES) throw new RuntimeException('Image is too large (max 2 MB).');
    $info = @getimagesize($path);
    if (!$info) throw new RuntimeException('This file is not a valid image.');
    [$width, $height, $type] = $info;
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Only JPG, PNG, GIF or WEBP images are allowed.');
    }
    if ($width < 1 || $height < 1 || $width * $height > AVATAR_MAX_PIXELS) {
        throw new RuntimeException('Image dimensions are too large.');
    }
    return $info;
}

function renderAvatarJpeg(string $path): string {
    [, , $type] = avatarImageInfo($path);
    if (!function_exists('imagecreatefromstring')) {
        // XAMPP may leave GD disabled in Apache. Enable it only in a CLI worker,
        // keeping the global php.ini and Apache configuration unchanged.
        $binary = dirname((string)php_ini_loaded_file()) . DIRECTORY_SEPARATOR . 'php.exe';
        if (!is_file($binary) || !is_callable('proc_open')) {
            throw new RuntimeException('Image processing is unavailable on this server.');
        }
        $pipes = [];
        $process = @proc_open([$binary, '-d', 'extension=gd', '-d', 'memory_limit=256M',
            '-f', __DIR__ . '/avatar_worker.php', '--', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            __DIR__, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Image processing is unavailable on this server.');
        fclose($pipes[0]);
        $jpeg = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $jpeg === false || substr($jpeg, 0, 2) !== "\xff\xd8") {
            error_log('Avatar worker: ' . $error);
            throw new RuntimeException('Cannot process this image. Please choose another image.');
        }
        return $jpeg;
    }
    $image = @imagecreatefromstring((string)file_get_contents($path));
    if (!$image) throw new RuntimeException('Cannot read this image.');
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) { imagedestroy($image); $image = $rotated; }
        }
    }
    $width = imagesx($image); $height = imagesy($image); $side = min($width, $height);
    $output = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagefill($output, 0, 0, imagecolorallocate($output, 255, 255, 255));
    imagecopyresampled($output, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2),
        AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    ob_start();
    imagejpeg($output, null, 85);
    $jpeg = (string)ob_get_clean();
    imagedestroy($output); imagedestroy($image);
    return $jpeg;
}
