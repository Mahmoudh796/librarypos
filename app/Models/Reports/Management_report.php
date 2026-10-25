<?php

namespace App\Models\Reports;

use App\Models\Sale;
use CodeIgniter\Model;
use Config\OSPOS;

/**
 * Shared management KPI source for the operational dashboard and management
 * reporting.
 *
 * It aggregates the same tables and reuses the same expressions as the
 * Summary_report family so a value shown on the dashboard always agrees with
 * the equivalent tabular report:
 *
 *  - only COMPLETED sales (sale_status = COMPLETED) are counted;
 *  - returns (sale_type = RETURN) carry a negative quantity in OSPOS, so they
 *    are summed separately and shown as positive magnitudes;
 *  - suspended quotes / work orders and canceled sales are excluded entirely;
 *  - cost is the persisted historical line cost (sales_items.item_cost_price),
 *    never the item's current cost_price, so restated costs cannot rewrite the
 *    past;
 *  - line discount is derived from sales_items.discount / discount_type using
 *    the Summary_report expression.
 *
 * Terminology (deliberately not "net profit"):
 *  - gross sales  = tax-exclusive merchandise revenue of completed sales, net of line discounts
 *  - discounts    = total line discount granted on completed sales
 *  - returns      = tax-exclusive merchandise value returned (positive)
 *  - net sales    = gross sales - returns
 *  - tax          = net tax collected on completed lines (returns reduce it)
 *  - cost         = net historical cost of goods sold (returns reverse cost)
 *  - gross profit = net sales - cost. This is a gross margin, not a net profit:
 *                   operating expenses, overhead and refunds of tax are not included.
 */
class Management_report extends Model
{
    protected $table = 'sales';
    protected $primaryKey = 'sale_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [];

