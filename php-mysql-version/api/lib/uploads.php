<?php
declare(strict_types=1);

const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

// Elmenti a $_FILES[$field] feltöltést az uploads/ mappába, és a relatív
// elérési utat adja vissza (pl. "uploads/xxxxx.jpg"), vagy null-t, ha nem
// volt (érvényes) feltöltés.
function save_uploaded_image(string $field): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_response('Image upload failed');
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        error_response('Image too large (max 5MB)');
    }

    // Nincs explicit finfo_close(): a finfo-erőforrás/objektum PHP 7.4-8.5+
    // között egyaránt automatikusan felszabadul, a close hívás PHP 8.5-től
    // deprecated.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);

    if (!isset(ALLOWED_IMAGE_TYPES[$mime])) {
        error_response('Unsupported image type');
    }

    $ext = ALLOWED_IMAGE_TYPES[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $uploadsDir = __DIR__ . '/../../uploads';
    if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

    $destination = $uploadsDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_response('Could not save the uploaded image', 500);
    }

    return 'uploads/' . $filename;
}
