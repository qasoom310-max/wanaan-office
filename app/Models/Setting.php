<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings the owner enters on the Settings page. Secrets are stored encrypted with
 * the app key, so a copy of the database alone does not give them away.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const ANTHROPIC_API_KEY = 'anthropic_api_key';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function read(string $key): ?string
    {
        return self::find($key)?->value;
    }

    public static function write(string $key, ?string $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
