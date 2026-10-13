<?php

/**
 * Dahrouj apparel variant helpers.
 *
 * Pure functions used by the variant creation workflow to turn a base product
 * name plus a set of colors/sizes into deterministic variant identities (name
 * and SKU/barcode). Keeping database access out of these functions makes the
 * normalization rules easy to test; collisions against stored items are
 * detected separately through Item::get_existing_item_numbers().
 */

/**
 * Normalizes a single variant option for display: trims the outer whitespace
 * and collapses internal whitespace runs into a single space.
 */
function variant_option_label(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

/**
 * Splits a free-form option list into clean, unique labels. Values may be
 * separated by new lines and/or commas. Matching is case-insensitive and the
 * first spelling wins, so "Black, black" produces a single "Black".
 *
 * @return string[]
 */
function variant_split_options(string $input): array
{
    return variant_unique_options(preg_split('/[\r\n,]+/u', $input) ?: []);
}

/**
 * @param array<int, mixed> $values
 * @return string[]
 */
function variant_unique_options(array $values): array
{
    $options = [];
    $seen = [];

    foreach ($values as $value) {
        if (!is_scalar($value)) {
            continue;
        }

        $label = variant_option_label((string) $value);

        if ($label === '') {
            continue;
        }

        $key = mb_strtolower($label, 'UTF-8');

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $options[] = $label;
    }

    return $options;
}

/**
 * Reduces a label to an uppercase ASCII token safe to use inside a SKU or
 * barcode. Labels with no ASCII characters (for example Arabic) would collapse
 * to an empty token, so a short deterministic hash is used instead of dropping
 * the segment.
 */
function variant_sku_token(string $label): string
{
    $token = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label), '-'));

    if ($token === '') {
        $token = strtoupper(substr(sha1($label), 0, 6));
    }

    return $token;
}

/**
 * Composes a SKU/barcode from an optional prefix and the variant option labels,
 * in the order they are supplied (color before size).
 *
 * @param string[] $options
 */
function variant_build_sku(string $prefix, array $options = []): string
{
    $segments = [];

    if (trim($prefix) !== '') {
        $segments[] = variant_sku_token($prefix);
    }

    foreach ($options as $option) {
        $segments[] = variant_sku_token((string) $option);
    }

    return implode('-', array_filter($segments, static fn (string $segment): bool => $segment !== ''));
}

/**
 * Builds the human readable variant name ("Classic Shirt / Black / M").
 *
 * @param string[] $options
 */
function variant_build_name(string $base, array $options = [], string $separator = ' / '): string
{
    $parts = [];

    if (trim($base) !== '') {
        $parts[] = variant_option_label($base);
    }

    foreach ($options as $option) {
        $label = variant_option_label((string) $option);

        if ($label !== '') {
            $parts[] = $label;
        }
    }

    return implode($separator, $parts);
}

/**
 * Builds the color x size matrix. Each entry carries the individual option
 * labels (color before size) as well as the combined option list used for the
 * name and SKU. When both lists are empty a single plain entry is produced so
 * the caller can still create one item.
 *
 * @param string[] $colors
 * @param string[] $sizes
 * @return array<int, array{color: ?string, size: ?string, options: string[]}>
 */
function variant_build_combinations(array $colors, array $sizes): array
{
    $combinations = [];

    if ($colors === [] && $sizes === []) {
        return [['color' => null, 'size' => null, 'options' => []]];
    }

    if ($colors === []) {
        foreach ($sizes as $size) {
            $combinations[] = ['color' => null, 'size' => $size, 'options' => [$size]];
        }

        return $combinations;
    }

    if ($sizes === []) {
        foreach ($colors as $color) {
            $combinations[] = ['color' => $color, 'size' => null, 'options' => [$color]];
        }

        return $combinations;
    }

    foreach ($colors as $color) {
        foreach ($sizes as $size) {
            $combinations[] = ['color' => $color, 'size' => $size, 'options' => [$color, $size]];
        }
    }

    return $combinations;
}
