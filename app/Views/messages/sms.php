<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<div class="dj-workspace dj-workspace--narrow">
    <div class="dj-page-head">
        <?= view('partial/page_path', ['crumbs' => [['module' => 'people'], ['component' => 'people', 'module' => 'messages']]]) ?>
        <h1 class="dj-page-title">
            <span class="glyphicon glyphicon-envelope" aria-hidden="true"></span>
            <?= lang('Messages.sms_send') ?>
        </h1>
        <?= view('partial/page_nav') ?>
    </div>

    <div class="dj-form-panel">
        <?= form_open("messages/send/", ['id' => 'send_sms_form', 'enctype' => 'multipart/form-data', 'method' => 'post', 'class' => 'form-horizontal']) ?>
            <fieldset>
                <div class="form-group form-group-sm">
                    <label for="phone" class="col-xs-3 control-label"><?= lang('Messages.phone') ?></label>
                    <div class="col-xs-9">
                        <input class="form-control input-sm dj-input-phone" type="text" name="phone" placeholder="<?= lang('Messages.phone_placeholder') ?>">
                        <span class="help-block"><?= lang('Messages.multiple_phones') ?></span>
                    </div>
                </div>

                <div class="form-group form-group-sm">
                    <label for="message" class="col-xs-3 control-label"><?= lang('Messages.message') ?></label>
                    <div class="col-xs-9">
                        <textarea class="form-control input-sm dj-input-text" rows="4" id="message" name="message" placeholder="<?= lang('Messages.message_placeholder') ?>"></textarea>
                    </div>
                </div>

                <div class="dj-form-actions">
                    <?= form_submit([
                        'name'  => 'submit_form',
                        'id'    => 'submit_form',
                        'value' => lang('Common.submit'),
                        'class' => 'btn btn-primary'
                    ]) ?>
                </div>
            </fieldset>
        <?= form_close() ?>
    </div>
</div>

<?= view('partial/footer') ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#send_sms_form').validate({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        })
                    },
                    dataType: 'json'
                });
            }
        });
    });
</script>
