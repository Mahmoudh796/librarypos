<?php
/**
 * @var list<array> $dimensions
 * @var list<array> $values
 */
$byDimension = [];
foreach ($values as $value) {
    $byDimension[(int) $value['dimension_id']][] = $value;
}
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'accounts', 'leaf' => lang('Accounting.cost_centers_and_dimensions')]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-th" aria-hidden="true"></span> <?= lang('Accounting.cost_centers_and_dimensions') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<p class="help-block dj-hint"><?= lang('Accounting.dimensions_help') ?></p>

<div class="row">
    <?php foreach ($dimensions as $dimension): ?>
        <div class="col-md-6">
            <section class="panel panel-default dj-panel">
                <div class="panel-heading"><h2 class="panel-title"><?= esc($dimension['name']) ?></h2></div>
                <ul class="list-group">
                    <?php foreach ($byDimension[(int) $dimension['dimension_id']] ?? [] as $value): ?>
                        <li class="list-group-item" dir="auto"><?= esc($value['name']) ?></li>
                    <?php endforeach; ?>
                    <?php if (empty($byDimension[(int) $dimension['dimension_id']])): ?>
                        <li class="list-group-item text-muted"><?= lang('Accounting.no_values_yet') ?></li>
                    <?php endif; ?>
                </ul>
                <div class="panel-footer">
                    <form class="form-inline js-value-form" autocomplete="off">
                        <input type="hidden" name="dimension_id" value="<?= (int) $dimension['dimension_id'] ?>">
                        <input type="text" name="name" maxlength="150" class="form-control input-sm" dir="auto" placeholder="<?= esc(lang('Accounting.new_value'), 'attr') ?>" required>
                        <button type="submit" class="btn btn-default btn-sm"><?= lang('Common.new') ?></button>
                    </form>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<script type="text/javascript">
    $('.js-value-form').on('submit', function(event) {
        event.preventDefault();
        $.post('<?= site_url('accounts/dimensionValue') ?>', $(this).serialize(), function(response) {
            $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
            if (response.success) {
                setTimeout(function() { window.location.reload(); }, 500);
            }
        }, 'json');
    });
</script>

<?= view('partial/footer') ?>
