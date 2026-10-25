<?php

use Config\OSPOS;

if (!function_exists('store_info')) {
    /**
     * A store detail exactly as the owner configured it.
     *
     * The installer seeds sample values (an example street, phone number and
     * e-mail, and "Test" as the return policy). Those are reported as not
     * configured, so a customer's receipt never shows made-up store details.
     */
    function store_info(string $key): string
    {
        $placeholders = [
            'address'       => '123 Nowhere street',
            'phone'         => '555-555-5555',
            'email'         => 'changeme@example.com',
            'return_policy' => 'Test',
        ];

        $value = trim((string) (config(OSPOS::class)->settings[$key] ?? ''));

        return isset($placeholders[$key]) && strcasecmp($value, $placeholders[$key]) === 0 ? '' : $value;
    }
}

if (!function_exists('store_info_placeholders')) {
    /**
     * Store details still holding the installer's sample value.
     *
     * @return list<string>
     */
    function store_info_placeholders(): array
    {
        $settings = config(OSPOS::class)->settings;

        return array_values(array_filter(['address', 'phone', 'email', 'return_policy'], static fn (string $key): bool => trim((string) ($settings[$key] ?? '')) !== '' && store_info($key) === ''));
    }
}
