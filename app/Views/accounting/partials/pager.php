<?php
/**
 * Previous/next pager that keeps the current query string.
 *
 * @var int $page
 * @var int $pages
 */
if ($pages <= 1) {
    return;
}

$request = service('request');
$query = $request->getGet();
$link = static function (int $target) use ($query): string {
    $query['page'] = $target;

    return '?' . http_build_query($query);
};
?>
<nav class="dj-pager print_hide" aria-label="<?= esc(lang('Accounting.pages'), 'attr') ?>">
    <ul class="pager">
        <li class="previous<?= $page <= 1 ? ' disabled' : '' ?>">
            <a href="<?= $page <= 1 ? '#' : esc($link($page - 1), 'attr') ?>"><?= lang('Common.back') ?></a>
        </li>
        <li class="dj-pager-status"><span class="dj-num"><?= $page ?> / <?= $pages ?></span></li>
        <li class="next<?= $page >= $pages ? ' disabled' : '' ?>">
            <a href="<?= $page >= $pages ? '#' : esc($link($page + 1), 'attr') ?>"><?= lang('Common.forward') ?></a>
        </li>
    </ul>
</nav>
