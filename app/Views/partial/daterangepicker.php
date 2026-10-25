<?php
/**
 * Compact period picker shared by every date-filtered screen.
 *
 * Common periods are one click away; the two calendars only open for
 * "custom period". The field always names the active period ("This month ·
 * 01/10/2026 – 25/10/2026"), including when a page restores dates from the URL.
 *
 * Contract kept for the including pages: the globals start_date / end_date
 * (Y-m-d, or Y-m-d H:i:s when the store shows times), the #daterangepicker
 * element, and its apply.daterangepicker event. window.djPeriod.set(start, end)
 * moves the picker programmatically.
 *
 * @var array $config
 */

$withTime = !empty($config['date_or_time_format']);
$displayFormat = $config['dateformat'] . ($withTime ? ' ' . $config['timeformat'] : '');
$valueFormat = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
$today = strtotime('today');
$endOfToday = strtotime('tomorrow') - 1;

// [label, start, end]; "this month/year" run to today because nothing is recorded in the future.
$presets = [
    ['Datepicker.today', $today, $endOfToday],
    ['Datepicker.yesterday', strtotime('yesterday'), $today - 1],
    ['Datepicker.last_7', strtotime('-6 days', $today), $endOfToday],
    ['Datepicker.last_30', strtotime('-29 days', $today), $endOfToday],
    ['Datepicker.this_month', strtotime('first day of this month', $today), $endOfToday],
    ['Datepicker.last_month', strtotime('first day of last month', $today), strtotime('first day of this month', $today) - 1],
    ['Datepicker.this_year', mktime(0, 0, 0, 1, 1, (int) date('Y')), $endOfToday],
    ['Datepicker.all_time', mktime(0, 0, 0, 1, 1, 2010), $endOfToday],
];

$ranges = [];
foreach ($presets as [$label, $start, $end]) {
    $ranges[lang($label)] = [date($displayFormat, $start), date($displayFormat, $end)];
}

$monthKeys = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
$dayKeys = ['su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'];
?>
    let start_date = "<?= date($valueFormat, $today) ?>";
    let end_date = "<?= date($valueFormat, $endOfToday) ?>";

    (function () {
        const picker = $('#daterangepicker');
        const displayFormat = '<?= dateformat_momentjs($displayFormat) ?>';
        const shortFormat = '<?= dateformat_momentjs($config['dateformat']) ?>';
        const valueFormat = '<?= $withTime ? 'YYYY-MM-DD HH:mm:ss' : 'YYYY-MM-DD' ?>';
        const ranges = <?= json_encode($ranges, JSON_UNESCAPED_UNICODE) ?>;

        picker.attr({ readonly: true, autocomplete: 'off', 'aria-haspopup': 'dialog' }).addClass('dj-period-input');

        // The visible text: the preset name when the range matches one, then the dates.
        const describe = function (start, end) {
            const dates = start.format(shortFormat) + (start.isSame(end, 'day') ? '' : ' – ' + end.format(shortFormat));
            for (const label in ranges) {
                if (moment(ranges[label][0], displayFormat).isSame(start, 'day') && moment(ranges[label][1], displayFormat).isSame(end, 'day')) {
                    return label + ' · ' + dates;
                }
            }
            return dates;
        };

        picker.daterangepicker({
            ranges: ranges,
            autoUpdateInput: false,
            alwaysShowCalendars: false,
            showCustomRangeLabel: true,
            linkedCalendars: false,
            opens: '<?= get_text_direction() === 'rtl' ? 'left' : 'right' ?>',
            <?php if ($withTime): ?>
            timePicker: true,
            timePicker24Hour: true,
            <?php endif; ?>
            locale: {
                direction: '<?= get_text_direction() ?>',
                format: displayFormat,
                separator: ' – ',
                applyLabel: <?= json_encode(lang('Datepicker.apply')) ?>,
                cancelLabel: <?= json_encode(lang('Datepicker.cancel')) ?>,
                fromLabel: <?= json_encode(lang('Datepicker.from')) ?>,
                toLabel: <?= json_encode(lang('Datepicker.to')) ?>,
                customRangeLabel: <?= json_encode(lang('Datepicker.custom')) ?>,
                daysOfWeek: <?= json_encode(array_map(static fn ($day) => lang('Calendar.' . $day), $dayKeys), JSON_UNESCAPED_UNICODE) ?>,
                monthNames: <?= json_encode(array_map(static fn ($month) => lang('Calendar.' . $month), $monthKeys), JSON_UNESCAPED_UNICODE) ?>,
                firstDay: <?= (int) lang('Datepicker.weekstart') ?>
            },
            startDate: moment(start_date, valueFormat),
            endDate: moment(end_date, valueFormat),
            minDate: moment('2010-01-01', 'YYYY-MM-DD'),
            maxDate: moment().endOf('day')
        }, function (start, end) {
            start_date = start.format(valueFormat);
            end_date = (valueFormat.length > 10 ? end : end.clone().startOf('day')).format(valueFormat);
            picker.val(describe(start, end));
        });

        window.djPeriod = {
            set: function (start, end) {
                const from = moment(start, valueFormat);
                const to = moment(end, valueFormat);
                picker.data('daterangepicker').setStartDate(from);
                picker.data('daterangepicker').setEndDate(to);
                start_date = from.format(valueFormat);
                end_date = to.format(valueFormat);
                picker.val(describe(from, to));
            }
        };

        // Pages assign start_date / end_date (restored from the URL) after including
        // this partial, so align the picker once their ready handler has finished.
        setTimeout(function () { window.djPeriod.set(start_date, end_date); }, 0);
    })();
