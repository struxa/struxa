<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Filter\FilterHook;

/**
 * Enforces declared plugin.json capabilities and hook contracts at boot time.
 *
 * Plugins with no capabilities and no declared hooks remain in legacy permissive mode.
 */
final class PluginCapabilityGuard
{
    public function __construct(
        private readonly PluginManifest $manifest,
    ) {
    }

    public function isLegacyPermissive(): bool
    {
        return $this->manifest->capabilities === []
            && $this->manifest->hookFilters === []
            && $this->manifest->hookEvents === [];
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->manifest->capabilities, true);
    }

    public function assertCapability(string $capability): void
    {
        if ($this->isLegacyPermissive()) {
            return;
        }
        if (!$this->has($capability)) {
            throw new PluginCapabilityException(
                'Plugin "' . $this->manifest->slug . '" requires capability "' . $capability . '" in plugin.json.'
            );
        }
    }

    public function assertFilterRegistration(string $hook): void
    {
        if (!FilterHook::isValid($hook)) {
            throw new PluginCapabilityException(
                'Filter hook "' . $hook . '" is not registered in Struxa. Use constants from ' . FilterHook::class . '.'
            );
        }

        if ($this->manifest->hookFilters !== [] && !in_array($hook, $this->manifest->hookFilters, true)) {
            throw new PluginCapabilityException(
                'Plugin "' . $this->manifest->slug . '" must declare filter "' . $hook . '" in plugin.json hooks.filters before registering it.'
            );
        }

        if ($this->isLegacyPermissive()) {
            return;
        }

        $required = FilterHook::requiredCapability($hook);
        if ($required !== null) {
            $this->assertCapability($required);
        }
    }

    /**
     * @param class-string $eventClass
     */
    public function assertEventRegistration(string $eventClass, string $declaredName): void
    {
        if ($this->manifest->hookEvents !== [] && !in_array($declaredName, $this->manifest->hookEvents, true)) {
            throw new PluginCapabilityException(
                'Plugin "' . $this->manifest->slug . '" must declare event "' . $declaredName . '" in plugin.json hooks.events before listening.'
            );
        }

        if ($this->isLegacyPermissive()) {
            return;
        }

        $required = PluginKnownEvents::requiredCapability($declaredName);
        if ($required !== null) {
            $this->assertCapability($required);
        }
    }

    public function assertPublicRoutes(): void
    {
        $this->assertCapability(PluginCapability::FRONTEND_RENDER);
    }

    public function assertAdminRoutes(): void
    {
        $this->assertCapability(PluginCapability::ADMIN_NAV);
    }
}
