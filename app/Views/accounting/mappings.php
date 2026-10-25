<?php
/**
 * @var array $mappings
 * @var array $accounts
 * @var list<array> $expense_categories
 * @var array $expense_accounts
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'accounts', 'leaf' => lang('Accounting.posting_mappings')]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-link" aria-hidden="true"></span> <?= lang('Accounting.posting_mappings') ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<p class="help-block dj-hint"><?= lang('Accounting.mappings_help') ?></p>

<form id="mappings_form" class="form-horizontal">
    <fieldset class="dj-form-section">
        <legend class="dj-form-section-title"><?= lang('Accounting.engine_accounts') ?></legend>
        <?php foreach ($mappings as $key => $accountId): ?>
            <div class="form-group form-group-sm">
                <label class="control-label col-sm-4" for="map_<?= esc($key, 'attr') ?>"><?= lang('Accounting.mapping_' . $key) ?></label>
                <div class="col-sm-6"><?= form_dropdown("mapping[$key]", $accounts, (string) $accountId, ['id' => "map_$key", 'class' => 'form-control input-sm']) ?></div>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <fieldset class="dj-form-section">
        <legend class="dj-form-section-title"><?= lang('Accounting.expense_category_accounts') ?></legend>
        <?php foreach ($expense_categories as $category): ?>
            <div class="form-group form-group-sm">
                <label class="control-label col-sm-4" for="cat_<?= (int) $category['expense_category_id'] ?>" dir="auto"><?= esc($category['category_name']) ?></label>
                <div class="col-sm-6"><?= form_dropdown('category_account[' . (int) $category['expense_category_id'] . ']', $expense_accounts, (string) ($category['account_id'] ?? ''), ['id' => 'cat_' . (int) $category['expense_category_id'], 'class' => 'form-control input-sm']) ?></div>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <div class="dj-form-actions">
        <button type="submit" class="btn btn-primary"><?= lang('Common.submit') ?></button>
    </div>
</form>

<script type="text/javascript">
    $('#mappings_form').on('submit', function(event) {
        event.preventDefault();
        $.post('<?= site_url('accounts/mappings') ?>', $(this).serialize(), function(response) {
            $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
        }, 'json');
    });
</script>

<?= view('partial/footer') ?>
