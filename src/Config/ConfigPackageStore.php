<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Saved config packages on disk (storage/config-packages/).
 */
final class ConfigPackageStore
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function directory(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config-packages';
    }

    public function inboxDirectory(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'config-sync-inbox';
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->directory(), $this->inboxDirectory()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create directory: ' . $dir);
            }
        }
    }

    /**
     * @return list<array{basename: string, path: string, label: string, exported_at: string, package_id: string}>
     */
    public function listSaved(): array
    {
        $this->ensureDirectories();
        $dir = $this->directory();
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $basename = basename($path, '.json');
            $meta = $this->readMeta($path);
            $out[] = [
                'basename' => $basename,
                'path' => $path,
                'label' => $meta['label'],
                'exported_at' => $meta['exported_at'],
                'package_id' => $meta['package_id'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['exported_at'], $a['exported_at']));

        return $out;
    }

    /**
     * @param array<string, mixed> $document full config package document
     */
    public function save(string $basename, array $document): string
    {
        $this->ensureDirectories();
        $basename = $this->sanitizeBasename($basename);
        $path = $this->directory() . DIRECTORY_SEPARATOR . $basename . '.json';
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Could not write config package.');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFile(string $basename): array
    {
        $path = $this->directory() . DIRECTORY_SEPARATOR . $this->sanitizeBasename($basename) . '.json';
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Config package not found.');
        }

        return $this->decodeFile($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Could not read file.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON root must be an object.');
        }

        return $data;
    }

    /**
     * Store upload for preview; returns preview token (filename without path).
     */
    public function storeInbox(array $document): string
    {
        $this->ensureDirectories();
        $token = bin2hex(random_bytes(16));
        $path = $this->inboxDirectory() . DIRECTORY_SEPARATOR . $token . '.json';
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Could not stage config package.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadInbox(string $token): array
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if (strlen($token) !== 32) {
            throw new \InvalidArgumentException('Invalid preview token.');
        }
        $path = $this->inboxDirectory() . DIRECTORY_SEPARATOR . $token . '.json';
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Preview expired or not found.');
        }

        return $this->decodeFile($path);
    }

    public function deleteInbox(string $token): void
    {
        $token = preg_replace('/[^a-f0-9]/', '', strtolower($token)) ?? '';
        if ($token === '') {
            return;
        }
        $path = $this->inboxDirectory() . DIRECTORY_SEPARATOR . $token . '.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function sanitizeBasename(string $basename): string
    {
        $basename = strtolower(trim($basename));
        $basename = preg_replace('/[^a-z0-9\-]+/', '-', $basename) ?? '';
        $basename = trim($basename, '-');

        return $basename !== '' ? $basename : 'config-package';
    }

    /**
     * @return array{label: string, exported_at: string, package_id: string}
     */
    private function readMeta(string $path): array
    {
        try {
            $data = $this->decodeFile($path);
        } catch (\Throwable) {
            return ['label' => basename($path), 'exported_at' => '', 'package_id' => ''];
        }

        return [
            'label' => (string) ($data['label'] ?? basename($path)),
            'exported_at' => (string) ($data['exported_at'] ?? ''),
            'package_id' => (string) ($data['package_id'] ?? ''),
        ];
    }
}
