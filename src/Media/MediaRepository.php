<?php

declare(strict_types=1);

namespace App\Media;

use PDO;

final class MediaRepository
{
    private const TABLE = 'cms_media';
    private const NOT_TRASHED = 'deleted_at IS NULL';
    private const SELECT_COLS = 'id, filename, original_name, mime_type, extension, file_size, path, alt_text, title, caption,
                    width, height, uploaded_by, folder_id, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Media
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLS . ' FROM ' . self::TABLE . ' WHERE id = ? AND ' . self::NOT_TRASHED . ' LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Media::fromRow($row);
    }

    public function existsId(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND ' . self::NOT_TRASHED . ' LIMIT 1');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function isImageId(int $id): bool
    {
        $m = $this->findById($id);

        return $m !== null && str_starts_with($m->mimeType, 'image/');
    }

    /**
     * Resolve a managed uploads web path to a media library id (0 when unknown).
     */
    public function findIdByWebPath(string $webPath): int
    {
        $webPath = trim($webPath);
        if ($webPath === '' || !MediaStorage::isSafeManagedWebPath($webPath)) {
            return 0;
        }

        $candidates = [$webPath];
        if (str_starts_with($webPath, '/')) {
            $candidates[] = ltrim($webPath, '/');
        } else {
            $candidates[] = '/' . $webPath;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . self::TABLE . ' WHERE path = ? AND ' . self::NOT_TRASHED . ' LIMIT 1'
        );

        foreach (array_unique($candidates) as $path) {
            $stmt->execute([$path]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return 0;
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
        ?int $uploadedBy,
        ?int $folderId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (filename, original_name, mime_type, extension, file_size, path, width, height, uploaded_by, folder_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$filename, $originalName, $mimeType, $extension, $fileSize, $path, $width, $height, $uploadedBy, $folderId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateMetadata(int $id, ?string $altText, ?string $title, ?string $caption): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET alt_text = ?, title = ?, caption = ? WHERE id = ?'
        );
        $stmt->execute([$altText, $title, $caption, $id]);
    }

    public function updateFolderId(int $id, ?int $folderId): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET folder_id = ? WHERE id = ?');
        $stmt->execute([$folderId, $id]);
    }

    /**
     * @param list<int|string> $ids
     */
    public function moveManyToFolder(array $ids, ?int $folderId): int
    {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $clean[$n] = true;
            }
        }
        if ($clean === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $params = array_merge([$folderId], array_map('intval', array_keys($clean)));
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET folder_id = ? WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function updateFileRecord(
        int $id,
        string $filename,
        string $mimeType,
        string $extension,
        int $fileSize,
        ?int $width,
        ?int $height,
        string $path
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET filename = ?, mime_type = ?, extension = ?, file_size = ?,
             width = ?, height = ?, path = ? WHERE id = ?'
        );
        $stmt->execute([$filename, $mimeType, $extension, $fileSize, $width, $height, $path, $id]);
    }

    /**
     * @return list<Media>
     */
    public function listImagesAfterId(int $afterId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT_COLS . ' FROM ' . self::TABLE . "
             WHERE mime_type LIKE 'image/%' AND id > ? AND deleted_at IS NULL
             ORDER BY id ASC
             LIMIT " . $limit
        );
        $stmt->execute([max(0, $afterId)]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = Media::fromRow($row);
        }

        return $out;
    }

    public function trash(int $id, ?int $deletedBy = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NOW(6), deleted_by = ? WHERE id = ? AND ' . self::NOT_TRASHED
        );
        $stmt->execute([$deletedBy, $id]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTrashed(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, original_name, mime_type, deleted_at FROM ' . self::TABLE
            . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT ' . $limit
        );
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function countTrashed(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL')->fetchColumn();
    }

    public function isTrashed(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function pathForTrashedId(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT path FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([$id]);
        $path = $stmt->fetchColumn();

        return $path === false ? null : (string) $path;
    }

    /**
     * @return list<array{id: int, filename: string, original_name: string, mime_type: string, path: string, public_url: string}>
     */
    public function listImagesForPicker(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, filename, original_name, mime_type, path FROM ' . self::TABLE
            . " WHERE mime_type LIKE 'image/%' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT " . $limit;
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
    public function searchPaginated(
        string $search,
        int $page,
        int $perPage,
        string $sort = MediaLibraryListOptions::SORT_NEWEST,
        ?MediaFolderFilter $folderFilter = null,
    ): array {
        $opts = new MediaLibraryListOptions($sort, $perPage);
        $page = max(1, $page);
        $perPage = $opts->perPage;
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildListWhere($search, $folderFilter);

        $sql = 'SELECT m.id, m.filename, m.original_name, m.mime_type, m.extension, m.file_size, m.path,
                       m.width, m.height, m.folder_id, m.created_at, u.email AS uploader_email
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

    public function countSearch(string $search, ?MediaFolderFilter $folderFilter = null): int
    {
        [$where, $params] = $this->buildListWhere($search, $folderFilter, '');

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
             FROM ' . self::TABLE . ' WHERE deleted_at IS NULL'
        );
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'total_files' => (int) ($row['total_files'] ?? 0),
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'image_files' => (int) ($row['image_files'] ?? 0),
        ];
    }

    /**
     * Staff search — filename and original name only.
     *
     * @return list<array<string, mixed>>
     */
    public function adminSearchLike(string $likeParam, int $limit = 25): array
    {
        if ($likeParam === '' || $likeParam === '%%') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, original_name, mime_type, file_size, created_at
             FROM ' . self::TABLE . '
             WHERE deleted_at IS NULL
               AND (filename LIKE ? ESCAPE \'\\\\\' OR original_name LIKE ? ESCAPE \'\\\\\')
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$likeParam, $likeParam]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildListWhere(string $search, ?MediaFolderFilter $folderFilter, string $alias = 'm'): array
    {
        $clauses = [];
        $params = [];

        $deletedCol = $alias !== '' ? $alias . '.deleted_at IS NULL' : 'deleted_at IS NULL';
        $clauses[] = $deletedCol;

        $q = trim($search);
        if ($q !== '') {
            if ($alias !== '') {
                $clauses[] = '(' . $alias . '.filename LIKE ? OR ' . $alias . '.original_name LIKE ?)';
            } else {
                $clauses[] = '(filename LIKE ? OR original_name LIKE ?)';
            }
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }

        if ($folderFilter !== null) {
            $col = $alias !== '' ? $alias . '.folder_id' : 'folder_id';
            if ($folderFilter->mode === MediaFolderFilter::MODE_UNFILED) {
                $clauses[] = $col . ' IS NULL';
            } elseif ($folderFilter->mode === MediaFolderFilter::MODE_FOLDER && $folderFilter->folderId !== null) {
                $clauses[] = $col . ' = ?';
                $params[] = $folderFilter->folderId;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }
}
