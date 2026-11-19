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
    private const MAX_ROWS = 10000;

    /** Characters of lines passed back at most; the totals always cover every line. */
    private const MAX_TABLE = 220000;

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
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'action' => $in['action'] ?? null];
                        $list = $this->pages('activity', (int) $in['company_id'], $q);

                        return $this->report('activity', (int) $in['company_id'], $q, $list, ['by_action' => self::by($list['rows'], 'action'), 'by_person' => self::by($list['rows'], 'user_name')], 'at');
                    },
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
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'state' => $in['state'] ?? null];
                        $list = $this->pages('accounting/journal-entries', (int) $in['company_id'], $q);
                        $unbalanced = array_values(array_filter($list['rows'], fn (array $r): bool => ! ($r['balanced'] ?? true)));

                        return $this->report('journal entries', (int) $in['company_id'], $q, $list, [
                            ...self::sums($list['rows'], ['total_debit', 'total_credit']),
                            'by_state' => self::by($list['rows'], 'state', ['total_debit']),
                            'by_person' => self::by($list['rows'], 'user_name', ['total_debit']),
                            'not_balancing' => array_column($unbalanced, 'number'),
                        ], 'date');
                    },
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
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'status' => $in['status'] ?? null];
                        $list = $this->pages('limousine/trips', (int) $in['company_id'], $q);
                        $money = ['line_total', 'net_amount', 'driver_payment', 'outside_cost', 'refund_amount'];

                        return $this->report('limousine trips', (int) $in['company_id'], $q, $list, [
                            ...self::sums($list['rows'], $money),
                            'by_status' => self::by($list['rows'], 'status', ['net_amount']),
                            'by_driver' => self::by($list['rows'], 'driver', ['net_amount', 'driver_payment']),
                            'by_car' => self::by($list['rows'], 'car', ['net_amount']),
                            'top_customers' => self::by($list['rows'], 'customer', ['net_amount'], 20),
                            'by_booking_payment' => self::by($list['rows'], 'booking_payment', ['net_amount']),
                            'outside_company' => self::by(array_values(array_filter($list['rows'], fn (array $r): bool => filled($r['outside_company'] ?? null))), 'outside_company', ['outside_cost']),
                        ], 'start_at');
                    },
                ),
                new AgentTool(
                    'erp_limousine_invoices',
                    'Limousine invoices of one company by issue day: customer, booking, total, paid, balance, status (unpaid, partial, paid) and days overdue.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day,
                        'status' => ['type' => 'string', 'enum' => ['unpaid', 'partial', 'paid'], 'description' => 'Only invoices in this status (optional).'],
                    ], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: limousine invoices of '.$this->companyName((int) $in['company_id']),
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'status' => $in['status'] ?? null];
                        $list = $this->pages('limousine/invoices', (int) $in['company_id'], $q);
                        $overdue = array_values(array_filter($list['rows'], fn (array $r): bool => ($r['days_overdue'] ?? 0) > 0));

                        return $this->report('limousine invoices', (int) $in['company_id'], $q, $list, [
                            ...self::sums($list['rows'], ['total', 'paid', 'balance']),
                            'by_status' => self::by($list['rows'], 'status', ['total', 'balance']),
                            'overdue' => ['count' => count($overdue), ...self::sums($overdue, ['balance'])],
                            'top_owing_customers' => self::by(array_values(array_filter($list['rows'], fn (array $r): bool => ($r['balance'] ?? 0) > 0)), 'customer', ['balance'], 20),
                        ], 'issue_date');
                    },
                ),
                new AgentTool(
                    'erp_limousine_expenses',
                    'Limousine section expenses of one company by day: category, amount, VAT, payee, driver, and whether a bill is attached.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id']],
                    fn (array $in): string => 'Opening the ERP: limousine expenses of '.$this->companyName((int) $in['company_id']),
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null];
                        $list = $this->pages('limousine/expenses', (int) $in['company_id'], $q);
                        $noBill = array_values(array_filter($list['rows'], fn (array $r): bool => ! ($r['has_bill'] ?? true)));

                        return $this->report('limousine expenses', (int) $in['company_id'], $q, $list, [
                            ...self::sums($list['rows'], ['amount', 'vat_amount']),
                            'by_category' => self::by($list['rows'], 'category', ['amount']),
                            'by_driver' => self::by($list['rows'], 'driver', ['amount']),
                            'without_bill' => ['count' => count($noBill), ...self::sums($noBill, ['amount'])],
                        ], 'date');
                    },
                ),
            ],
            'money' => [
                new AgentTool(
                    'erp_money_in',
                    'Money that came in to one company over a period (at most 93 days): limousine and rent-a-car receipts, with customer, invoice, method and whether each was confirmed. from and to are required.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id', 'from', 'to']],
                    fn (array $in): string => 'Opening the ERP: money in for '.$this->companyName((int) $in['company_id']),
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null];
                        $rows = $this->erp->get('money/in', (int) $in['company_id'], $q)['data'] ?? [];

                        return $this->report('money in', (int) $in['company_id'], $q, ['rows' => $rows, 'complete' => true], [
                            ...self::sums($rows, ['amount']), 'by_source' => self::by($rows, 'source', ['amount']), 'by_method' => self::by($rows, 'method', ['amount']),
                            'not_confirmed' => self::by(array_values(array_filter($rows, fn (array $r): bool => ($r['confirmed'] ?? null) === false)), 'source', ['amount']),
                        ], 'date');
                    },
                ),
                new AgentTool(
                    'erp_money_out',
                    'Money that went out of one company over a period (at most 93 days): company expenses, purchases, limousine expenses (and which have no bill), petty-cash advances and payroll as a monthly total. from and to are required.',
                    ['type' => 'object', 'properties' => ['company_id' => $company, ...$day], 'required' => ['company_id', 'from', 'to']],
                    fn (array $in): string => 'Opening the ERP: money out for '.$this->companyName((int) $in['company_id']),
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null];
                        $rows = $this->erp->get('money/out', (int) $in['company_id'], $q)['data'] ?? [];

                        return $this->report('money out', (int) $in['company_id'], $q, ['rows' => $rows, 'complete' => true], [
                            ...self::sums($rows, ['amount']), 'by_source' => self::by($rows, 'source', ['amount']), 'by_category' => self::by($rows, 'category', ['amount']), 'top_payees' => self::by($rows, 'payee', ['amount'], 25),
                        ], 'date');
                    },
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
                    function (array $in): string {
                        $q = ['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'state' => $in['state'] ?? null];
                        $list = $this->pages('rental/orders', (int) $in['company_id'], $q);
                        $held = array_values(array_filter($list['rows'], fn (array $r): bool => ($r['deposit_status'] ?? null) === 'held'));

                        return $this->report('rentals', (int) $in['company_id'], $q, $list, [
                            ...self::sums($list['rows'], ['total', 'balance', 'deposit', 'deposit_deducted', 'fines_amount', 'extra_charge']),
                            'by_state' => self::by($list['rows'], 'state', ['total', 'balance']),
                            'by_payment' => self::by($list['rows'], 'payment_status', ['balance']),
                            'deposits_held' => ['count' => count($held), ...self::sums($held, ['deposit'])],
                            'with_damage' => count(array_filter($list['rows'], fn (array $r): bool => (bool) ($r['has_damage'] ?? false))),
                            'by_car' => self::by($list['rows'], 'car', ['total'], 30),
                            'by_staff' => self::by($list['rows'], 'created_by'),
                        ], 'start_date');
                    },
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
     * Read every page of a list (up to MAX_ROWS lines).
     *
     * @param  array<string, scalar|null>  $query
     * @return array{rows: list<array<string, mixed>>, complete: bool}
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

        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'complete' => $after === null];
    }

    /**
     * What an agent reads back for a list: the totals worked out over EVERY line first,
     * then the lines themselves as a compact table, sorted, cut only by length and always
     * saying exactly how many were shown. So a long period never gives wrong totals.
     *
     * @param  array{rows: list<array<string, mixed>>, complete: bool}  $list
     * @param  array<string, mixed>  $totals
     */
    private function report(string $what, int $company, array $query, array $list, array $totals, ?string $sortBy = null): string
    {
        $rows = $list['rows'];
        if ($sortBy !== null) {
            usort($rows, fn (array $a, array $b): int => strcmp((string) ($a[$sortBy] ?? ''), (string) ($b[$sortBy] ?? '')));
        }
        $count = count($rows);
        $period = trim(($query['from'] ?? '').' to '.($query['to'] ?? ''), ' to');

        $out = ["ERP: {$what} of ".$this->companyName($company).($period !== '' ? ", {$period}" : '').'.'];
        $out[] = $list['complete']
            ? "Lines: {$count}, every one read."
            : "Lines: the first {$count} were read and there are MORE: ask for a shorter period to see the rest.";
        $out[] = "TOTALS (worked out over all {$count} lines read): ".$this->json($totals);

        if ($count === 0) {
            return implode("\n", $out);
        }

        $columns = array_keys($rows[0]);
        $lines = [implode("\t", $columns)];
        $used = 0;
        $shown = 0;
        foreach ($rows as $row) {
            $line = implode("\t", array_map(fn ($value): string => is_array($value) ? $this->json($value) : str_replace(["\t", "\n", "\r"], ' ', (string) ($value === null ? '' : (is_bool($value) ? ($value ? 'yes' : 'no') : $value))), array_map(fn (string $c) => $row[$c] ?? null, $columns)));
            if ($used + mb_strlen($line) > self::MAX_TABLE) {
                break;
            }
            $lines[] = $line;
            $used += mb_strlen($line) + 1;
            $shown++;
        }

        $out[] = 'LINES (tab-separated'.($sortBy !== null ? ", sorted by {$sortBy}" : '').'):';
        $out[] = implode("\n", $lines);
        if ($shown < $count) {
            $last = $sortBy !== null ? ' (up to '.$sortBy.' '.($rows[$shown - 1][$sortBy] ?? '?').')' : '';
            $out[] = "[Lines 1-{$shown} of {$count} shown{$last}. The TOTALS above cover all {$count}. For the lines after that, ask for a shorter period.]";
        }

        return implode("\n", $out);
    }

    /**
     * Count and add up lines by one field.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $sum
     * @return array<string, array<string, float|int>>
     */
    private static function by(array $rows, string $field, array $sum = [], int $top = 0): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '(none)');
            $key = $key === '' ? '(none)' : $key;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
            foreach ($sum as $column) {
                $groups[$key][$column] = round(($groups[$key][$column] ?? 0) + (float) ($row[$column] ?? 0), 3);
            }
        }
        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $top > 0 ? array_slice($groups, 0, $top, true) : $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array<string, float>
     */
    private static function sums(array $rows, array $columns): array
    {
        $totals = [];
        foreach ($columns as $column) {
            $totals[$column] = round(array_sum(array_map(fn (array $row): float => (float) ($row[$column] ?? 0), $rows)), 3);
        }

        return $totals;
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
