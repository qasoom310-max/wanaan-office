<?php

namespace App\Office\Erp;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads the Wanaan ERP through its read-only office API (/api/office/v1). The ERP
 * itself refuses anything but reading; this client only ever sends GET.
 */
class ErpClient
{
    public const URL = 'erp_url';

    public const KEY = 'erp_key';

    public const WORKSPACES = 'erp_workspaces';

    public function isConnected(): bool
    {
        return filled($this->url()) && filled($this->key()) && $this->workspaceIds() !== [];
    }

    /**
     * The company databases the office may read, as the owner set them.
     *
     * @return list<int>
     */
    public function workspaceIds(): array
    {
        $raw = (string) Setting::read(self::WORKSPACES);

        return array_values(array_unique(array_map('intval', array_filter(explode(',', $raw), 'ctype_digit'))));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws ErpProblem
     */
    public function get(string $path, int $workspace, array $query = []): array
    {
        if (! in_array($workspace, $this->workspaceIds(), true)) {
            throw new ErpProblem("company {$workspace} is not one the office may read");
        }

        try {
            $response = Http::withToken((string) $this->key())
                ->withHeaders(['X-Workspace' => (string) $workspace])
                ->acceptJson()
                ->timeout(30)
                ->get(rtrim((string) $this->url(), '/').'/api/office/v1/'.ltrim($path, '/'), array_filter($query, fn ($value): bool => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            throw new ErpProblem('the ERP could not be reached');
        }

        if (! $response->successful()) {
            $reason = $response->json('error');
            throw new ErpProblem(match ($response->status()) {
                401 => 'the ERP refused the office key',
                403 => 'the ERP does not let the office read that company',
                422 => 'the ERP did not accept the question: '.(is_string($reason) ? $reason : 'invalid filter'),
                default => 'the ERP answered with an error ('.$response->status().')',
            });
        }

        return (array) $response->json();
    }

    private function url(): ?string
    {
        return Setting::read(self::URL);
    }

    private function key(): ?string
    {
        return Setting::read(self::KEY);
    }
}
