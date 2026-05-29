<?php

declare(strict_types=1);

namespace App\Media;

use PDO;

final class MediaRepository
{
    private const TABLE = 'cms_media';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Media
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, original_name, mime_type, extension, file_size, path, alt_text, title, caption,
                    width, height, uploaded_by, created_at, updated_at
             FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Media::fromRow($row);
    }

    public function existsId(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function isImageId(int $id): bool
    {
        $m = $this->findById($id);

        return $m !== null && str_starts_with($m->mimeType, 'image/');
    }

    /**
     * @return int new id
     */
    public function insert(
        string $filename,
        string $originalName,
        string $mimeType,
        string $extension,
        int $fileSize,
        string $path,
        ?int $width,
        ?int $height,
        ?int $uploadedBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (filename, original_name, mime_type, extension, file_size, path, width, height, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$filename, $originalName, $mimeType, $extension, $fileSize, $path, $width, $height, $uploadedBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateMetadata(int $id, ?string $altText, ?string $title, ?string $caption): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET alt_text = ?, title = ?, caption = ? WHERE id = ?'
        );
        $stmt->execute([$altText, $title, $caption, $id]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @return list<array{id: int, filename: string, original_name: string, mime_type: string, path: string, public_url: string}>
     */
    public function listImagesForPicker(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, filename, original_name, mime_type, path FROM ' . self::TABLE
            . " WHERE mime_type LIKE 'image/%' ORDER BY created_at DESC LIMIT " . $limit;
        $stmt = $this->pdo->query($sql);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = (string) $row['path'];
            $safe = MediaStorage::isSafeManagedWebPath($path) ? $path : '';
            $out[] = [
                'id' => (int) $row['id'],
                'filename' => (string) $row['filename'],
                'original_name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'path' => $path,
                'public_url' => $safe,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchPaginated(string $search, int $page, int $perPage, string $sort = MediaLibraryListOptions::SORT_NEWEST): array
    {
        $opts = new MediaLibraryListOptions($sort, $perPage);
        $page = max(1, $page);
        $perPage = $opts->perPage;
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        $q = trim($search);
        if ($q !== '') {
            $where = ' WHERE m.filename LIKE ? OR m.original_name LIKE ?';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }

        $sql = 'SELECT m.id, m.filename, m.original_name, m.mime_type, m.extension, m.file_size, m.path,
                       m.width, m.height, m.created_at, u.email AS uploader_email
                FROM ' . self::TABLE . ' m
                LEFT JOIN cms_users u ON u.id = m.uploaded_by'
            . $where
            . ' ORDER BY ' . $opts->orderBySql()
            . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function countSearch(string $search): int
    {
        $where = '';
        $params = [];
        $q = trim($search);
        if ($q !== '') {
            $where = ' WHERE filename LIKE ? OR original_name LIKE ?';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{total_files: int, total_bytes: int, image_files: int}
     */
    public function libraryStats(): array
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(*) AS total_files,
                    COALESCE(SUM(file_size), 0) AS total_bytes,
                    SUM(CASE WHEN mime_type LIKE \'image/%\' THEN 1 ELSE 0 END) AS image_files
             FROM ' . self::TABLE
        );
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'total_files' => (int) ($row['total_files'] ?? 0),
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'image_files' => (int) ($row['image_files'] ?? 0),
        ];
    }
}
