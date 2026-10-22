<?php
/**
 * Compact language switcher shared by the sign-in and account-recovery screens.
 *
 * The choice is posted to App\Controllers\Language, which validates it against
 * the supported-locale whitelist, persists it in the "language" cookie and sends
 * the visitor back to the page they were on.
 *
 * @var string $activeLocale Language code currently in effect.
 * @var string $redirectPath Same-site path to return to after switching.
 */
$request = service('request');
$activeLocale ??= current_language_code();
$redirectPath ??= ltrim($request->getUri()->getPath(), '/');

$authLanguages  = get_auth_languages();
$primarySubtag  = static fn (string $locale): string => strtolower(explode('-', str_replace('_', '-', $locale))[0]);
$activeLanguage = $primarySubtag($activeLocale) === 'ar' ? 'ar-EG' : 'en';
?>
<form class="dj-lang" method="post" action="<?= site_url('language/set') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= esc($redirectPath, 'attr') ?>">
    <div class="dj-lang-group" role="group" aria-label="<?= esc(lang('Employees.language'), 'attr') ?>">
        <?php foreach ($authLanguages as $code => $label): ?>
            <?php $isActive = $code === $activeLanguage; ?>
            <button type="submit"
                    name="language"
                    value="<?= esc($code, 'attr') ?>"
                    class="dj-lang-option<?= $isActive ? ' is-active' : '' ?>"
                    lang="<?= esc($primarySubtag($code), 'attr') ?>"
                    dir="<?= esc(get_text_direction($code), 'attr') ?>"
                    aria-pressed="<?= $isActive ? 'true' : 'false' ?>"><?= esc($label) ?></button>
        <?php endforeach; ?>
    </div>
</form>
