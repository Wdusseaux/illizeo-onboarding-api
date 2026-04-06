<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key (create or update).
     */
    public static function set(string $key, $value): static
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
