<?php

/**
 * Standalone CLI worker used by ConcurrentDbRaceTrait::raceTwoProcesses().
 * Bootstraps CodeIgniter in its own process so the model method call below
 * runs on a genuinely separate DB connection from any sibling worker,
 * exercising real concurrent access instead of simulating it sequentially.
 *
 * Usage: php RaceWorker.php <method> <arg> [<arg> ...]
 */

chdir(dirname(__DIR__, 2));

require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

$method = $argv[1];
$args   = array_slice($argv, 2);

$readyFile = getenv('RACE_READY_FILE');
$goFile    = getenv('RACE_GO_FILE');

file_put_contents($readyFile, '1');

while (!file_exists($goFile)) {
    usleep(1000);
}

/**
 * Finalizes a one-item completed sale (line 0), mirroring the cart shape the
 * sales register produces for a POS transaction.
 *
 * args: itemId, locationId, quantity, price, employeeId
 */
$finalizeSale = static function (array $args): int {
    $items = [
        0 => [
            'item_id'       => (int) $args[0],
            'line'          => 0,
            'description'   => 'Test Item',
            'serialnumber'  => '',
            'quantity'      => (float) $args[2],
            'discount'      => 0,
            'discount_type' => 0,
            'cost_price'    => 1.00,
            'price'         => (float) $args[3],
            'item_location' => (int) $args[1],
            'print_option'  => 0,
        ],
    ];

    $employeeId = (int) $args[4];
    $saleStatus = COMPLETED;
    $payments   = [
        0 => [
            'payment_type'    => lang('Sales.cash'),
            'payment_amount'  => (float) $args[3] * abs((float) $args[2]),
            'cash_refund'     => 0,
            'cash_adjustment' => 0,
            'reference_code'  => null,
        ],
    ];
    $salesTaxes = [[], []];

    return model(App\Models\Sale::class)->save_value(
        NEW_ENTRY,
        $saleStatus,
        $items,
        NEW_ENTRY,
        $employeeId,
        'race sale',
        null,
        null,
        null,
        SALE_TYPE_POS,
        $payments,
        null,
        $salesTaxes
    );
};

/**
 * Finalizes a completed sale with two distinct item lines, used to race kit
 * style component deductions (item A at line 0, item B at line 1).
 *
 * args: itemA, itemB, locationId, qtyA, qtyB, price, employeeId
 */
$finalizeTwoItemSale = static function (array $args): int {
    $locationId = (int) $args[2];
    $employeeId = (int) $args[6];
    $price      = (float) $args[5];

    $items = [
        0 => [
            'item_id'       => (int) $args[0],
            'line'          => 0,
            'description'   => 'Test Item A',
            'serialnumber'  => '',
            'quantity'      => (float) $args[3],
            'discount'      => 0,
            'discount_type' => 0,
            'cost_price'    => 1.00,
            'price'         => $price,
            'item_location' => $locationId,
            'print_option'  => 0,
        ],
        1 => [
            'item_id'       => (int) $args[1],
            'line'          => 1,
            'description'   => 'Test Item B',
            'serialnumber'  => '',
            'quantity'      => (float) $args[4],
            'discount'      => 0,
            'discount_type' => 0,
            'cost_price'    => 1.00,
            'price'         => $price,
            'item_location' => $locationId,
            'print_option'  => 0,
        ],
    ];

    $saleStatus = COMPLETED;
    $payments   = [
        0 => [
            'payment_type'    => lang('Sales.cash'),
            'payment_amount'  => $price * (abs((float) $args[3]) + abs((float) $args[4])),
            'cash_refund'     => 0,
            'cash_adjustment' => 0,
            'reference_code'  => null,
        ],
    ];
    $salesTaxes = [[], []];

    return model(App\Models\Sale::class)->save_value(
        NEW_ENTRY,
        $saleStatus,
        $items,
        NEW_ENTRY,
        $employeeId,
        'race sale',
        null,
        null,
        null,
        SALE_TYPE_POS,
        $payments,
        null,
        $salesTaxes
    );
};

/**
 * Saves a one-item receiving.
 *
 * args: itemId, locationId, quantity, employeeId, supplierId
 */
$finalizeReceiving = static function (array $args): int {
    $items = [
        0 => [
            'item_id'            => (int) $args[0],
            'line'               => 0,
            'description'        => 'Test Item',
            'serialnumber'       => '',
            'quantity'           => (float) $args[2],
            'receiving_quantity' => 1,
            'discount'           => 0,
            'discount_type'      => 0,
            'price'              => 1.00,
            'item_location'      => (int) $args[1],
        ],
    ];

    return model(App\Models\Receiving::class)->save_value(
        $items,
        (int) $args[4],
        (int) $args[3],
        'race receiving',
        'REF-' . uniqid(),
        'Cash'
    );
};

/**
 * Voids a sale (restores stock when the sale was completed).
 *
 * args: saleId, employeeId
 */
$voidSale = static fn (array $args): bool => model(App\Models\Sale::class)
    ->delete((int) $args[0], false, true, (int) $args[1]);

/**
 * Applies a floor-guarded quantity change inside an explicit transaction so
 * the row lock is held until the transaction completes (the guard requires an
 * active transaction to serialize against concurrent writers).
 *
 * args: itemId, locationId, quantityChange
 */
$itemQuantityGuarded = static function (array $args): bool {
    $db = \Config\Database::connect();
    $db->transStart();

    $result = model(App\Models\Item_quantity::class)
        ->changeQuantity((int) $args[0], (int) $args[1], (float) $args[2], 0.0);

    $db->transComplete();

    return $result && $db->transStatus();
};

$result = match ($method) {
    'giftcard' => model(App\Models\Giftcard::class)
        ->decrementGiftcardValue((string) $args[0], (float) $args[1]),
    'rewardPoints' => model(App\Models\Customer::class)
        ->adjustRewardPoints((int) $args[0], (float) $args[1]),
    'itemQuantity' => model(App\Models\Item_quantity::class)
        ->changeQuantity((int) $args[0], (int) $args[1], (float) $args[2]),
    'itemQuantityGuarded' => $itemQuantityGuarded($args),
    'finalizeSale' => $finalizeSale($args) > 0,
    'finalizeTwoItemSale' => $finalizeTwoItemSale($args) > 0,
    'receiving' => $finalizeReceiving($args) > 0,
    'voidSale' => $voidSale($args),
    default => throw new InvalidArgumentException("Unknown race method: {$method}"),
};

echo $result ? '1' : '0';