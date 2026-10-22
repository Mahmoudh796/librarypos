<?php

/**
 * Direction semantics for the Ru'ya interface.
 *
 * A single document direction (`<html dir>`) is not enough to make an
 * application read natively: a form mixes human prose with machine-readable
 * values, and the two need opposite content directions inside the same RTL
 * page. Deciding that per input type is unreliable (`type="text"` says nothing
 * about the value), so every control carries an explicit semantic class and
 * the stylesheet resolves it.
 *
 * The semantic classes are locale independent — `.dj-input-text` follows the
 * document direction, the machine-readable classes stay left-to-right in every
 * locale — so switching Arabic and English never leaves stale direction
 * behind.
 */

/** Human-language prose: names, addresses, comments, descriptions, notes. */
const DJ_INPUT_TEXT = 'dj-input-text';

/** Machine-readable identifier: barcode, ISBN, SKU, account number, serial. */
const DJ_INPUT_CODE = 'dj-input-code';

/** Numeric-only value: price, cost, quantity, discount, percentage. */
const DJ_INPUT_NUMBER = 'dj-input-number';

/** Email address. */
const DJ_INPUT_EMAIL = 'dj-input-email';

/** Telephone number. */
const DJ_INPUT_PHONE = 'dj-input-phone';

/** Credential (password, PIN). */
const DJ_INPUT_SECRET = 'dj-input-secret';

/** Date or date-time handled by a calendar component. */
const DJ_INPUT_DATE = 'dj-input-date';

/**
 * The semantic direction classes, keyed by the short name used in views.
 *
 * @var array<string, string>
 */
const DJ_INPUT_SEMANTICS = [
    'text'   => DJ_INPUT_TEXT,
    'code'   => DJ_INPUT_CODE,
    'number' => DJ_INPUT_NUMBER,
    'email'  => DJ_INPUT_EMAIL,
    'phone'  => DJ_INPUT_PHONE,
    'secret' => DJ_INPUT_SECRET,
    'date'   => DJ_INPUT_DATE,
];

/**
 * Builds the class attribute of a form control, appending the shared
 * direction-semantics class for the given semantic.
 *
 * The base classes are kept verbatim so no existing styling contract
 * (Bootstrap sizing, validation hooks, JS selectors) changes.
 *
 * @param string $semantic One of: text, code, number, email, phone, secret, date.
 * @param string $classes  Existing class list.
 * @return string
 */
function dj_input_class(string $semantic, string $classes = ''): string
{
    $class = DJ_INPUT_SEMANTICS[$semantic] ?? DJ_INPUT_TEXT;

    $classes = trim($classes);

    return $classes === '' ? $class : $classes . ' ' . $class;
}

/**
 * The direction attribute value for the document or a fragment.
 *
 * @param string|null $languageCode Language code, or null for the active one.
 * @return string 'rtl' or 'ltr'
 */
function dj_dir(?string $languageCode = null): string
{
    return get_text_direction($languageCode);
}

/**
 * Whether the active document direction is right-to-left.
 */
function dj_is_rtl(): bool
{
    return dj_dir() === 'rtl';
}

/**
 * The inline `dir` attribute for a machine-readable value.
 *
 * Machine-readable values keep their own left-to-right content in every
 * locale; the attribute makes that explicit for assistive technology and for
 * values rendered outside a control (table cells, receipts, exports).
 */
function dj_ltr_attr(): string
{
    return 'ltr';
}
