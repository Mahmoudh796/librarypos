<?php

/**
 * Navigation helpers for the Ru'ya interface.
 *
 * A breadcrumb ancestor is a promise: "this crumb leads to a real page". That
 * promise only holds when the destination is a canonical route of this
 * application. A crumb built from browser history (`javascript:history.back()`),
 * from the previous referrer, or from a hard-coded absolute URL breaks it: the
 * link works on one deployment and dies on the next, and it can leave the
 * application entirely.
 *
 * The helper below is the single gate every breadcrumb destination passes
 * through, so the rule lives in one place instead of in each view.
 */

if (!function_exists('dj_canonical_route')) {
    /**
     * Normalises a breadcrumb destination to a canonical in-application URL.
     *
     * Accepts a relative route (`reports/summary`), an application-root path
     * (`/reports/summary`), or an absolute URL that already points at this
     * application (which is rewritten to its route rather than emitted as an
     * absolute URL). Anything else - a foreign host, a protocol-relative URL,
     * a `javascript:`/`data:` scheme, a Windows-style path, or an empty value -
     * is rejected so the caller renders plain text instead of a dead link.
     *
     * @param string|null $href Candidate destination.
     * @return string|null Canonical URL, or null when the destination is unsafe.
     */
    function dj_canonical_route(?string $href): ?string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return null;
        }

        // A backslash is never part of an application route and is a common
        // way to smuggle an absolute path past a naive check.
        if (str_contains($href, '\\')) {
            return null;
        }

        if (str_starts_with($href, '//') || preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) === 1) {
            // Scheme-qualified or protocol-relative: only accept it when it is
            // this very application, and then emit the route instead.
            $base = rtrim(base_url(), '/');

            if ($base !== '' && str_starts_with($href, $base . '/')) {
                return base_url(ltrim(substr($href, strlen($base)), '/'));
            }

            return null;
        }

        if (str_starts_with($href, '/')) {
            return $href;
        }

        return base_url($href);
    }
}
