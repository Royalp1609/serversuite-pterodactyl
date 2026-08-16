<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Panel-wide Mod Manager settings, editable from the admin page. Values here
 * OVERRIDE the corresponding config/.env entries (the service provider maps
 * them onto the config repository at boot), so config remains the single read
 * point for drivers and services.
 */
class ModManagerGlobalSetting extends Model
{
    protected $table = 'mod_manager_global_settings';

    protected $fillable = ['key', 'value'];

    /** All settings as key => value. */
    public static function allSettings(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row !== null ? $row->value : $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
