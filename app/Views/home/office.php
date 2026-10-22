<?php
/**
 * Office landing page.
 *
 * The office modules are listed in the shared navigation, so this page no
 * longer repeats them as an icon grid.
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<div class="dj-dashboard">
    <div class="dj-page-head">
        <h1 class="dj-page-title"><span class="glyphicon glyphicon-briefcase" aria-hidden="true"></span><?= lang('Module.office') ?></h1>
        <?= view('partial/page_nav') ?>
    </div>

    <nav class="dj-office-nav" aria-label="<?= esc(lang('Common.page_navigation'), 'attr') ?>">
        <a class="dj-office-nav__item" href="<?= esc(base_url('config'), 'attr') ?>">
            <span class="glyphicon glyphicon-cog" aria-hidden="true"></span>
            <span><?= lang('Module.config') ?></span>
        </a>
        <a class="dj-office-nav__item" href="<?= esc(base_url('taxes'), 'attr') ?>">
            <span class="glyphicon glyphicon-piggy-bank" aria-hidden="true"></span>
            <span><?= lang('Module.taxes') ?></span>
        </a>
        <a class="dj-office-nav__item" href="<?= esc(base_url('backups'), 'attr') ?>">
            <span class="glyphicon glyphicon-hdd" aria-hidden="true"></span>
            <span><?= lang('Common.backup') ?></span>
        </a>
        <a class="dj-office-nav__item" href="<?= esc(base_url('messages/sms'), 'attr') ?>">
            <span class="glyphicon glyphicon-envelope" aria-hidden="true"></span>
            <span><?= lang('Messages.sms_send') ?></span>
        </a>
    </nav>
</div>

<?= view('partial/footer') ?>
