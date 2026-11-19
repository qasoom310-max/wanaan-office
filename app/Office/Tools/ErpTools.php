<?php

namespace App\Office\Tools;

use App\Office\Erp\ErpClient;
use App\Office\Erp\ErpProblem;

/**
 * The ERP as an agent can read it: which companies, who works there, what they did,
 * and the accounting entries. Read-only, like the ERP's office API itself.
 */
class ErpTools
{
    /** Rows read in one go, so a busy month cannot flood an answer. */
    private const MAX_ROWS = 1500;

    /** @var array<int, string> */
    private array $companyNames = [];

    public function __construct(private ErpClient $erp) {}

    /**
     * @return list<AgentTool>
     */
    public function all(): array
    {
        $company = ['type' => 'integer', 'description' => 'The company id, from erp_companies.'];
        $from = ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (optional).'];
        $to = ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD, included (optional).'];

        return [
            new AgentTool(
                'erp_companies',
                'List the companies (ERP workspaces) you may read, with each one\'s timezone, current time and whether it has accounting. Call this first.',
                ['type' => 'object', 'properties' => new \stdClass],
                fn (): string => 'Opening the ERP: companies',
                fn (): string => $this->json($this->companies()),
            ),
            new AgentTool(
                'erp_staff',
                'Everyone with an account in one company: id, name, admin, accountant, paused. Use it to put names to user ids.',
                ['type' => 'object', 'properties' => ['company_id' => $company], 'required' => ['company_id']],
                fn (array $in): string => 'Opening the ERP: staff of '.$this->companyName((int) $in['company_id']),
                fn (array $in): string => $this->json($this->erp->get('staff', (int) $in['company_id'])['data'] ?? []),
            ),
            new AgentTool(
                'erp_activity',
                'The audit trail of one company: who did what and when (created, updated, deleted, signed in…), oldest first. Filter by days and by action.',
                ['type' => 'object', 'properties' => [
                    'company_id' => $company, 'from' => $from, 'to' => $to,
                    'action' => ['type' => 'string', 'description' => 'Only this action, e.g. created, updated, deleted, login (optional).'],
                ], 'required' => ['company_id']],
                fn (array $in): string => 'Opening the ERP: activity of '.$this->companyName((int) $in['company_id']),
                fn (array $in): string => $this->json($this->pages('activity', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'action' => $in['action'] ?? null])),
            ),
            new AgentTool(
                'erp_journal_entries',
                'Accounting journal entries of one company by accounting date: number, date, reference, who made it, draft or posted, when it was created and posted, debit and credit totals and whether it balances.',
                ['type' => 'object', 'properties' => [
                    'company_id' => $company, 'from' => $from, 'to' => $to,
                    'state' => ['type' => 'string', 'enum' => ['draft', 'posted'], 'description' => 'Only drafts or only posted entries (optional).'],
                ], 'required' => ['company_id']],
                fn (array $in): string => 'Opening the ERP: journal entries of '.$this->companyName((int) $in['company_id']),
                fn (array $in): string => $this->json($this->pages('accounting/journal-entries', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'state' => $in['state'] ?? null])),
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function companies(): array
    {
        $companies = [];
        foreach ($this->erp->workspaceIds() as $id) {
            try {
                $info = $this->erp->get('workspace', $id);
                $this->companyNames[$id] = (string) ($info['name'] ?? "company {$id}");
                $companies[] = $info;
            } catch (ErpProblem $problem) {
                $companies[] = ['id' => $id, 'error' => $problem->getMessage()];
            }
        }

        return $companies;
    }

    private function companyName(int $id): string
    {
        return $this->companyNames[$id] ?? "company {$id}";
    }

    /**
     * Read every page of a list, up to the row limit, and say if it was cut short.
     *
     * @param  array<string, scalar|null>  $query
     * @return array{rows: list<mixed>, count: int, complete: bool}
     */
    private function pages(string $path, int $company, array $query): array
    {
        $rows = [];
        $after = 0;
        do {
            $page = $this->erp->get($path, $company, [...$query, 'after_id' => $after, 'limit' => 500]);
            array_push($rows, ...($page['data'] ?? []));
            $after = $page['next_after_id'] ?? null;
        } while ($after !== null && count($rows) < self::MAX_ROWS);

        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'count' => min(count($rows), self::MAX_ROWS), 'complete' => $after === null];
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
