<?php
use Config\OSPOS;

$config = config(OSPOS::class)->settings;
$languageCode = current_language_code(); ?>

<?php /*
 * The picker options are built explicitly. Extending the widget defaults with
 * the whole settings array leaked unrelated keys into the options and, because
 * the settings carry the language *name* rather than its code, it silently
 * replaced the locale with a value the widget does not know — the calendar
 * then fell back to its English month and day names.
 *
 * A function declaration (rather than a const) keeps the partial safe to
 * include more than once on the same document.
 */ ?>
function pickerconfig(config) {
    return {
        format: "<?= $this->data["format"] ?? dateformat_bootstrap($config['dateformat']) . ' ' . dateformat_bootstrap($config['timeformat'])?>",
        <?php
        $t = $config['timeformat'];
        $m = $t[strlen($t) - 1];
        if (str_contains($config['timeformat'], 'a') || str_contains($config['timeformat'], 'A')) {
        ?>
            showMeridian: true,
        <?php } else {  ?>
            showMeridian: false,
        <?php } ?>
        minView: 2,
        minuteStep: 1,
        autoclose: true,
        todayBtn: true,
        todayHighlight: true,
        bootcssVer: 3,
        language: "<?= $languageCode ?>"
    };
}

/*
 * The widget looks its strings up by locale code, so the calendar has to be
 * registered under the very code the widget is configured with.
 */
$.fn.datetimepicker.dates['<?= $languageCode ?>'] = {
    days: [
        "<?= lang('Calendar.sunday') ?>",
        "<?= lang('Calendar.monday') ?>",
        "<?= lang('Calendar.tuesday') ?>",
        "<?= lang('Calendar.wednesday') ?>",
        "<?= lang('Calendar.thursday') ?>",
        "<?= lang('Calendar.friday') ?>",
        "<?= lang('Calendar.saturday') ?>"
    ],
    daysShort: [
        "<?= lang('Calendar.sun') ?>",
        "<?= lang('Calendar.mon') ?>",
        "<?= lang('Calendar.tue') ?>",
        "<?= lang('Calendar.wed') ?>",
        "<?= lang('Calendar.thu') ?>",
        "<?= lang('Calendar.fri') ?>",
        "<?= lang('Calendar.sat') ?>"
    ],
    daysMin: [
        "<?= lang('Calendar.su') ?>",
        "<?= lang('Calendar.mo') ?>",
        "<?= lang('Calendar.tu') ?>",
        "<?= lang('Calendar.we') ?>",
        "<?= lang('Calendar.th') ?>",
        "<?= lang('Calendar.fr') ?>",
        "<?= lang('Calendar.sa') ?>"
    ],
    months: [
        "<?= lang('Calendar.january') ?>",
        "<?= lang('Calendar.february') ?>",
        "<?= lang('Calendar.march') ?>",
        "<?= lang('Calendar.april') ?>",
        "<?= lang('Calendar.may') ?>",
        "<?= lang('Calendar.june') ?>",
        "<?= lang('Calendar.july') ?>",
        "<?= lang('Calendar.august') ?>",
        "<?= lang('Calendar.september') ?>",
        "<?= lang('Calendar.october') ?>",
        "<?= lang('Calendar.november') ?>",
        "<?= lang('Calendar.december') ?>"
    ],
    monthsShort: [
        "<?= lang('Calendar.jan') ?>",
        "<?= lang('Calendar.feb') ?>",
        "<?= lang('Calendar.mar') ?>",
        "<?= lang('Calendar.apr') ?>",
        "<?= lang('Calendar.may') ?>",
        "<?= lang('Calendar.jun') ?>",
        "<?= lang('Calendar.jul') ?>",
        "<?= lang('Calendar.aug') ?>",
        "<?= lang('Calendar.sep') ?>",
        "<?= lang('Calendar.oct') ?>",
        "<?= lang('Calendar.nov') ?>",
        "<?= lang('Calendar.dec') ?>"
    ],
    today: "<?= lang('Datepicker.today') ?>",
    <?php if (str_contains($config['timeformat'], 'a')) { ?>
        meridiem: ["am", "pm"],
    <?php } elseif (str_contains($config['timeformat'], 'A')) { ?>
        meridiem: ["AM", "PM"],
    <?php } else { ?>
        meridiem: [],
    <?php } ?>
    weekStart: <?= lang('Datepicker.weekstart') ?>,
    /*
     * The widget mirrors itself when the locale declares it right-to-left,
     * which moves the calendar navigation and the time column to the reading
     * direction of the document.
     */
    rtl: <?= dj_is_rtl() ? 'true' : 'false' ?>
};

$(".datetime").datetimepicker(pickerconfig());
