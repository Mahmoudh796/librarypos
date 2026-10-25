<?php

namespace App\Commands;

use App\Libraries\Accounting\Ledger;
use App\Libraries\Accounting\Posting;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Posts documents created before the ledger existed.
 *
 * 1. Opening stock at the start date: quantity on hand at that moment (from the
 *    stock history) valued at the current average cost, against opening balance
 *    equity. Current cost is the best available figure; history did not keep cost.
 * 2. Completed sales, receivings, live expenses and closed cash-ups from the
 *    start date, in date order. Each posting is idempotent, so a rerun is safe.
 * 3. accounting_start_date is moved back to the start date.
 *
 * Posting into a closed period fails and is reported per document.
 */
class AccountingBackfill extends BaseCommand
{
    protected $group = 'Ru\'ya';
    protected $name = 'accounting:backfill';
    protected $description = 'Posts sales, receivings, expenses and cash-ups made before the ledger existed.';
    protected $usage = 'accounting:backfill [--since YYYY-MM-DD] [--dry-run]';
    protected $options = [
        '--since'   => 'First date to post (default: date of the oldest document).',
        '--dry-run' => 'Only count what would be posted.',
    ];

    public function run(array $params): void
    {
        // Entry descriptions are stored as text, so post them in the store's language;
        // a CLI run otherwise falls back to the framework default (English).
        helper('locale');
        Services::language()->setLocale(current_language_code(true));

        $db = Database::connect();
        $since = (string) (CLI::getOption('since') ?: $this->oldestDocumentDate($db));
        $dryRun = CLI::getOption('dry-run') !== null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            CLI::error('--since must be YYYY-MM-DD');

            return;
        }

        $documents = $this->documents($db, $since);
        CLI::write(sprintf('Backfill from %s: %d documents.', $since, count($documents)));

        if ($dryRun) {
            return;
        }

        $posting = new Posting($db);
        $failures = 0;

        $this->postOpeningStock($db, $posting->ledger(), $since);

        foreach ($documents as [$type, $id]) {
            try {
                match ($type) {
                    Posting::SOURCE_SALE      => $posting->postSale($id, null, true),
                    Posting::SOURCE_RECEIVING => $posting->postReceiving($id, null, true),
                    Posting::SOURCE_EXPENSE   => $posting->postExpense($id, null, true),
                    Posting::SOURCE_CASHUP    => $posting->postCashup($id),
                    Posting::SOURCE_INVENTORY => $this->postManualStock($db, $posting, $id),
                };
            } catch (Throwable $e) {
                $failures++;
                CLI::error("$type $id: " . $e->getMessage());
            }
        }

        $db->table('app_config')->where('key', 'accounting_start_date')->update(['value' => $since]);

        CLI::write($failures === 0 ? 'Backfill complete.' : "Backfill finished with $failures failures.", $failures === 0 ? 'green' : 'yellow');
    }

    private function oldestDocumentDate($db): string
    {
        $dates = array_filter([
            $db->table('sales')->selectMin('sale_time', 'd')->get()->getRow()->d ?? null,
            $db->table('receivings')->selectMin('receiving_time', 'd')->get()->getRow()->d ?? null,
            $db->table('expenses')->selectMin('date', 'd')->get()->getRow()->d ?? null,
        ]);

        return $dates === [] ? date('Y-m-d') : substr(min($dates), 0, 10);
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function documents($db, string $since): array
    {
        $rows = [];

        foreach ($db->table('sales')->select('sale_id AS id, sale_time AS d')->where('sale_status', COMPLETED)->where('sale_time >=', $since)->get()->getResult() as $row) {
            $rows[] = [$row->d, Posting::SOURCE_SALE, (int) $row->id];
        }
        foreach ($db->table('receivings')->select('receiving_id AS id, receiving_time AS d')->where('receiving_time >=', $since)->get()->getResult() as $row) {
            $rows[] = [$row->d, Posting::SOURCE_RECEIVING, (int) $row->id];
        }
        foreach ($db->table('expenses')->select('expense_id AS id, date AS d')->where('deleted', 0)->where('date >=', $since)->get()->getResult() as $row) {
            $rows[] = [$row->d, Posting::SOURCE_EXPENSE, (int) $row->id];
        }

        // Manual stock changes (new item quantities, adjustments, CSV imports); the
        // stock movements written by sales and receivings are posted with their document.
        $manual = $db->table('inventory')->select('trans_id AS id, trans_date AS d')->where('trans_date >=', $since)
            ->notLike('trans_comment', 'POS ', 'after')->notLike('trans_comment', 'RECV ', 'after')
            ->notLike('trans_comment', 'Deleting sale', 'after')->notLike('trans_comment', 'Deleting receiving', 'after')
            ->where('trans_inventory !=', 0)->get()->getResult();
        foreach ($manual as $row) {
            $rows[] = [$row->d, Posting::SOURCE_INVENTORY, (int) $row->id];
        }

        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): array => [$row[1], $row[2]], $rows);
    }

    private function postManualStock($db, Posting $posting, int $transId): ?int
    {
        $row = $db->table('inventory')->where('trans_id', $transId)->get()->getRow();

        // An item's first stock row is its initial quantity: that is opening equity, not a P&L gain.
        $firstTransId = (int) $db->table('inventory')->selectMin('trans_id', 'first_id')->where('trans_items', $row->trans_items)->get()->getRow()->first_id;

        return $posting->postInventoryAdjustment($transId, (int) $row->trans_items, (float) $row->trans_inventory, (int) $row->trans_user, (string) $row->trans_comment, (string) $row->trans_date, $firstTransId === $transId);
    }

    private function postOpeningStock($db, Ledger $ledger, string $since): void
    {
        if ($ledger->liveEntry('opening_stock', (int) str_replace('-', '', $since)) !== null) {
            return;
        }

        $value = (float) ($db->query(
            'SELECT SUM(q.qty * i.cost_price) AS value FROM (
                SELECT trans_items, SUM(trans_inventory) AS qty FROM ' . $db->prefixTable('inventory') . ' WHERE trans_date < ? GROUP BY trans_items
             ) q JOIN ' . $db->prefixTable('items') . ' i ON i.item_id = q.trans_items WHERE i.stock_type = ' . HAS_STOCK,
            [$since]
        )->getRow()->value ?? 0);

        if (round($value, 2) == 0.0) {
            return;
        }

        $ledger->post([
            'entry_date'  => date('Y-m-d H:i:s', strtotime($since) - 1),
            'description' => lang('Accounting.opening_stock_entry'),
            'source_type' => 'opening_stock',
            'source_id'   => (int) str_replace('-', '', $since),
            'reference'   => 'OPEN-STOCK',
        ], [
            ['account' => 'inventory', 'amount' => round($value, 2)],
            ['account' => 'opening_balance', 'amount' => -round($value, 2)],
        ]);

        CLI::write(sprintf('Opening stock posted: %.2f', $value));
    }
}
