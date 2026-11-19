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
     * The tools for the ERP sections an agent may read; every agent with the ERP also
     * gets the company list, the staff names and the activity log.
     *
     * @param  list<string>  $sections  accounting, limousine, rental
     * @return list<AgentTool>
     */
    public function forSections(array $sections): array
    {
        $groups = array_filter($this->all(), fn (string $section): bool => $section === 'common' || in_array($section, $sections, true), ARRAY_FILTER_USE_KEY);

        return array_merge(...array_values($groups));
    }

    /**
     * @return array<string, list<AgentTool>>
     */
    public function all(): array
    {
        $company = ['type' => 'integer', 'description' => 'The company id, from erp_companies.'];
        $from = ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (optional).'];
        $to = ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD, included (optional).'];

        $day = ['from' => $from, 'to' => $to];

        return [
            'common' => [
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
            ],
            'accounting' => [
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
            ],
            'limousine' => [
                new AgentTool(
                    'erp_limousine_trips',
                    'Limousine trips of one company by start day: reference, booking, customer, from/to, start time, status (queue, confirmed, active, completed, cancelled), car, driver, price, driver payment, outside company and its cost, cancellation reason and refund.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day,
                        'status' => ['type' => 'string', 'enum' => ['queue', 'confirmed', 'active', 'completed', 'cancelled'], 'description' => 'Only trips in this status (optional).'],
                    ], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: limousine trips of '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->pages('limousine/trips', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'status' => $in['status'] ?? null])),
                ),
                new AgentTool(
                    'erp_limousine_invoices',
                    'Limousine invoices of one company by issue day: customer, booking, total, paid, balance, status (unpaid, partial, paid) and days overdue.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day,
                        'status' => ['type' => 'string', 'enum' => ['unpaid', 'partial', 'paid'], 'description' => 'Only invoices in this status (optional).'],
                    ], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: limousine invoices of '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->pages('limousine/invoices', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'status' => $in['status'] ?? null])),
                ),
                new AgentTool(
                    'erp_limousine_expenses',
                    'Limousine section expenses of one company by day: category, amount, VAT, payee, driver, and whether a bill is attached.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: limousine expenses of '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->pages('limousine/expenses', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null])),
                ),
            ],
            'money' => [
                new AgentTool(
                    'erp_money_in',
                    'Money that came in to one company over a period (at most 93 days): limousine and rent-a-car receipts, with customer, invoice, method and whether each was confirmed. from and to are required.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id', 'from', 'to']],
                    fn (array $in): string => 'Opening the ERP: money in for '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->erp->get('money/in', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null])),
                ),
                new AgentTool(
                    'erp_money_out',
                    'Money that went out of one company over a period (at most 93 days): company expenses, purchases, limousine expenses (and which have no bill), petty-cash advances and payroll as a monthly total. from and to are required.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id', 'from', 'to']],
                    fn (array $in): string => 'Opening the ERP: money out for '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->erp->get('money/out', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null])),
                ),
            ],
            'rental' => [
                new AgentTool(
                    'erp_rental_orders',
                    'Rent-a-car rentals of one company that overlap the days asked (a rental running across the period counts): customer, car, dates, state (draft, active, closed, cancelled), price, balance, payment, deposit and what happened to it, kilometres, damage, fuel, extra charges, fines, and who made it.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day,
                        'state' => ['type' => 'string', 'enum' => ['draft', 'active', 'closed', 'cancelled'], 'description' => 'Only rentals in this state (optional).'],
                    ], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: rentals of '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->pages('rental/orders', (int) $in['company_id'], ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'state' => $in['state'] ?? null])),
                ),
                new AgentTool(
                    'erp_rental_fleet',
                    'The rent-a-car fleet of one company: every car with its state, prices, monthly target, kilometres, next service, and registration and insurance expiry.',
                    ['type' => 'object', 'properties' => ['company_id' => $company], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: the fleet of '.$this->companyName((int) $in['company_id']),
                    fn (array $in): string => $this->json($this->erp->get('rental/fleet', (int) $in['company_id'])['data'] ?? []),
                ),
            ],
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
