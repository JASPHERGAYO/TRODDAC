<?php
declare(strict_types=1);

require __DIR__ . '/../config/auth.php';

$user = requireApiRole(['passenger', 'driver', 'operator', 'officer']);

if (!isPost()) {
    jsonOut(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (!schemaHas('users', 'profile_picture')) {
    jsonOut(['ok' => false, 'error' => SCHEMA_HELP], 503);
}

const AVATAR_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const AVATAR_MAX_DIM   = 512;
const AVATAR_DIR_REL   = 'uploads/avatars';

$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
$action    = (string)($_POST['action'] ?? 'upload');

/** Deletes a previously saved avatar file if it lives inside uploads/avatars. */
$deleteOld = static function (?string $relPath) use ($uploadDir): void {
    if (!$relPath || !str_starts_with($relPath, AVATAR_DIR_REL . '/')) {
        return;
    }
    $name = basename($relPath);
    if ($name === '' || str_contains($name, '..')) {
        return;
    }
    $full = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (is_file($full)) {
        @unlink($full);
    }
};

$oldPath = $user['profile_picture'] ?? null;

if ($action === 'remove') {
    db()->prepare('UPDATE users SET profile_picture = NULL WHERE id = ?')->execute([(int)$user['id']]);
    $deleteOld($oldPath);
    jsonOut(['ok' => true, 'url' => null, 'message' => 'Profile picture removed.']);
}

if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    jsonOut(['ok' => false, 'error' => 'Choose an image file to upload.'], 400);
}

$file = $_FILES['avatar'];
$err  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    $msg = match ($err) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large. Maximum size is 2 MB.',
        UPLOAD_ERR_NO_FILE                        => 'Choose an image file to upload.',
        default                                   => 'Upload failed. Please try again.',
    };
    jsonOut(['ok' => false, 'error' => $msg], 400);
}

$tmp  = (string)($file['tmp_name'] ?? '');
$size = (int)($file['size'] ?? 0);
if ($tmp === '' || !is_uploaded_file($tmp)) {
    jsonOut(['ok' => false, 'error' => 'Invalid file upload.'], 400);
}
if ($size <= 0 || $size > AVATAR_MAX_BYTES) {
    jsonOut(['ok' => false, 'error' => 'Image must be 2 MB or smaller.'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($tmp);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    jsonOut(['ok' => false, 'error' => 'Only JPG, PNG, and WebP images are allowed.'], 400);
}

$info = @getimagesize($tmp);
if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
    jsonOut(['ok' => false, 'error' => 'Please upload a valid image (at least 16x16 pixels).'], 400);
}

if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
    jsonOut(['ok' => false, 'error' => 'Could not prepare the upload folder.'], 500);
}

$ext      = $allowed[$mime];
$filename = 'u' . (int)$user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destFull = $uploadDir . DIRECTORY_SEPARATOR . $filename;
$saved    = false;

// Re-encode and center-crop to a clean square when GD is available (strips metadata & shrinks file size).
if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
    $raw = @file_get_contents($tmp);
    $src = $raw !== false ? @imagecreatefromstring($raw) : false;
    if ($src !== false) {
        $sw   = imagesx($src);
        $sh   = imagesy($src);
        $side = min($sw, $sh);
        $sx   = (int)floor(($sw - $side) / 2);
        $sy   = (int)floor(($sh - $side) / 2);
        $out  = min(AVATAR_MAX_DIM, $side);
        $dst  = imagecreatetruecolor($out, $out);
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $out, $out, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out, $out, $side, $side);
        $saved = match ($ext) {
            'jpg'  => imagejpeg($dst, $destFull, 86),
            'png'  => imagepng($dst, $destFull, 6),
            'webp' => function_exists('imagewebp') ? imagewebp($dst, $destFull, 85) : false,
        };
        imagedestroy($dst);
        imagedestroy($src);
    }
}

if (!$saved && !move_uploaded_file($tmp, $destFull)) {
    jsonOut(['ok' => false, 'error' => 'Could not save the uploaded image.'], 500);
}

$relPath = AVATAR_DIR_REL . '/' . $filename;
db()->prepare('UPDATE users SET profile_picture = ? WHERE id = ?')->execute([$relPath, (int)$user['id']]);
$deleteOld($oldPath);

jsonOut([
    'ok'      => true,
    'url'     => $relPath,
    'message' => 'Profile picture updated.',
]);
