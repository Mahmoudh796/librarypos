<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var array $all_modules
 * @var array $all_subpermissions
 * @var int $employee_id
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("$controller_name/save/$person_info->person_id", ['id' => 'employee_form', 'class' => 'form-horizontal']) ?>

    <ul class="nav nav-tabs nav-justified" data-tabs="tabs">
        <li class="active" role="presentation">
            <a data-toggle="tab" href="#employee_basic_info"><?= lang('Employees.basic_information') ?></a>
        </li>
        <li role="presentation">
            <a data-toggle="tab" href="#employee_login_info"><?= lang('Employees.login_info') ?></a>
        </li>
        <li role="presentation">
            <a data-toggle="tab" href="#employee_permission_info"><?= lang('Employees.permission_info') ?></a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane active" id="employee_basic_info">
            <fieldset>
                <?= view('people/form_basic_info') ?>
            </fieldset>
        </div>

        <div class="tab-pane" id="employee_login_info">
            <fieldset>
                <div class="form-group form-group-sm">
                    <?= form_label(lang('Employees.username'), 'username', ['class' => 'control-label col-xs-3 text-danger required']) ?>
                    <div class="col-xs-8">
                        <div class="input-group">
                            <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-user"></span></span>
                            <?= form_input([
                                'name'  => 'username',
                                'id'    => 'username',
                                'class' => 'form-control input-sm dj-input-code',
                                'value' => $person_info->username
                            ]) ?>
                        </div>
                    </div>
                </div>

                <?php $password_label_attributes = $person_info->person_id == "" ? ['class' => 'required'] : []; ?>

                <div class="form-group form-group-sm">
                    <?= form_label(lang('Employees.password'), 'password', array_merge($password_label_attributes, ['class' => 'control-label col-xs-3'])) ?>
                    <div class="col-xs-8">
                        <div class="input-group">
                            <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-lock"></span></span>
                            <?= form_password([
                                'name'  => 'password',
                                'id'    => 'password',
                                'class' => 'form-control input-sm dj-input-secret'
                            ]) ?>
                        </div>
                    </div>
                </div>

                <div class="form-group form-group-sm">
                    <?= form_label(lang('Employees.repeat_password'), 'repeat_password', array_merge($password_label_attributes, ['class' => 'control-label col-xs-3'])) ?>
                    <div class="col-xs-8">
                        <div class="input-group">
                            <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-lock"></span></span>
                            <?= form_password([
                                'name'  => 'repeat_password',
                                'id'    => 'repeat_password',
                                'class' => 'form-control input-sm dj-input-secret'
                            ]) ?>
                        </div>
                    </div>
                </div>

                <div class="form-group form-group-sm">
                    <?= form_label(lang('Employees.language'), 'language', ['class' => 'control-label col-xs-3']) ?>
                    <div class="col-xs-8">
                        <div class="input-group">
                            <?php
                            $languages = get_languages();
                            $languages[':'] = lang('Employees.system_language');
                            $language_code = current_language_code();
                            $language = current_language();

                            // If No language is set then it will display "System Language"
                            if ($language_code === current_language_code(true)) {
                                $language_code = '';
                                $language = '';
                            }

                            echo form_dropdown(
                                'language',
                                $languages,
                                "$language_code:$language",
                                ['class' => 'form-control input-sm']
                            );
                            ?>
                        </div>
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="tab-pane" id="employee_permission_info">
            <fieldset>
                <p><?= lang('Employees.permission_desc') ?></p>

                <?php
                /*
                 * The permission list keeps every server-side field name and
                 * value exactly as before: grant_<module_id>, menu_group_<id>,
                 * grant_<permission_id> and the validation hooks
                 * (#permission_list, .module-toggle, .module). The module rows
                 * are only grouped visually — group titles are list items with
                 * no form controls, so the existing validators and toggles keep
                 * operating on the same structure.
                 */
                $permGroupPlan = [
                    'pos'        => ['sales'],
                    'operations' => ['receivings', 'cashups'],
                    'catalog'    => ['items', 'item_kits', 'attributes'],
                    'people'     => ['customers', 'suppliers'],
                    'reporting'  => ['reports'],
                    'finance'    => ['expenses', 'expenses_categories', 'giftcards', 'taxes'],
                    'comms'      => ['messages'],
                    'admin'      => ['employees', 'home', 'config', 'office'],
                ];

                $permModulesById = [];
                foreach ($all_modules as $permModule) {
                    $permModulesById[$permModule->module_id] = $permModule;
                }

                $permissionGroups = [];
                $permGroupedIds = [];
                foreach ($permGroupPlan as $permGroupId => $permModuleIds) {
                    $modulesInGroup = [];
                    foreach ($permModuleIds as $permModuleId) {
                        if (isset($permModulesById[$permModuleId])) {
                            $modulesInGroup[] = $permModulesById[$permModuleId];
                            $permGroupedIds[] = $permModuleId;
                        }
                    }
                    if ($modulesInGroup !== []) {
                        $permissionGroups[$permGroupId] = $modulesInGroup;
                    }
                }

                // Safety net: an unknown or newly added module stays reachable.
                $permUngrouped = [];
                foreach ($all_modules as $permModule) {
                    if (!in_array($permModule->module_id, $permGroupedIds, true)) {
                        $permUngrouped[] = $permModule;
                    }
                }
                if ($permUngrouped !== []) {
                    $permissionGroups['admin'] = array_merge($permissionGroups['admin'] ?? [], $permUngrouped);
                }

                $renderPermModule = static function ($module, array $allSubpermissions): void {
                    ?>
                        <li>
                            <?= form_checkbox("grant_$module->module_id", $module->module_id, $module->grant == 1, 'class="module-toggle"') ?>
                            <?= form_dropdown(
                                "menu_group_$module->module_id",
                                [
                                    'home'   => lang('Module.home'),
                                    'office' => lang('Module.office'),
                                    'both'   => lang('Module.both')
                                ],
                                $module->menu_group
                            ) ?>

                            <span class="medium"><?= lang("Module.$module->module_id") ?>:</span>
                            <span class="small"><?= lang("Module.$module->module_id" . '_desc') ?></span>
                            <?php
                            foreach ($allSubpermissions as $permission) {
                                $exploded_permission = explode('_', $permission->permission_id, 2);
                                if ($permission->module_id == $module->module_id) {
                                    $lang_key = ucfirst($module->module_id) . '.' . $exploded_permission[1];
                                    $lang_line = lang($lang_key);
                                    // lang() returns the key itself when untranslated;
                                    // degrade to a humanized permission suffix instead.
                                    $lang_line = ($lang_line === $lang_key) ? ucwords(str_replace('_', ' ', $exploded_permission[1])) : $lang_line;
                                    if (!empty($lang_line)) {
                            ?>
                                        <ul>
                                            <li>
                                                <?= form_checkbox("grant_$permission->permission_id", $permission->permission_id, $permission->grant == 1) ?>
                                                <?= form_hidden("menu_group_$permission->permission_id", "--") ?>
                                                <span class="medium"><?= esc($lang_line) ?></span>
                                            </li>
                                        </ul>
                            <?php
                                    }
                                }
                            }
                            ?>
                        </li>
                    <?php
                };
                ?>

                <ul id="permission_list">
                    <?= form_hidden('module_validation', '1', 'class="module"') ?>
                    <?php foreach ($permissionGroups as $permGroupId => $permGroupModules) { ?>
                        <li role="presentation" class="dj-perm-head"><?= esc(lang('Employees.perm_group_' . $permGroupId)) ?></li>
                        <?php foreach ($permGroupModules as $permModule) { ?>
                            <?php $renderPermModule($permModule, $all_subpermissions) ?>
                        <?php } ?>
                    <?php } ?>
                </ul>
            </fieldset>
        </div>
    </div>

