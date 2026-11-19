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

    /** The one project the office may write into (the new website), if any. */
    public const WRITE_PROJECT = 'seo_write_project';

    public function writeProject(): ?int
    {
        $id = Setting::read(self::WRITE_PROJECT);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Add or change something in the one writable project (POST or PATCH only).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ErpProblem
     */
    public function write(string $method, string $path, array $body): array
    {
        $project = $this->writeProject();
        if ($project === null) {
            throw new ErpProblem('the office has no project it may write into');
        }

        try {
            $response = Http::withToken((string) Setting::read(self::KEY))->acceptJson()->timeout(30)
                ->send($method, rtrim((string) Setting::read(self::URL), '/').'/api/office/v1/projects/'.$project.'/'.ltrim($path, '/'), ['json' => $body]);
        } catch (ConnectionException) {
            throw new ErpProblem('the SEO system could not be reached');
        }

        if ($response->status() === 409 || $response->status() === 422) {
            $errors = $response->json('errors');

            return ['refused' => $response->json('error') ?? (is_array($errors) ? implode(' ', array_merge(...array_values($errors))) : 'refused')];
        }
        if (! $response->successful()) {
            throw new ErpProblem(match ($response->status()) {
                401 => 'the SEO system refused the office key',
                403 => 'the office may not write in that project',
                default => 'the SEO system answered with an error ('.$response->status().')',
            });
        }

        return (array) $response->json();
    }

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
