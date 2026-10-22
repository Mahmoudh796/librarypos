<?php
/**
 * Account-recovery screen.
 *
 * No credential lookup is performed here: this deployment cannot resolve a
 * trustworthy unique employee e-mail and has no expiring reset-token store, so
 * self-service recovery is intentionally not offered. The screen instead routes
 * the employee to the authorized administrator without disclosing anything
 * about any account, and shows the operator-configured public contact details.
 *
 * @var array $config
 */

$contactEmail   = trim((string) ($config['email'] ?? ''));
$contactPhone   = trim((string) ($config['phone'] ?? ''));
$contactAddress = trim((string) ($config['address'] ?? ''));
$contactWebsite = trim((string) ($config['website'] ?? ''));

// Never present the placeholder details shipped by the default installer as if
// they were real administrator contacts on an otherwise unconfigured install.
$placeholderContacts = ['changeme@example.com', '555-555-5555', '123 Nowhere street'];

if (in_array($contactEmail, $placeholderContacts, true)) {
    $contactEmail = '';
}
if (in_array($contactPhone, $placeholderContacts, true)) {
    $contactPhone = '';
}
if (in_array($contactAddress, $placeholderContacts, true)) {
    $contactAddress = '';
}

$hasContact = $contactEmail !== '' || $contactPhone !== '' || $contactAddress !== '' || $contactWebsite !== '';
?>

<!doctype html>
<html lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(get_text_direction(), 'attr') ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(lang('Common.software_short')) . '&nbsp;|&nbsp;' . esc(lang('Login.recovery_title')) ?></title>
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
        <section class="dj-auth-card" aria-labelledby="recovery-heading">
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

                <h1 id="recovery-heading" class="dj-auth-title"><?= lang('Login.recovery_title') ?></h1>
                <p class="dj-auth-subtitle"><?= lang('Login.recovery_message') ?></p>
            </header>

            <?php if ($hasContact): ?>
                <div class="dj-auth-contact">
                    <h2 class="dj-auth-contact-title"><?= lang('Login.recovery_contact_admin') ?></h2>
                    <ul class="dj-auth-contact-list">
                        <?php if ($contactEmail !== ''): ?>
                            <li><a href="mailto:<?= esc($contactEmail, 'attr') ?>"><?= esc($contactEmail) ?></a></li>
                        <?php endif; ?>
                        <?php if ($contactPhone !== ''): ?>
                            <li><a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $contactPhone), 'attr') ?>"><?= esc($contactPhone) ?></a></li>
                        <?php endif; ?>
                        <?php if ($contactAddress !== ''): ?>
                            <li><?= esc($contactAddress) ?></li>
                        <?php endif; ?>
                        <?php if ($contactWebsite !== ''): ?>
                            <li>
                                <a href="<?= esc((str_starts_with($contactWebsite, 'http') ? '' : 'https://') . $contactWebsite, 'attr') ?>" target="_blank" rel="noopener">
                                    <?= esc($contactWebsite) ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <a class="dj-auth-submit dj-auth-back" href="<?= site_url('login') ?>"><?= lang('Login.back_to_login') ?></a>
        </section>
    </main>

    <footer class="dj-auth-footer" dir="ltr">
        <?= lang('Common.copyrights', [date('Y')]) ?>
    </footer>
</body>

</html>
