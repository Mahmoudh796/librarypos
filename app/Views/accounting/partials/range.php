<?php
/**
 * Standard filter bar of the treasury and accounting screens: the shared period
 * picker (or a single "as of" date), the page's own filters, then the actions.
 * Choosing a period applies it at once; the dates still travel as ?from=&to=.
 *
 * Every dropdown filter with a value other than its "all" option is echoed as a
 * removable chip below the bar, so the active filter state is always visible.
 *
 * @var string|null $from
 * @var string|null $to
 * @var string|null $as_of
 * @var string $extra       Pre-rendered extra controls (already escaped)
 * @var string $action      Form action URL
 */
$extra ??= '';
$isFiltered = service('request')->getGet() !== [];
?>
<form method="get" action="<?= esc($action, 'attr') ?>" class="ruya-filterbar print_hide" role="search" id="ruya_filter_form">
    <?php if (isset($as_of)): ?>
        <div class="ruya-filterbar__field">
            <label for="as_of"><?= lang('Accounting.as_of') ?></label>
            <input type="date" id="as_of" name="as_of" class="form-control input-sm dj-ltr" value="<?= esc($as_of, 'attr') ?>" onchange="this.form.submit()">
        </div>
    <?php else: ?>
        <div class="ruya-filterbar__field">
            <label for="daterangepicker"><?= lang('Reports.period') ?></label>
            <input type="text" id="daterangepicker" class="form-control input-sm">
            <input type="hidden" name="from" id="filter_from" value="<?= esc($from ?? '', 'attr') ?>">
            <input type="hidden" name="to" id="filter_to" value="<?= esc($to ?? '', 'attr') ?>">
        </div>
    <?php endif; ?>
    <?= $extra ?>
    <div class="ruya-filterbar__actions">
        <button type="submit" class="btn btn-primary btn-sm">
            <span class="glyphicon glyphicon-search" aria-hidden="true"></span> <?= lang('Common.show_results') ?>
        </button>
        <?php if ($isFiltered): ?>
            <a class="btn btn-link btn-sm" href="<?= esc($action, 'attr') ?>"><?= lang('Common.clear_filters') ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-default btn-sm" onclick="window.print()">
            <span class="glyphicon glyphicon-print" aria-hidden="true"></span> <?= lang('Common.print') ?>
        </button>
    </div>
</form>
<div class="ruya-chips print_hide" id="ruya_filter_chips" aria-live="polite"></div>

<script type="text/javascript">
    $(document).ready(function () {
        const form = $('#ruya_filter_form');

        <?php if (!isset($as_of)): ?>
            <?= view('partial/daterangepicker') ?>
            setTimeout(function () {
                window.djPeriod.set($('#filter_from').val() || moment().format('YYYY-MM-DD'), $('#filter_to').val() || moment().format('YYYY-MM-DD'));
            }, 0);
            $('#daterangepicker').on('apply.daterangepicker', function () {
                $('#filter_from').val(start_date.substring(0, 10));
                $('#filter_to').val(end_date.substring(0, 10));
                form.trigger('submit');
            });
        <?php endif; ?>

        // A chip per active dropdown filter; removing it resets that filter.
        form.find('select').each(function () {
            const select = $(this);
            const first = select.find('option').first();
            if (!select.attr('name') || select.val() === null || select.val() === '' || select.val() === first.val()) {
                return;
            }
            const label = form.find('label[for="' + select.attr('id') + '"]').text() || select.attr('aria-label') || '';
            const chip = $('<span class="ruya-chip"></span>').text(label + ': ' + select.find('option:selected').text() + ' ');
            const remove = $('<button type="button" class="ruya-chip__remove" aria-label="<?= esc(lang('Common.clear_filters'), 'attr') ?>">×</button>');
            remove.on('click', function () {
                select.val(first.val());
                form.trigger('submit');
            });
            $('#ruya_filter_chips').append(chip.append(remove));
        });
    });
</script>
