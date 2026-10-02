<?php

namespace NineteenNinetyFour\Ghostwriter;

use Statamic\Addons\Addon;
use Statamic\Contracts\Addons\Settings as AddonSettings;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Facades\Addon as Addons;

/**
 * Statamic's addon settings, with Ghostwriter's read as they apply: each
 * value config/ghostwriter.php (or .env) sets stands in for the one saved,
 * so the settings screen shows a locked field at the value that wins, not
 * at one that does nothing.
 *
 * What was saved is kept all the same: saving puts the stored value back
 * for each locked field (see ServiceProvider), so taking the line out of
 * the config brings the screen's own choice back. Other addons' settings
 * pass straight through.
 */
class ConfiguredSettingsRepository implements SettingsRepository
{
    public function __construct(private SettingsRepository $inner) {}

    public function make(Addon $addon, array $settings = []): AddonSettings
    {
        return $this->inner->make($addon, $settings);
    }

    public function find(string $addon): ?AddonSettings
    {
        $found = $this->inner->find($addon);

        if ($addon !== Settings::ADDON || ($configured = app(Settings::class)->configured()) === []) {
            return $found;
        }

        $model = $found?->addon() ?? Addons::get($addon);

        return $model ? $this->inner->make($model, array_merge($found?->raw() ?? [], $configured)) : $found;
    }

    /**
     * An addon's settings as saved, without the config laid over them.
     *
     * @return array<string, mixed>
     */
    public function stored(string $addon): array
    {
        return $this->inner->find($addon)?->raw() ?? [];
    }

    public function save(AddonSettings $settings): bool
    {
        return $this->inner->save($settings);
    }

    public function delete(AddonSettings $settings): bool
    {
        return $this->inner->delete($settings);
    }
}
