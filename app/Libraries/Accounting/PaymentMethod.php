<?php

namespace App\Libraries\Accounting;

/**
 * OSPOS stores the payment type as the translated label that was active when the
 * payment was taken ("نقدى", "Cash", "Gift Card:4100000048", ...). Accounting needs
 * the method, so labels from every locale are folded back to a method code.
 */
class PaymentMethod
{
    public const CASH = 'cash';
    public const DEBIT_CARD = 'debit';
    public const CREDIT_CARD = 'credit';
    public const CHEQUE = 'check';
    public const DUE = 'due';
    public const GIFT_CARD = 'giftcard';
    public const REWARDS = 'rewards';
    public const CASH_DEPOSIT = 'cash_deposit';
    public const CREDIT_DEPOSIT = 'credit_deposit';
    public const CASH_ADJUSTMENT = 'cash_adjustment';
    public const UNKNOWN = 'unknown';

    /**
     * Language file => key => method. Expenses saved by older versions carry the
     * Expenses.* labels ("نقدا") rather than the Sales.* ones ("نقدى").
     */
    private const LANGUAGE_KEYS = [
        'Sales' => [
            'cash'            => self::CASH,
            'debit'           => self::DEBIT_CARD,
            'credit'          => self::CREDIT_CARD,
            'check'           => self::CHEQUE,
            'due'             => self::DUE,
            'giftcard'        => self::GIFT_CARD,
            'rewards'         => self::REWARDS,
            'cash_deposit'    => self::CASH_DEPOSIT,
            'credit_deposit'  => self::CREDIT_DEPOSIT,
            'cash_adjustment' => self::CASH_ADJUSTMENT,
        ],
        'Expenses' => [
            'cash'   => self::CASH,
            'debit'  => self::DEBIT_CARD,
            'credit' => self::CREDIT_CARD,
            'check'  => self::CHEQUE,
            'due'    => self::DUE,
        ],
    ];

    /** @var array<string, string>|null */
    private static ?array $labels = null;

    public static function resolve(string $label): string
    {
        $label = trim($label);

        // Gift card payments carry the card number after a colon.
        $prefix = trim(explode(':', $label, 2)[0]);

        $labels = self::labels();

        return $labels[self::key($label)] ?? $labels[self::key($prefix)] ?? self::UNKNOWN;
    }

    /**
     * Where a payment method lands in the ledger. Cash goes to the treasury
     * account supplied by the caller; everything else to a mapped account.
     */
    public static function mappingKey(string $method): ?string
    {
        return match ($method) {
            self::CASH, self::CASH_DEPOSIT, self::CASH_ADJUSTMENT => null,
            self::DEBIT_CARD, self::CREDIT_CARD, self::CREDIT_DEPOSIT, self::UNKNOWN => 'card_clearing',
            self::CHEQUE   => 'cheques_receivable',
            self::DUE      => 'accounts_receivable',
            self::GIFT_CARD => 'giftcard_liability',
            self::REWARDS  => 'rewards_liability',
        };
    }

    private static function key(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $label)));
    }

    /**
     * @return array<string, string> normalized label => method
     */
    private static function labels(): array
    {
        if (self::$labels !== null) {
            return self::$labels;
        }

        $labels = [];

        // Sales labels first: they win when another file reuses the same text.
        foreach (self::LANGUAGE_KEYS as $languageFile => $keys) {
            foreach (glob(APPPATH . "Language/*/$languageFile.php") ?: [] as $file) {
                $lines = include $file;

                if (!is_array($lines)) {
                    continue;
                }

                foreach ($keys as $key => $method) {
                    $text = $lines[$key] ?? '';

                    if (is_string($text) && $text !== '') {
                        $labels[self::key($text)] ??= $method;
                    }
                }
            }
        }

        return self::$labels = $labels;
    }
}
