<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Tracks plugin boot and hook timing for admin diagnostics.
 */
final class PluginPerformanceRegistry
{
    public const BOOT_SLOW_MS = 50.0;
    public const HOOK_SLOW_MS = 25.0;

    private static ?self $instance = null;

    private readonly PluginPerformanceStore $store;

    /** @var array<string, float> slug => ms */
    private array $requestBootMs = [];

    public static function configure(string $projectRoot): void
    {
        self::$instance = new self($projectRoot);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(dirname(__DIR__, 2));
    }

    public static function instanceOrNull(): ?self
    {
        return self::$instance;
    }

    private function __construct(private readonly string $projectRoot)
    {
        $this->store = new PluginPerformanceStore($projectRoot);
    }

    public function recordBoot(string $slug, float $ms, int $filterCount, int $eventCount): void
    {
        $this->requestBootMs[$slug] = $ms;
        $patch = [
            'last_boot_ms' => round($ms, 2),
            'last_boot_at' => gmdate('c'),
            'filter_count' => $filterCount,
            'event_count' => $eventCount,
            'boot_slow' => $ms >= self::BOOT_SLOW_MS,
        ];
        if ($ms >= self::BOOT_SLOW_MS) {
            error_log(sprintf('[plugin] Slow boot: %s took %.1f ms (threshold %.0f ms).', $slug, $ms, self::BOOT_SLOW_MS));
        }
        $this->store->merge($slug, $patch);
    }

    public function recordBootError(string $slug, \Throwable $e): void
    {
        $message = $e->getMessage();
        error_log('[plugin] Boot error for ' . $slug . ': ' . $message);
        $this->store->merge($slug, [
            'last_boot_error' => [
                'message' => $message,
                'class' => $e::class,
                'at' => gmdate('c'),
            ],
            'last_boot_at' => gmdate('c'),
        ]);
    }

    public function recordAutoDeactivated(string $slug): void
    {
        $this->store->merge($slug, [
            'auto_deactivated_at' => gmdate('c'),
        ]);
    }

    public function recordSkipped(string $slug, PluginLoadScope $scope): void
    {
        $this->store->merge($slug, [
            'last_skipped_scope' => $scope->value,
            'last_skipped_at' => gmdate('c'),
        ]);
    }

    public function recordHookCall(string $hook, float $ms, ?string $pluginSlug): void
    {
        if ($ms < self::HOOK_SLOW_MS) {
            return;
        }

        $slug = $pluginSlug ?? 'core';
        error_log(sprintf(
            '[plugin] Slow hook: %s via %s took %.1f ms (threshold %.0f ms).',
            $hook,
            $slug,
            $ms,
            self::HOOK_SLOW_MS,
        ));

        $this->store->merge($slug, [
            'slow_hooks' => [[
                'hook' => $hook,
                'ms' => round($ms, 2),
                'at' => gmdate('c'),
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshotForSlug(string $slug): ?array
    {
        $stored = $this->store->forSlug($slug);
        if ($stored === null && !isset($this->requestBootMs[$slug])) {
            return null;
        }

        $row = $stored ?? [];
        if (isset($this->requestBootMs[$slug])) {
            $row['request_boot_ms'] = $this->requestBootMs[$slug];
        }

        return $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allSnapshots(): array
    {
        return $this->store->all();
    }

    public function store(): PluginPerformanceStore
    {
        return $this->store;
    }

    public static function circuitBreakerEnabled(): bool
    {
        $raw = $_ENV['PLUGIN_BOOT_CIRCUIT_BREAKER'] ?? '0';

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }
}
