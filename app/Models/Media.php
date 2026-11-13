<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Context;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * Media library: validated uploads, thumbnails, WebP derivatives, search.
 */
final class Media
{
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_DIMENSION = 2400;
    private const THUMB_WIDTH = 480;

    public static function all(array $filters = [], int $limit = 60, int $offset = 0): array
    {
        $sql = 'SELECT m.*, u.username FROM media m LEFT JOIN users u ON u.id = m.user_id WHERE m.blog_id = ?';
        $params = [Context::blogId()];
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $sql .= ' AND m.mime_type LIKE ?';
            $params[] = $filters['type'] . '%';
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (m.file_name LIKE ? OR m.alt LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['folder'])) {
            $sql .= ' AND m.folder = ?';
            $params[] = $filters['folder'];
        }
        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        return Database::fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM media WHERE blog_id = ?';
        $params = [Context::blogId()];
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $sql .= ' AND mime_type LIKE ?';
            $params[] = $filters['type'] . '%';
        }
        return (int)Database::value($sql, $params);
    }

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM media WHERE id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    /**
     * Store an uploaded image with MIME verification, size limits, optional
     * thumbnail and WebP derivative when GD support is available.
     */
    public static function upload(array $file, int $userId, array $options = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            throw new ValidationException('Pilih file gambar yang valid untuk diunggah.');
        }
        $maxMb = max(1, min(50, (int)Settings::get('media_max_mb', '5')));
        if ((int)($file['size'] ?? 0) > $maxMb * 1024 * 1024) {
            throw new ValidationException('Ukuran file maksimal ' . $maxMb . ' MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException('File upload tidak valid.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException('Hanya gambar JPG, PNG, GIF, atau WebP yang diperbolehkan.');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            throw new ValidationException('File bukan gambar yang valid.');
        }
        $extension = self::ALLOWED[$mime];
        $blogId = Context::blogId();
        $relative = 'uploads/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
        $absolute = CMS_ROOT . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0755, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Folder upload tidak dapat dibuat.');
        }
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            throw new \RuntimeException('File gagal disimpan ke folder media.');
        }
        @chmod($absolute, 0644);

        $checksum = hash_file('sha256', $absolute) ?: null;
        $thumbPath = self::createThumbnail($absolute, $mime);
        $webpPath = (bool)Settings::get('media_webp', '1') ? self::createWebp($absolute, $mime) : null;

        $id = Database::insert('media', [
            'blog_id' => $blogId,
            'user_id' => $userId,
            'file_name' => basename((string)($file['name'] ?? 'image.' . $extension)),
            'file_path' => $relative,
            'thumb_path' => $thumbPath,
            'webp_path' => $webpPath,
            'mime_type' => $mime,
            'extension' => $extension,
            'file_size' => (int)filesize($absolute),
            'width' => (int)$info[0],
            'height' => (int)$info[1],
            'alt' => trim((string)($options['alt'] ?? '')),
            'caption' => trim((string)($options['caption'] ?? '')) ?: null,
            'folder' => trim((string)($options['folder'] ?? '')),
            'checksum' => $checksum,
        ]);
        log_activity('media.upload', $relative);
        return self::find($id) ?? [];
    }

    private static function createThumbnail(string $absolute, string $mime): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $image = self::openImage($absolute, $mime);
        if (!$image) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= self::THUMB_WIDTH) {
            imagedestroy($image);
            return null;
        }
        $targetWidth = self::THUMB_WIDTH;
        $targetHeight = (int)round($height / $width * $targetWidth);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $path = preg_replace('/\.(jpg|jpeg|png|gif|webp)$/i', '-thumb.jpg', $absolute);
        $saved = imagejpeg($canvas, $path, 82);
        imagedestroy($canvas);
        imagedestroy($image);
        if (!$saved) {
            return null;
        }
        return ltrim(str_replace(CMS_ROOT . '/', '', $path), '/');
    }

    private static function createWebp(string $absolute, string $mime): ?string
    {
        if (!function_exists('imagewebp') || $mime === 'image/webp') {
            return null;
        }
        $image = self::openImage($absolute, $mime);
        if (!$image) {
            return null;
        }
        $path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $absolute);
        $saved = imagewebp($image, $path, 82);
        imagedestroy($image);
        if (!$saved) {
            return null;
        }
        return ltrim(str_replace(CMS_ROOT . '/', '', $path), '/');
    }

    private static function openImage(string $path, string $mime): mixed
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /**
     * Resize/crop a stored image into a new derivative.
     */
    public static function resize(int $id, int $width, int $height, bool $crop = false): string
    {
        $media = self::find($id);
        if (!$media) {
            throw new ValidationException('Media tidak ditemukan.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new ValidationException('Ekstensi GD tidak tersedia pada server.');
        }
        $source = CMS_ROOT . '/' . ltrim((string)$media['file_path'], '/');
        if (!is_file($source)) {
            throw new ValidationException('File media hilang dari disk.');
        }
        $image = self::openImage($source, (string)$media['mime_type']);
        if (!$image) {
            throw new ValidationException('Gambar tidak dapat dibaca.');
        }
        $width = max(16, min(4000, $width));
        $height = max(16, min(4000, $height));
        $srcWidth = imagesx($image);
        $srcHeight = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        if ((string)$media['mime_type'] === 'image/png') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
        if ($crop) {
            $ratio = max($width / $srcWidth, $height / $srcHeight);
            $newWidth = (int)($width / $ratio);
            $newHeight = (int)($height / $ratio);
            $x = (int)(($srcWidth - $newWidth) / 2);
            $y = (int)(($srcHeight - $newHeight) / 2);
            imagecopyresampled($canvas, $image, 0, 0, $x, $y, $width, $height, $newWidth, $newHeight);
        } else {
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);
        }
        $path = preg_replace('/\.(jpg|jpeg|png|gif|webp)$/i', '-' . $width . 'x' . $height . '.jpg', $source);
        imagejpeg($canvas, $path, 84);
        imagedestroy($canvas);
        imagedestroy($image);
        return ltrim(str_replace(CMS_ROOT . '/', '', $path), '/');
    }

    public static function updateMeta(int $id, array $data): void
    {
        Database::update('media', [
            'alt' => trim((string)($data['alt'] ?? '')) ?: null,
            'caption' => trim((string)($data['caption'] ?? '')) ?: null,
            'folder' => trim((string)($data['folder'] ?? '')),
        ], 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
    }

    /**
     * Replace the binary of an existing media record, keeping references intact.
     */
    public static function replace(int $id, array $file): void
    {
        $media = self::find($id);
        if (!$media) {
            throw new ValidationException('Media tidak ditemukan.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException('Pilih file pengganti yang valid.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException('Hanya gambar JPG, PNG, GIF, atau WebP.');
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new ValidationException('File bukan gambar yang valid.');
        }
        $relative = (string)$media['file_path'];
        $absolute = CMS_ROOT . '/' . ltrim($relative, '/');
        $backup = $absolute . '.bak';
        if (is_file($absolute)) {
            @copy($absolute, $backup);
        }
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            throw new \RuntimeException('File tidak dapat diganti.');
        }
        Database::update('media', [
            'mime_type' => $mime,
            'extension' => self::ALLOWED[$mime],
            'file_size' => (int)filesize($absolute),
            'width' => (int)$info[0],
            'height' => (int)$info[1],
            'checksum' => hash_file('sha256', $absolute) ?: null,
        ], 'id = ?', [$id]);
        log_activity('media.replace', $relative);
    }

    public static function delete(int $id): void
    {
        $media = self::find($id);
        if (!$media) {
            throw new ValidationException('Media tidak ditemukan.');
        }
        $paths = array_filter([
            (string)$media['file_path'],
            (string)($media['thumb_path'] ?? ''),
            (string)($media['webp_path'] ?? ''),
        ]);
        foreach ($paths as $relative) {
            $relative = ltrim($relative, '/');
            if (!str_starts_with($relative, 'uploads/')) {
                continue;
            }
            $absolute = realpath(CMS_ROOT . '/' . $relative);
            $root = realpath(CMS_ROOT . '/uploads');
            if ($absolute !== false && $root !== false && str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)) {
                @unlink($absolute);
            }
        }
        Database::delete('media', 'id = ? AND blog_id = ?', [$id, Context::blogId()]);
        log_activity('media.delete', (string)$media['file_path']);
    }

    public static function usage(int $id): int
    {
        $media = self::find($id);
        if (!$media) {
            return 0;
        }
        $path = (string)$media['file_path'];
        return (int)Database::value(
            'SELECT COUNT(*) FROM posts WHERE blog_id = ? AND (thumbnail LIKE ? OR body LIKE ? OR og_image LIKE ?)',
            [Context::blogId(), '%' . $path . '%', '%' . $path . '%', '%' . $path . '%']
        );
    }

    public static function stats(): array
    {
        $rows = Database::fetchAll(
            'SELECT mime_type, COUNT(*) AS total, SUM(file_size) AS bytes FROM media WHERE blog_id = ? GROUP BY mime_type',
            [Context::blogId()]
        );
        $total = 0;
        $bytes = 0;
        foreach ($rows as $row) {
            $total += (int)$row['total'];
            $bytes += (int)$row['bytes'];
        }
        return ['files' => $total, 'bytes' => $bytes, 'types' => $rows];
    }

    public static function publicUrl(array $media, bool $preferWebp = true): string
    {
        $path = (string)$media['file_path'];
        if ($preferWebp && !empty($media['webp_path'])) {
            $path = (string)$media['webp_path'];
        }
        return url($path);
    }
}
