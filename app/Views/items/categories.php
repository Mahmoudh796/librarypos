<?php
/**
 * Approved item categories.
 *
 * @var list<array{name: string, approved: bool, item_count: int}> $categories
 * @var bool $can_manage_categories
 */
?>

<?= view('partial/header') ?>

<div class="dj-page-head">
    <h1 class="dj-page-title">
        <span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span>
        <?= lang('Items.categories') ?>
    </h1>
    <?= view('partial/page_nav') ?>
</div>

<p class="help-block dj-hint"><?= lang('Items.categories_help') ?></p>

<?php if ($can_manage_categories): ?>
    <form id="category_add_form" class="form-inline dj-category-add" autocomplete="off">
        <div class="form-group">
            <label class="sr-only" for="category_name"><?= lang('Items.category_new') ?></label>
            <input type="text" class="form-control input-sm" id="category_name" name="name" maxlength="255" placeholder="<?= esc(lang('Items.category_new'), 'attr') ?>" dir="auto" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">
            <span class="glyphicon glyphicon-plus" aria-hidden="true"></span> <?= lang('Common.new') ?>
        </button>
    </form>
<?php endif; ?>

<?php if ($categories === []): ?>
    <div class="alert alert-info"><?= lang('Items.categories_empty') ?></div>
<?php else: ?>
    <table class="table table-striped table-bordered table-condensed dj-category-table">
        <thead>
            <tr>
                <th><?= lang('Items.category') ?></th>
                <th class="text-center"><?= lang('Items.category_item_count') ?></th>
                <th class="text-center"><?= lang('Items.category_status') ?></th>
                <?php if ($can_manage_categories): ?>
                    <th class="text-center print_hide"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $category): ?>
                <tr data-category="<?= esc($category['name'], 'attr') ?>">
                    <td dir="auto">
                        <a href="<?= esc(base_url('items') . '?category=' . rawurlencode($category['name']), 'attr') ?>"><?= esc($category['name']) ?></a>
                    </td>
                    <td class="text-center"><?= to_quantity_decimals((string) $category['item_count']) ?></td>
                    <td class="text-center">
                        <?php if ($category['approved']): ?>
                            <span class="label label-success"><?= lang('Items.category_approved') ?></span>
                        <?php else: ?>
                            <span class="label label-warning" title="<?= esc(lang('Items.category_unapproved_help'), 'attr') ?>"><?= lang('Items.category_unapproved') ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if ($can_manage_categories): ?>
                        <td class="text-center print_hide text-nowrap">
                            <button type="button" class="btn btn-default btn-xs js-category-rename" title="<?= esc(lang('Items.category_rename_help'), 'attr') ?>">
                                <span class="glyphicon glyphicon-pencil" aria-hidden="true"></span> <?= lang('Items.category_rename') ?>
                            </button>
                            <?php if (!$category['approved']): ?>
                                <button type="button" class="btn btn-default btn-xs js-category-approve">
                                    <span class="glyphicon glyphicon-ok" aria-hidden="true"></span> <?= lang('Items.category_approve') ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($category['item_count'] === 0): ?>
                                <button type="button" class="btn btn-danger btn-xs js-category-delete">
                                    <span class="glyphicon glyphicon-trash" aria-hidden="true"></span> <?= lang('Common.delete') ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($can_manage_categories): ?>
<script type="text/javascript">
    $(document).ready(function() {
        const messages = {
            renamePrompt: <?= json_encode(lang('Items.category_rename_prompt')) ?>,
            deleteConfirm: <?= json_encode(lang('Items.category_delete_confirm')) ?>
        };

        const submit = function(url, data) {
            $.post(url, data, function(response) {
                $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                if (response.success) {
                    setTimeout(function() { window.location.reload(); }, 600);
                }
            }, 'json');
        };

        $('#category_add_form').on('submit', function(event) {
            event.preventDefault();
            const name = $.trim($('#category_name').val());
            if (name !== '') {
                submit('<?= site_url('items/saveCategory') ?>', { name: name });
            }
        });

        $('.js-category-rename').on('click', function() {
            const original = $(this).closest('tr').data('category').toString();
            const name = window.prompt(messages.renamePrompt, original);
            if (name !== null && $.trim(name) !== '' && name !== original) {
                submit('<?= site_url('items/saveCategory') ?>', { name: name, original: original });
            }
        });

        $('.js-category-approve').on('click', function() {
            submit('<?= site_url('items/saveCategory') ?>', { name: $(this).closest('tr').data('category').toString() });
        });

        $('.js-category-delete').on('click', function() {
            const name = $(this).closest('tr').data('category').toString();
            if (window.confirm(messages.deleteConfirm)) {
                submit('<?= site_url('items/deleteCategory') ?>', { name: name });
            }
        });
    });
</script>
<?php endif; ?>

<?= view('partial/footer') ?>
