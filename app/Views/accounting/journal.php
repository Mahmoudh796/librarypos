<?php
/**
 * @var list<array> $entries
 * @var string $from
 * @var string $to
 * @var string $source_type
 * @var string $search
 * @var int $page
 * @var int $pages
 * @var bool $can_post
 */
$sources = ['' => lang('Accounting.all_sources')];
foreach (['sale', 'receiving', 'expense', 'cashup', 'voucher', 'shareholder', 'asset_purchase', 'depreciation', 'inventory', 'cost_revaluation', 'manual', 'year_close', 'opening_stock'] as $source) {
    $sources[$source] = lang('Accounting.source_' . $source);
}
$extra = '<div class="form-group">' . form_dropdown('source_type', $sources, $source_type, ['class' => 'form-control input-sm', 'aria-label' => lang('Accounting.source')]) . '</div>'
    . '<div class="form-group"><input type="search" name="search" class="form-control input-sm" dir="auto" value="' . esc($search, 'attr') . '" placeholder="' . esc(lang('Accounting.search_entries'), 'attr') . '"></div>';
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'journal']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-list" aria-hidden="true"></span> <?= lang('Accounting.journal') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<?php if ($can_post): ?>
    <div class="btn-toolbar dj-page-actions print_hide">
        <a class="btn btn-primary btn-sm" href="<?= esc(base_url('journal/create'), 'attr') ?>"><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Accounting.new_entry') ?></a>
    </div>
<?php endif; ?>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('journal'), 'extra' => $extra]) ?>

<?php if ($entries === []): ?>
    <div class="dj-empty-state"><?= lang('Accounting.no_entries') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.entry_number') ?></th>
                    <th><?= lang('Accounting.date') ?></th>
                    <th><?= lang('Accounting.source') ?></th>
                    <th><?= lang('Accounting.reference') ?></th>
                    <th><?= lang('Accounting.description') ?></th>
                    <th class="text-right"><?= lang('Accounting.amount') ?></th>
                    <th><?= lang('Accounting.employee') ?></th>
                    <th><?= lang('Accounting.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr<?= $entry['reverses_entry_id'] !== null ? ' class="dj-reversal-row"' : '' ?>>
                        <td class="dj-ltr"><a href="<?= esc(base_url('journal/view/' . $entry['entry_id']), 'attr') ?>"><?= esc($entry['entry_number']) ?></a></td>
                        <td class="dj-num text-nowrap"><?= esc(substr($entry['entry_date'], 0, 16)) ?></td>
                        <td><?= lang('Accounting.source_' . ($entry['source_type'] ?? 'manual')) ?></td>
                        <td class="dj-ltr"><?= esc($entry['reference']) ?></td>
                        <td dir="auto"><?= esc($entry['description']) ?></td>
                        <td class="text-right dj-num"><?= to_currency($entry['total']) ?></td>
                        <td><?= esc(trim($entry['first_name'] . ' ' . $entry['last_name'])) ?></td>
                        <td>
                            <?php if ($entry['reverses_entry_id'] !== null): ?>
                                <span class="label label-default"><?= lang('Accounting.reversal') ?></span>
                            <?php elseif ($entry['reversed_by_entry_id'] !== null): ?>
                                <span class="label label-warning"><?= lang('Accounting.status_reversed') ?></span>
                            <?php else: ?>
                                <span class="label label-success"><?= lang('Accounting.status_posted') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= view('accounting/partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif; ?>

<?= view('partial/footer') ?>
