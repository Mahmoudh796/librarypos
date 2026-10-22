<?php
/**
 * @var bool $hasErrors
 * @var bool $isLatest
 * @var bool $isNewInstall
 * @var string $latestVersion
 * @var bool $gcaptchaEnabled
 * @var CodeIgniter\HTTP\IncomingRequest $request
 * @var array $config
 * @var $validation
 */

use Config\Services;

$request = Services::request();
?>

<!doctype html>
<html lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(get_text_direction(), 'attr') ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Common.software_short')) . '&nbsp;|&nbsp;' . esc(lang('Login.sign_in')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <?php
    $theme = (empty($config['theme'])
        || 'paper' == $config['theme']
        || 'readable' == $config['theme']
        ? 'flatly'
        : $config['theme']);
    ?>
    <link rel="stylesheet" href="resources/bootswatch5/<?= esc($theme, 'attr') ?>/bootstrap.min.css">
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="css/dahrouj.css">
    <link rel="stylesheet" href="css/rtl.css">
    <meta name="theme-color" content="#0f766e">
</head>

<body class="dj-auth">
    <main class="dj-auth-main">
        <section class="dj-auth-card" aria-labelledby="form-heading">
            <?= view('partial/language_switcher') ?>

            <header class="dj-auth-header">
                <?php if (isset($config['company_logo']) && !empty($config['company_logo'])): ?>
                    <img class="dj-auth-logo" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="<?= esc($config['company']) ?>">
                <?php else: ?>
                    <span class="dj-brand dj-auth-brand" aria-label="<?= esc(lang('Common.software_title'), 'attr') ?>">
                        <span class="dj-auth-wordmark" lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(get_text_direction(), 'attr') ?>"><?= esc(lang('Common.software_short')) ?></span>
                    </span>
                    <span class="dj-auth-descriptor" lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(get_text_direction(), 'attr') ?>"><?= esc(lang('Common.software_descriptor')) ?></span>
                <?php endif; ?>

                <h1 id="form-heading" class="dj-auth-title"><?= lang('Login.welcome', [lang('Common.software_short')]) ?></h1>
                <p id="dj-login-subtitle" class="dj-auth-subtitle"><?= lang('Login.login_subtitle') ?></p>
            </header>

            <?= form_open('login', ['id' => 'login-form']) ?>

            <div id="migration-warning" class="dj-auth-warning<?= ($isNewInstall || !$isLatest) ? '' : ' d-none' ?>">
                <strong>
                    <?php if ($isNewInstall): ?>
                        <?= lang('Login.initialization_message') ?>
                    <?php else: ?>
                        <?= lang('Login.migration_auth_message', [$latestVersion]) ?>
                    <?php endif; ?>
                </strong>
            </div>

            <?php if ($hasErrors): ?>
                <div class="dj-auth-alert" role="alert">
                    <?php foreach ($validation->getErrors() as $error): ?>
                        <div><?= $error ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="migration-success" class="dj-auth-note d-none" role="status">
                <strong><?= lang('Login.migration_complete') ?></strong> <?= lang('Login.migration_complete_login') ?>
            </div>

            <div id="migration-progress" class="d-none" role="status" aria-live="polite">
                <h3 class="dj-auth-title"><?= lang('Login.migration_initializing') ?></h3>
                <div class="dj-auth-progress-bar"><span></span></div>
                <p class="dj-auth-status" id="migration-status"><?= lang('Login.migration_running') ?></p>
            </div>

            <div id="migration-error" class="dj-auth-alert d-none" role="alert">
                <span id="migration-error-message"></span>
            </div>

            <div id="login-fields" class="dj-auth-fields">
                <div class="dj-auth-field">
                    <label for="input-username"><?= lang('Login.username') ?></label>
                    <input id="input-username" name="username" type="text" class="dj-input-code" autocomplete="username" autocapitalize="none" spellcheck="false" required <?php if (ENVIRONMENT == "testing") echo 'value="admin"'; ?>>
                </div>
                <div class="dj-auth-field">
                    <label for="input-password"><?= lang('Login.password') ?></label>
                    <input id="input-password" name="password" type="password" class="dj-input-secret" autocomplete="current-password" required <?php if (ENVIRONMENT == "testing") echo 'value="pointofsale"'; ?>>
                </div>

                <?php if ($gcaptchaEnabled): ?>
                    <script src="https://www.google.com/recaptcha/api.js"></script>
                    <div class="g-recaptcha" style="margin-top: var(--dj-space-3); text-align: center;" data-sitekey="<?= esc($config['gcaptcha_site_key']) ?>"></div>
                <?php endif; ?>
            </div>

            <button id="submit-button" class="dj-auth-submit" name="login-button" type="submit">
                <?php if ($isNewInstall): ?>
                    <?= lang('Login.initialize') ?>
                <?php elseif (!$isLatest): ?>
                    <?= lang('Module.migrate') ?>
                <?php else: ?>
                    <?= lang('Login.sign_in') ?>
                <?php endif; ?>
            </button>

            <?= form_close() ?>

            <p id="dj-recovery-link" class="dj-auth-recovery">
                <a class="dj-auth-recovery-link" href="<?= site_url('login/recovery') ?>"><?= lang('Login.forgot_username_or_password') ?></a>
            </p>
        </section>
    </main>

    <footer class="dj-auth-footer" dir="ltr">
        <?= lang('Common.copyrights', [date('Y')]) ?>
    </footer>

    <?php if (ENVIRONMENT == 'development' || get_cookie('debug') == 'true' || $request->getGet('debug') == 'true') : ?>
        <!-- inject:login:debug:js -->
        <script src="resources/js/jquery-12e87d2f3a.js"></script>
        <!-- endinject -->
    <?php else : ?>
        <!-- inject:login:prod:js -->
        <script src="resources/jquery-2c872dbe60.min.js"></script>
        <!-- endinject -->
    <?php endif; ?>
    <script>
        // @noinspection JSInitializingVariableWithUndefined
        const APP_STATE = {
            isNewInstall: <?= $isNewInstall ? 'true' : 'false' ?>,
            isLatest: <?= $isLatest ? 'true' : 'false' ?>,
            csrfToken: '<?= csrf_token() ?>',
            csrfHash: '<?= csrf_hash() ?>',
            migrateUrl: '<?= site_url('migrate') ?>',
            loginUrl: '<?= site_url('login') ?>',
            i18n: {
                welcome: <?= json_encode(lang('Login.welcome', [lang('Common.software_short')])) ?>,
                initialize: <?= json_encode(lang('Login.initialize')) ?>,
                initializationRequired: <?= json_encode(lang('Login.initialization_required')) ?>,
                initializationMessage: <?= json_encode(lang('Login.initialization_message')) ?>,
                migrate: <?= json_encode(lang('Module.migrate')) ?>,
                signIn: <?= json_encode(lang('Login.sign_in')) ?>,
                migrationRequired: <?= json_encode(lang('Login.migration_required')) ?>,
                migrationInitializing: <?= json_encode(lang('Login.migration_initializing')) ?>,
                migratingDatabase: <?= json_encode(lang('Login.migrating_database')) ?>,
                migrationRunning: <?= json_encode(lang('Login.migration_running')) ?>,
                migrationComplete: <?= json_encode(lang('Login.migration_complete')) ?>,
                migrationCompleteMigrate: <?= json_encode(lang('Login.migration_complete_migrate')) ?>,
                migrationCompleteLogin: <?= json_encode(lang('Login.migration_complete_login')) ?>,
                migrationFailed: <?= json_encode(lang('Login.migration_failed')) ?>,
                migrationErrorConnection: <?= json_encode(lang('Login.migration_error_connection')) ?>
            }
        };

        $(document).ready(function() {
            const $form = $('#login-form');
            const $heading = $('#form-heading');
            const $subtitle = $('#dj-login-subtitle');
            const $warning = $('#migration-warning');
            const $success = $('#migration-success');
            const $progress = $('#migration-progress');
            const $error = $('#migration-error');
            const $errorMessage = $('#migration-error-message');
            const $loginFields = $('#login-fields');
            const $recovery = $('#dj-recovery-link'); // Secondary "forgot credentials?" action, hidden during migration states.
            const $submitButton = $('#submit-button');

            function showMigrationRequired() {
                if (APP_STATE.isNewInstall) {
                    $heading.text(APP_STATE.i18n.initializationRequired);
                    $submitButton.text(APP_STATE.i18n.initialize);
                    $loginFields.addClass('d-none');
                } else {
                    $heading.text(APP_STATE.i18n.migrationRequired);
                    $submitButton.text(APP_STATE.i18n.migrate);
                    $loginFields.removeClass('d-none');
                }
                $subtitle.addClass('d-none');
                $recovery.addClass('d-none');
                $warning.removeClass('d-none');
                $success.addClass('d-none');
                $progress.addClass('d-none');
                $error.addClass('d-none');
            }

            function showMigrationProgress() {
                $warning.addClass('d-none');
                $success.addClass('d-none');
                $error.addClass('d-none');
                $loginFields.addClass('d-none');
                $subtitle.addClass('d-none');
                $recovery.addClass('d-none');
                $progress.find('h3').text(APP_STATE.isNewInstall ? APP_STATE.i18n.migrationInitializing : APP_STATE.i18n.migratingDatabase);
                $progress.removeClass('d-none');
                $submitButton.prop('disabled', true);
            }

            function showMigrationSuccess() {
                $progress.addClass('d-none');
                $error.addClass('d-none');
                $warning.addClass('d-none');
                $success.find('strong').text(APP_STATE.isNewInstall ? APP_STATE.i18n.migrationComplete : APP_STATE.i18n.migrationCompleteMigrate);
                $success.removeClass('d-none');
                $heading.text(APP_STATE.i18n.welcome);
                $subtitle.removeClass('d-none');
                $loginFields.removeClass('d-none');
                $recovery.removeClass('d-none');
                $submitButton.text(APP_STATE.i18n.signIn);
                $submitButton.prop('disabled', false);
            }

            function showMigrationError(message) {
                $progress.addClass('d-none');
                $success.addClass('d-none');
                $errorMessage.text(message);
                $error.removeClass('d-none');
                $warning.addClass('d-none');
                if (APP_STATE.isNewInstall) {
                    $loginFields.addClass('d-none');
                    $submitButton.text(APP_STATE.i18n.initialize);
                } else {
                    $loginFields.removeClass('d-none');
                    $submitButton.text(APP_STATE.i18n.migrate);
                }
                $subtitle.addClass('d-none');
                $recovery.addClass('d-none');
                $submitButton.prop('disabled', false);
            }

            function showLoginForm() {
                $heading.text(APP_STATE.i18n.welcome);
                $subtitle.removeClass('d-none');
                $recovery.removeClass('d-none');
                $warning.addClass('d-none');
                $progress.addClass('d-none');
                $error.addClass('d-none');
                $success.addClass('d-none');
                $loginFields.removeClass('d-none');
                $submitButton.text(APP_STATE.i18n.signIn);
            }

            if (!APP_STATE.isNewInstall && APP_STATE.isLatest) {
                showLoginForm();
            } else {
                showMigrationRequired();
            }

            $form.on('submit', function(e) {
                if (APP_STATE.isNewInstall || !APP_STATE.isLatest) {
                    e.preventDefault();

                    showMigrationProgress();

                    $.ajax({
                        url: APP_STATE.migrateUrl,
                        type: 'POST',
                        dataType: 'json',
                        timeout: 3600000,
                        data: {
                            [APP_STATE.csrfToken]: APP_STATE.csrfHash,
                            username: $('#input-username').val(),
                            password: $('#input-password').val(),
                        },
                        success: function(response) {
                            if (response.success) {
                                APP_STATE.isNewInstall = false;
                                APP_STATE.isLatest = true;
                                showMigrationSuccess();
                            } else {
                                showMigrationError(response.message || APP_STATE.i18n.migrationFailed);
                            }
                        },
                        error: function(xhr, status, error) {
                            let message = APP_STATE.i18n.migrationErrorConnection;
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            showMigrationError(message);
                        }
                    });
                }
            });
        });
    </script>
</body>

</html>
