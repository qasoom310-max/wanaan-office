<?php

namespace App\Office\Seo;

use App\Models\Setting;
use App\Office\Erp\ErpProblem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads the Qassim SEO system through its read-only office API (/api/office/v1).
 */
class SeoClient
{
    public const URL = 'seo_url';

    public const KEY = 'seo_key';

    public function isConnected(): bool
    {
        return filled(Setting::read(self::URL)) && filled(Setting::read(self::KEY));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws ErpProblem
     */
    public function get(string $path, array $query = []): array
    {
        try {
            $response = Http::withToken((string) Setting::read(self::KEY))
                ->acceptJson()
                ->timeout(30)
                ->get(rtrim((string) Setting::read(self::URL), '/').'/api/office/v1/'.ltrim($path, '/'), array_filter($query, fn ($value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            throw new ErpProblem('the SEO system could not be reached');
        }

        if (! $response->successful()) {
            throw new ErpProblem(match ($response->status()) {
                401 => 'the SEO system refused the office key',
                403 => 'the SEO system does not let the office read that project',
                404 => 'the SEO system has no such item',
                default => 'the SEO system answered with an error ('.$response->status().')',
            });
        }

        return (array) $response->json();
    }
}