<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $.validator.setDefaults({
            ignore: []
        });

        $.validator.addMethod('module', function() {
            let result = $('#permission_list input').is(':checked');
            $('.module-toggle').each(function(index, element) {
                const parent = $(element);
                const checked = $(element).is(':checked');
                if ($('ul', parent).length > 0 && result) {
                    result &= !checked || (checked && $('ul > li > input:checked', parent).length > 0);
                }
            });
            return result;
        }, "<?= lang('Employees.subpermission_required') ?>");

        $('ul#permission_list > li > input.module-toggle').each(function() {
            const $this = $(this);
            $('ul > li > input,select', $this.parent()).each(function() {
                const $that = $(this);
                const updateInputs = function(checked) {
                    $that.prop('disabled', !checked);
                    !checked && $that.prop('checked', false);
                }
                $this.change(function() {
                    updateInputs($this.is(':checked'));
                });
                updateInputs($this.is(':checked'));
            });
        });

        $('#employee_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                first_name: 'required',
                last_name: 'required',
                username: {

                    required: true,
                    minlength: 5,
                    remote: '<?= esc("$controller_name/checkUsername/$employee_id") ?>'
                },
                password: {
                    <?php if ($person_info->person_id == '') { ?>
                        required: true,
                    <?php } ?>
                    minlength: 8
                },
                repeat_password: {
                    equalTo: '#password'
                },
                email: 'email'
            },

            messages: {
                first_name: "<?= lang('Common.first_name_required') ?>",
                last_name: "<?= lang('Common.last_name_required') ?>",
                username: {
                    required: "<?= lang('Employees.username_required') ?>",
                    minlength: "<?= lang('Employees.username_minlength') ?>",
                    remote: "<?= lang('Employees.username_duplicate') ?>"
                },
                password: {
                    <?php if ($person_info->person_id == "") { ?>
                        required: "<?= lang('Employees.password_required') ?>",
                    <?php } ?>
                    minlength: "<?= lang('Employees.password_minlength') ?>"
                },
                repeat_password: {
                    equalTo: "<?= lang('Employees.password_must_match') ?>"
                },
                email: "<?= lang('Common.email_invalid_format') ?>"
            }
        }, form_support.error));
    });
</script>