    /**
     * Aggregate sales KPIs for a date range.
     *
     * @param string $startDate Inclusive Y-m-d (or full datetime when configured)
     * @param string $endDate Inclusive Y-m-d (or full datetime when configured)
     * @param int[]|null $locationIds Null = every location, [] = none
     * @return array<string, float|int>
     */
    public function getSalesTotals(string $startDate, string $endDate, ?array $locationIds = null): array
    {
        $decimals = totals_decimals();
        $config = config(OSPOS::class)->settings;
        $taxIncluded = (bool) $config['tax_included'];

        $lineRevenue = Sale::lineNetSql($decimals);

        $internalTax = "IFNULL(line_taxes.internal_tax, 0)";
        $salesTax = "IFNULL(line_taxes.sales_tax, 0)";

        $sql = "SELECT
                SUM(CASE WHEN sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ") THEN $lineRevenue ELSE 0 END) AS gross_revenue,
                SUM(CASE WHEN sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ") THEN
                    " . Sale::lineDiscountSql($decimals) . " ELSE 0 END) AS discounts,
                SUM(CASE WHEN sales.sale_type = " . SALE_TYPE_RETURN . " THEN $lineRevenue ELSE 0 END) AS return_revenue,
                SUM(IFNULL(line_taxes.tax, 0)) AS total_tax,
                SUM($internalTax) AS internal_tax,
                SUM($salesTax) AS sales_tax,
                SUM(CASE WHEN sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ") THEN $internalTax ELSE 0 END) AS gross_internal_tax,
                SUM(CASE WHEN sales.sale_type = " . SALE_TYPE_RETURN . " THEN $internalTax ELSE 0 END) AS return_internal_tax,
                SUM(sales_items.item_cost_price * sales_items.quantity_purchased) AS cost,
                COUNT(DISTINCT CASE WHEN sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ") THEN sales.sale_id END) AS transaction_count,
                COUNT(DISTINCT CASE WHEN sales.sale_type = " . SALE_TYPE_RETURN . " THEN sales.sale_id END) AS return_count
            FROM " . $this->db->prefixTable('sales_items') . " AS sales_items
            INNER JOIN " . $this->db->prefixTable('sales') . " AS sales ON sales.sale_id = sales_items.sale_id
            LEFT JOIN (
                SELECT sale_id, item_id, line,
                    SUM(ROUND(item_tax_amount, $decimals)) AS tax,
                    SUM(ROUND(CASE WHEN tax_type = 0 THEN item_tax_amount ELSE 0 END, $decimals)) AS internal_tax,
                    SUM(ROUND(CASE WHEN tax_type = 1 THEN item_tax_amount ELSE 0 END, $decimals)) AS sales_tax
                FROM " . $this->db->prefixTable('sales_items_taxes') . "
                GROUP BY sale_id, item_id, line
            ) AS line_taxes ON line_taxes.sale_id = sales_items.sale_id
                AND line_taxes.item_id = sales_items.item_id
                AND line_taxes.line = sales_items.line
            WHERE " . $this->dateWhere($config, 'sales.sale_time') . "
                AND sales.sale_status = " . COMPLETED . "
                AND sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ", " . SALE_TYPE_RETURN . ")"
            . $this->locationWhere($locationIds, 'sales_items.item_location');

        $row = $this->db->query($sql, $this->dateBinds($config, $startDate, $endDate))->getRowArray();

        if ($row === null) {
            $row = [];
        }

        $grossRevenue = (float) ($row['gross_revenue'] ?? 0);              // POS + invoice lines
        $returnRevenue = (float) ($row['return_revenue'] ?? 0);            // negative
        $grossInternalTax = (float) ($row['gross_internal_tax'] ?? 0);
        $returnInternalTax = (float) ($row['return_internal_tax'] ?? 0);

        // When tax is embedded in the price, strip it so the KPI is comparable
        // with the tabular reports that report a tax-exclusive subtotal.
        $grossSales = round($taxIncluded ? $grossRevenue - $grossInternalTax : $grossRevenue, $decimals);

        // Return quantities are negative; present returns as a positive magnitude.
        $returns = $taxIncluded ? -$returnRevenue + $returnInternalTax : -$returnRevenue;
        $returns = max(0.0, round($returns, $decimals));

        $netSales = round($grossSales - $returns, $decimals);
        $tax = round((float) ($row['total_tax'] ?? 0), $decimals);
        $cost = round((float) ($row['cost'] ?? 0), $decimals);
        $transactionCount = (int) ($row['transaction_count'] ?? 0);
        $returnCount = (int) ($row['return_count'] ?? 0);

        return [
            'gross_sales'         => $grossSales,
            'discounts'           => round((float) ($row['discounts'] ?? 0), $decimals),
            'returns'             => $returns,
            'net_sales'           => $netSales,
            'tax'                 => $tax,
            'cost'                => $cost,
            'gross_profit'        => round($netSales - $cost, $decimals),
            'transaction_count'   => $transactionCount,
            'return_count'        => $returnCount,
            'average_transaction' => $transactionCount > 0 ? round($grossSales / $transactionCount, $decimals) : 0.0,
        ];
    }

    /**
     * Daily net-sales trend for a date range, oldest first.
     *
     * @param string $startDate
     * @param string $endDate
     * @param int[]|null $locationIds
     * @return array<int, array{date: string, net_sales: float, transaction_count: int}>
     */
    public function getDailyTrend(string $startDate, string $endDate, ?array $locationIds = null): array
    {
        $decimals = totals_decimals();
        $config = config(OSPOS::class)->settings;
        $taxIncluded = (bool) $config['tax_included'];

        $lineRevenue = Sale::lineNetSql($decimals);

        $sql = "SELECT DATE(sales.sale_time) AS sale_date,
                SUM($lineRevenue) AS revenue,
                SUM(IFNULL(line_taxes.internal_tax, 0)) AS internal_tax,
                COUNT(DISTINCT CASE WHEN sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ") THEN sales.sale_id END) AS transaction_count
            FROM " . $this->db->prefixTable('sales_items') . " AS sales_items
            INNER JOIN " . $this->db->prefixTable('sales') . " AS sales ON sales.sale_id = sales_items.sale_id
            LEFT JOIN (
                SELECT sale_id, item_id, line,
                    SUM(ROUND(CASE WHEN tax_type = 0 THEN item_tax_amount ELSE 0 END, $decimals)) AS internal_tax
                FROM " . $this->db->prefixTable('sales_items_taxes') . "
                GROUP BY sale_id, item_id, line
            ) AS line_taxes ON line_taxes.sale_id = sales_items.sale_id
                AND line_taxes.item_id = sales_items.item_id
                AND line_taxes.line = sales_items.line
            WHERE " . $this->dateWhere($config, 'sales.sale_time') . "
                AND sales.sale_status = " . COMPLETED . "
                AND sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ", " . SALE_TYPE_RETURN . ")"
            . $this->locationWhere($locationIds, 'sales_items.item_location') . "
            GROUP BY DATE(sales.sale_time)
            ORDER BY sale_date ASC";

        $rows = $this->db->query($sql, $this->dateBinds($config, $startDate, $endDate))->getResultArray();
        $trend = [];

        foreach ($rows as $row) {
            $revenue = (float) $row['revenue'];
            if ($taxIncluded) {
                $revenue -= (float) $row['internal_tax'];
            }

            $trend[] = [
                'date'              => $row['sale_date'],
                'net_sales'         => round($revenue, $decimals),
                'transaction_count' => (int) $row['transaction_count'],
            ];
        }

        return $trend;
    }

    /**
     * Inventory snapshot for the dashboard.
     *
     * low_stock_count counts items that are still on hand (0 < quantity) but at
     * or below their reorder level. out_of_stock_count counts items at or below
     * zero. They are disjoint so the two cards never double-count.
     *
     * @param int[]|null $locationIds
     * @return array<string, float|int>
     */
    public function getInventorySnapshot(?array $locationIds = null): array
    {
        $decimals = totals_decimals();

        $sql = "SELECT
                SUM(CASE WHEN item_quantities.quantity > 0 AND item_quantities.quantity <= items.reorder_level THEN 1 ELSE 0 END) AS low_stock_count,
                SUM(CASE WHEN item_quantities.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                SUM(item_quantities.quantity * items.cost_price) AS cost_value,
                SUM(item_quantities.quantity * items.unit_price) AS retail_value,
                COUNT(*) AS stock_rows
            FROM " . $this->db->prefixTable('items') . " AS items
            INNER JOIN " . $this->db->prefixTable('item_quantities') . " AS item_quantities ON items.item_id = item_quantities.item_id
            INNER JOIN " . $this->db->prefixTable('stock_locations') . " AS stock_locations ON stock_locations.location_id = item_quantities.location_id
            WHERE items.deleted = 0
                AND items.stock_type = " . HAS_STOCK . "
                AND stock_locations.deleted = 0
                " . $this->locationWhere($locationIds, 'item_quantities.location_id');

        $row = $this->db->query($sql)->getRowArray() ?: [];

        return [
            'low_stock_count'    => (int) ($row['low_stock_count'] ?? 0),
            'out_of_stock_count' => (int) ($row['out_of_stock_count'] ?? 0),
            'cost_value'         => round((float) ($row['cost_value'] ?? 0), $decimals),
            'retail_value'       => round((float) ($row['retail_value'] ?? 0), $decimals),
        ];
    }

    /**
     * Best selling items (variants have their own item_id and therefore rank
     * independently) over a date range.
     *
     * @param string $startDate
     * @param string $endDate
     * @param int[]|null $locationIds
     * @param int $limit
     * @return array<int, array{item_id: int, name: string, item_number: string, quantity_sold: float, revenue: float}>
     */
    public function getTopItems(string $startDate, string $endDate, ?array $locationIds = null, int $limit = 10): array
    {
        $decimals = totals_decimals();
        $config = config(OSPOS::class)->settings;

        $lineRevenue = Sale::lineNetSql($decimals);

        $limit = max(1, min(100, $limit));

        $sql = "SELECT sales_items.item_id,
                MAX(items.name) AS name,
                MAX(items.item_number) AS item_number,
                SUM(sales_items.quantity_purchased) AS quantity_sold,
                SUM($lineRevenue) AS revenue
            FROM " . $this->db->prefixTable('sales_items') . " AS sales_items
            INNER JOIN " . $this->db->prefixTable('sales') . " AS sales ON sales.sale_id = sales_items.sale_id
            LEFT JOIN " . $this->db->prefixTable('items') . " AS items ON items.item_id = sales_items.item_id
            WHERE " . $this->dateWhere($config, 'sales.sale_time') . "
                AND sales.sale_status = " . COMPLETED . "
                AND sales.sale_type IN (" . SALE_TYPE_POS . ", " . SALE_TYPE_INVOICE . ", " . SALE_TYPE_RETURN . ")
                " . $this->locationWhere($locationIds, 'sales_items.item_location') . "
            GROUP BY sales_items.item_id
            ORDER BY revenue DESC
            LIMIT $limit";

        $rows = $this->db->query($sql, $this->dateBinds($config, $startDate, $endDate))->getResultArray();

        return array_map(static fn (array $row): array => [
            'item_id'       => (int) $row['item_id'],
            'name'          => (string) ($row['name'] ?? ''),
            'item_number'   => (string) ($row['item_number'] ?? ''),
            'quantity_sold' => (float) $row['quantity_sold'],
            'revenue'       => round((float) $row['revenue'], $decimals),
        ], $rows);
    }

    /**
     * Date predicate honouring the configured date/time format.
     */
    private function dateWhere(array $config, string $column): string
    {
        if (empty($config['date_or_time_format'])) {
            return "DATE($column) BETWEEN ? AND ?";
        }

        return "$column BETWEEN ? AND ?";
    }

    /**
     * Bindings for dateWhere().
     *
     * @return array<int, string>
     */
    private function dateBinds(array $config, string $startDate, string $endDate): array
    {
        if (empty($config['date_or_time_format'])) {
            return [$startDate, $endDate];
        }

        return [rawurldecode($startDate), rawurldecode($endDate)];
    }

    /**
     * Safe location predicate. Only integer ids are ever interpolated.
     */
    private function locationWhere(?array $locationIds, string $column): string
    {
        if ($locationIds === null) {
            return '';
        }

        $ids = array_values(array_unique(array_map('intval', $locationIds)));

        if ($ids === []) {
            return " AND $column = 0";
        }

        return " AND $column IN (" . implode(',', $ids) . ")";
    }
}
