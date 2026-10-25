<?php
/**
 * @var list<array> $vouchers
 * @var array<string, string> $parties
 * @var string $from
 * @var string $to
 * @var int $page
 * @var int $pages
 * @var bool $can_reverse
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-briefcase" aria-hidden="true"></span>
        <?= lang('Treasury.vouchers') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<ul class="nav nav-tabs dj-subnav print_hide">
    <li><a href="<?= esc(base_url('treasury'), 'attr') ?>"><?= lang('Treasury.movements') ?></a></li>
    <li class="active"><a href="<?= esc(base_url('treasury/vouchers'), 'attr') ?>" aria-current="page"><?= lang('Treasury.vouchers') ?></a></li>
    <li><a href="<?= esc(base_url('cashups'), 'attr') ?>"><?= lang('Treasury.shifts') ?></a></li>
</ul>

<?= view('accounting/partials/range', ['from' => $from, 'to' => $to, 'action' => base_url('treasury/vouchers')]) ?>

<?php if ($vouchers === []): ?>
    <div class="dj-empty-state"><?= lang('Treasury.no_vouchers') ?></div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Treasury.voucher_number') ?></th>
                    <th><?= lang('Accounting.date') ?></th>
                    <th><?= lang('Treasury.voucher_type') ?></th>
                    <th><?= lang('Treasury.treasury') ?></th>
                    <th><?= lang('Accounting.party') ?> / <?= lang('Accounting.account') ?></th>
                    <th><?= lang('Accounting.description') ?></th>
                    <th class="text-right"><?= lang('Accounting.amount') ?></th>
                    <th><?= lang('Accounting.status') ?></th>
                    <?php if ($can_reverse): ?><th class="print_hide"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vouchers as $voucher): ?>
                    <tr>
                        <td class="dj-ltr text-nowrap"><?= esc($voucher['voucher_number']) ?></td>
                        <td class="dj-num text-nowrap"><?= esc(substr($voucher['voucher_date'], 0, 10)) ?></td>
                        <td><?= lang('Treasury.type_' . $voucher['voucher_type']) ?></td>
                        <td><?= esc($voucher['treasury_name']) ?></td>
                        <td dir="auto"><?= esc($parties[$voucher['party_type'] . ':' . $voucher['party_id']] ?? ($voucher['account_name'] ?? '')) ?></td>
                        <td dir="auto"><?= esc($voucher['description']) ?></td>
                        <td class="text-right dj-num"><?= to_currency($voucher['amount']) ?></td>
                        <td>
                            <span class="label label-<?= $voucher['status'] === 'posted' ? 'success' : 'default' ?>"><?= lang('Accounting.status_' . $voucher['status']) ?></span>
                        </td>
                        <?php if ($can_reverse): ?>
                            <td class="print_hide">
                                <?php if ($voucher['status'] === 'posted'): ?>
                                    <button type="button" class="btn btn-default btn-xs js-reverse" data-id="<?= (int) $voucher['voucher_id'] ?>"><?= lang('Accounting.reverse') ?></button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= view('accounting/partials/pager', ['page' => $page, 'pages' => $pages]) ?>
<?php endif; ?>

<?php if ($can_reverse): ?>
<script type="text/javascript">
    $(document).ready(function() {
        $('.js-reverse').on('click', function() {
            const reason = window.prompt(<?= json_encode(lang('Accounting.reverse_reason_prompt')) ?>);
            if (reason === null) {
                return;
            }
            $.post('<?= site_url('treasury/reverse') ?>/' + $(this).data('id'), { reason: reason }, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    setTimeout(function() { window.location.reload(); }, 600);
                }
            }, 'json');
        });
    });
</script>
<?php endif; ?>

<?= view('partial/footer') ?>
