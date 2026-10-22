<?php
/**
 * @var int   $person_id
 * @var array $permission_ids
 * @var array $grants
 */

$detailed_reports = [
    'reports_sales'      => 'detailed',
    'reports_receivings' => 'detailed',
    'reports_customers'  => 'specific',
    'reports_discounts'  => 'specific',
    'reports_employees'  => 'specific',
    'reports_suppliers'  => 'specific',
];
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php
if (isset($error)) {
    echo '<div class="alert alert-dismissible alert-danger">' . esc($error) . '</div>';
}
?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-stats" aria-hidden="true"></span>
        <?= lang('Module.reports') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<div class="reports-hub">
    <section class="dj-section" aria-labelledby="dj-reports-graphical">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-reports-graphical"><span class="glyphicon glyphicon-stats" aria-hidden="true"></span><?= lang('Reports.graphical_reports') ?></h2>
        </div>
        <div class="list-group">
            <?php foreach ($permission_ids as $permission_id) {
                if (can_show_report($permission_id, ['inventory', 'receiving'])) {
                    $link = get_report_link($permission_id, 'graphical_summary');
            ?>
                    <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
            <?php
                }
            }
            ?>
        </div>
    </section>

    <section class="dj-section" aria-labelledby="dj-reports-summary">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-reports-summary"><span class="glyphicon glyphicon-list" aria-hidden="true"></span><?= lang('Reports.summary_reports') ?></h2>
        </div>
        <div class="list-group">
            <?php foreach ($permission_ids as $permission_id) {
                if (can_show_report($permission_id, ['inventory', 'receiving'])) {
                    $link = get_report_link($permission_id, 'summary');
            ?>
                    <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
            <?php
                }
            }
            ?>
        </div>
    </section>

    <section class="dj-section" aria-labelledby="dj-reports-detailed">
        <div class="dj-section-head">
            <h2 class="dj-section-title" id="dj-reports-detailed"><span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span><?= lang('Reports.detailed_reports') ?></h2>
        </div>
        <div class="list-group">
            <?php foreach ($detailed_reports as $report_name => $prefix) {
                if (in_array($report_name, $permission_ids, true)) {
                    $link = get_report_link($report_name, $prefix);
            ?>
                    <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
            <?php
                }
            }
            ?>
        </div>
    </section>

    <?php if (in_array('reports_inventory', $permission_ids, true)) { ?>
        <section class="dj-section" aria-labelledby="dj-reports-inventory">
            <div class="dj-section-head">
                <h2 class="dj-section-title" id="dj-reports-inventory"><span class="glyphicon glyphicon-book" aria-hidden="true"></span><?= lang('Reports.inventory_reports') ?></h2>
            </div>
            <div class="list-group">
                <?php
                $inventory_low_report = get_report_link('reports_inventory_low');
                $inventory_summary_report = get_report_link('reports_inventory_summary');
                ?>
                <a class="list-group-item" href="<?= $inventory_low_report['path'] ?>"><?= esc($inventory_low_report['label']) ?></a>
                <a class="list-group-item" href="<?= $inventory_summary_report['path'] ?>"><?= esc($inventory_summary_report['label']) ?></a>
            </div>
        </section>
    <?php } ?>
</div>

<?= view('partial/footer') ?>
