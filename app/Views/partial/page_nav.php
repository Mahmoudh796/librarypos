<?php
/**
 * Ru'ya secondary page navigation (back / forward).
 *
 * Deliberately separate from the breadcrumb: a breadcrumb ancestor is a
 * deterministic link to a canonical route, while this control only walks the
 * browser's own history. It is therefore rendered as a compact pair of
 * buttons next to the page title of a nested page, never inside a CRUD
 * dialog, and each button is disabled while its direction is unavailable.
 *
 * @var string $direction Document direction ('rtl' or 'ltr').
 */
?>
<div class="dj-page-nav" role="group" aria-label="<?= esc(lang('Common.page_navigation'), 'attr') ?>">
    <button type="button" class="btn btn-default btn-sm dj-page-nav__btn dj-page-nav__back" data-ruya-nav="back" disabled>
        <span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span>
        <span class="dj-page-nav__label"><?= lang('Common.back') ?></span>
    </button>
    <button type="button" class="btn btn-default btn-sm dj-page-nav__btn dj-page-nav__forward" data-ruya-nav="forward" disabled>
        <span class="dj-page-nav__label"><?= lang('Common.forward') ?></span>
        <span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span>
    </button>
</div>
