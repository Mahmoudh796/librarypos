<?php
/**
 * Ru'ya shared page-path (breadcrumb) component.
 *
 * Renders the deterministic parent chain for nested pages. Top-level module
 * pages must NOT include this partial - a lone module crumb would only be a
 * redundant "المبيعات ‹ المبيعات".
 *
 * Crumb shapes (a crumb array may combine them):
 *  - ['module' => 'reports']           label from lang('Module.<id>'), linked
 *      to base_url('<id>') only when the employee holds that module grant,
 *      otherwise printed as plain text;
 *  - ['component' => 'people', 'module' => 'people']  a hub crumb whose label
 *      comes from lang('Module.<module>') and whose destination is authorised
 *      by the component's own grant list (the people hub is reached through
 *      the customers, suppliers and employees grants);
 *  - ['label' => 'سجل المبيعات']        plain crumb (the current leaf when last);
 *  - ['label' => ..., 'href' => ...]   intermediate step with a deterministic
 *      destination. The href is passed through dj_canonical_route(), so a
 *      history step, a `javascript:` target or an off-site URL degrades to
 *      plain text instead of becoming a link that cannot be trusted.
 *
 * The current (last) crumb is announced with aria-current="page".
 *
 * @var array $crumbs ordered parent - current breadcrumbs
 */

if (!is_array($crumbs ?? null)) {
    return;
}

// A single crumb is a top-level page, not a hierarchy - suppress it entirely.
if (count($crumbs) < 2) {
    return;
}

$allowedModules = is_array($allowed_module_ids ?? null) ? array_map('strval', $allowed_module_ids) : [];

/*
 * A hub crumb links to a page that owns no module grant of its own - the
 * "people" hub is reached through the customers, suppliers and employees
 * grants. The authority therefore comes from the component's own module list
 * rather than from a single ID.
 */
$componentModules = [
    'people' => ['customers', 'suppliers', 'employees'],
];

$componentUrls = [
    'people' => 'people',
];

$lastKey = array_key_last($crumbs);

?>
<nav class="dj-page-path" aria-label="<?= esc(lang('Common.page_path')) ?>">
    <ol>
        <?php foreach ($crumbs as $key => $crumb) { ?>
            <?php
            $isLast = ($key === $lastKey);
            $label = '';
            $href = null;

            if (isset($crumb['component'])) {
                $component = (string) $crumb['component'];
                $label = (string) lang('Common.people');
                if (trim($label) === '') {
                    // The hub label has no dedicated translation in every
                    // locale; the module wording is the honest fallback.
                    $label = (string) lang('Module.customers');
                }
                $componentGrants = $componentModules[$component] ?? [];
                $grants = array_intersect($componentGrants, $allowedModules);
                if (!$isLast && $grants !== []) {
                    $href = dj_canonical_route($componentUrls[$component] ?? $component);
                }
            } elseif (isset($crumb['module'])) {
                $label = (string) lang('Module.' . $crumb['module']);
                if (!$isLast && in_array($crumb['module'], $allowedModules, true)) {
                    $href = dj_canonical_route((string) $crumb['module']);
                }
            } else {
                $label = (string) ($crumb['label'] ?? '');
                if (!$isLast) {
                    $href = dj_canonical_route($crumb['href'] ?? null);
                }
            }

            if (trim($label) === '') {
                continue;
            }
            ?>
            <li>
                <?php if ($isLast) { ?>
                    <span aria-current="page"><?= esc($label) ?></span>
                <?php } elseif ($href !== null) { ?>
                    <a href="<?= $href ?>"><?= esc($label) ?></a>
                <?php } else { ?>
                    <span><?= esc($label) ?></span>
                <?php } ?>
            </li>
        <?php } ?>
    </ol>
</nav>
