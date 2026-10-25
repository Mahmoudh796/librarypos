<?php
/**
 * @var int $year
 * @var list<array> $months
 * @var ?object $closed_year_entry
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'periods']) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-calendar" aria-hidden="true"></span> <?= lang('Accounting.periods') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<form method="get" class="form-inline dj-ledger-filter print_hide">
    <label for="year"><?= lang('Accounting.fiscal_year') ?></label>
    <input type="number" id="year" name="year" min="2000" max="2100" class="form-control input-sm dj-ltr" value="<?= $year ?>">
    <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounting.show') ?></button>
</form>

<p class="help-block dj-hint"><?= lang('Accounting.periods_help') ?></p>

<div class="table-responsive">
    <table class="table table-bordered table-condensed dj-ledger-table">
        <thead>
            <tr>
                <th><?= lang('Accounting.period') ?></th>
                <th class="text-right"><?= lang('Accounting.entries') ?></th>
                <th><?= lang('Accounting.status') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php $monthKeys = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']; ?>
            <?php foreach ($months as $month): ?>
                <?php $closed = $month['status'] === 'closed'; ?>
                <tr<?= $month['ended'] ? '' : ' class="dj-row-muted"' ?>>
                    <td><?= lang('Calendar.' . $monthKeys[$month['month'] - 1]) ?> <span class="dj-num text-muted"><?= sprintf('%04d-%02d', $month['year'], $month['month']) ?></span></td>
                    <td class="text-right dj-num"><?= $month['entries'] ?></td>
                    <td>
                        <?php if ($closed): ?>
                            <span class="dj-badge dj-badge--neutral"><span class="glyphicon glyphicon-lock" aria-hidden="true"></span> <?= lang('Accounting.period_closed') ?></span>
                            <?php if ($month['closed_at']): ?><small class="text-muted dj-num"><?= esc($month['closed_at']) ?></small><?php endif; ?>
                        <?php elseif ($month['ended']): ?>
                            <span class="dj-badge dj-badge--success"><span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span> <?= lang('Accounting.period_open') ?></span>
                        <?php else: ?>
                            <span class="dj-badge dj-badge--info"><span class="glyphicon glyphicon-time" aria-hidden="true"></span> <?= lang('Accounting.period_running') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($closed): ?>
                            <button type="button" class="btn btn-default btn-xs js-period" data-month="<?= $month['month'] ?>" data-status="open"><?= lang('Accounting.reopen_period') ?></button>
                        <?php elseif ($month['ended']): ?>
                            <button type="button" class="btn btn-default btn-xs js-period" data-month="<?= $month['month'] ?>" data-status="closed"><span class="glyphicon glyphicon-lock" aria-hidden="true"></span> <?= lang('Accounting.close_period') ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section class="dj-section">
    <h2 class="dj-section-title"><?= lang('Accounting.year_end_closing') ?></h2>
    <p class="dj-hint"><?= lang('Accounting.year_end_closing_help') ?></p>
    <?php if ($closed_year_entry): ?>
        <p><?= lang('Accounting.year_closed_by_entry') ?> <a class="dj-ltr" href="<?= esc(base_url('journal/view/' . $closed_year_entry->entry_id), 'attr') ?>"><?= esc($closed_year_entry->entry_number) ?></a></p>
    <?php endif; ?>
    <?php if ($year < (int) date('Y')): ?>
        <button type="button" class="btn btn-default btn-sm" id="close_year"><?= lang($closed_year_entry ? 'Accounting.reclose_year' : 'Accounting.close_year') ?></button>
    <?php else: ?>
        <p class="dj-hint"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> <?= lang('Accounting.year_not_ended', [$year]) ?></p>
    <?php endif; ?>
</section>

<script type="text/javascript">
    $(document).ready(function() {
        $('.js-period').on('click', function() {
            const status = $(this).data('status');
            if (!window.confirm(status === 'closed' ? <?= json_encode(lang('Accounting.close_period_confirm')) ?> : <?= json_encode(lang('Accounting.reopen_period_confirm')) ?>)) {
                return;
            }
            const button = $(this).prop('disabled', true);
            $.post('<?= site_url('accounts/periodStatus') ?>', { year: <?= $year ?>, month: button.data('month'), status: status }, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    setTimeout(function() { window.location.reload(); }, 500);
                } else {
                    button.prop('disabled', false);
                }
            }, 'json').fail(function() { button.prop('disabled', false); });
        });

        $('#close_year').on('click', function() {
            if (!window.confirm(<?= json_encode(lang('Accounting.close_year_confirm', [$year])) ?>)) {
                return;
            }
            $.post('<?= site_url('accounts/closeYear') ?>', { year: <?= $year ?> }, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    setTimeout(function() { window.location.reload(); }, 500);
                }
            }, 'json');
        });
    });
</script>

<?= view('partial/footer') ?>
