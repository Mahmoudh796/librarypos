<?php
/**
 * @var object $user_info
 * @var array $allowed_modules
 * @var CodeIgniter\HTTP\IncomingRequest $request
 * @var array $config
 */

use App\Models\Employee;
use App\Models\Module;
use Config\Services;

$request = Services::request();

/*
 * The menu is grouped by business workflow (sell, buy, stock, treasury, costs,
 * accounting, reports, administration) instead of one entry per OSPOS module.
 * Every entry is permission-filtered; a module or screen the employee is not
 * granted is never offered, and the controllers enforce the same grants.
 */
$uri = $request->getUri();
$activeSegment = (string) $uri->getSegment(1);
$activeSegment2 = $uri->getTotalSegments() >= 2 ? (string) $uri->getSegment(2) : '';

$allowedModuleById = [];
foreach ($allowed_modules ?? [] as $allowedModule) {
    $allowedModuleById[$allowedModule->module_id] = $allowedModule;
}

/*
 * A grant carries the menu group it was created in and Secure_Controller only
 * publishes the group the current session is on, so whole destinations —
 * Dashboard, Employees, Settings, Taxes — vanished from the header depending on
 * which page you arrived from, while the operations modules vanished the moment
 * you opened an office page. The header therefore reads both groups of the
 * employee's own grants and works from that single list. The rows are still
 * filtered per person and per permission, so this only decides where an
 * authorised module appears, never whether it is reachable; nothing is removed
 * and no grant is broadened.
 */
$djModuleModel = model(Module::class);
$djPersonId    = (int) ($user_info->person_id ?? 0);

$djGrantGroups = [
    $djModuleModel->get_allowed_home_modules($djPersonId)->getResult(),
    $djModuleModel->get_allowed_office_modules($djPersonId)->getResult(),
];

foreach ($djGrantGroups as $djGrantGroup) {
    foreach ($djGrantGroup as $djGrantedModule) {
        if (! isset($allowedModuleById[$djGrantedModule->module_id])) {
            $allowedModuleById[$djGrantedModule->module_id] = $djGrantedModule;
        }
    }
}

helper('navigation');
// One query for every grant check in the menu instead of one per entry.
$djGrantIds = array_flip(array_column(model(Employee::class)->get_employee_grants($djPersonId), 'permission_id'));
$djHasGrant = static fn (string $grant): bool => isset($djGrantIds[$grant]);
$djNavGroups = ruya_navigation(array_keys($allowedModuleById), $djHasGrant);

/*
 * Administration. Modules that no business group covers are appended to the
 * system section, so an unknown or newly added module stays reachable instead
 * of silently disappearing from the header.
 */
$djCoveredModules = ['home', 'sales', 'customers', 'giftcards', 'receivings', 'suppliers', 'reports', 'items', 'item_kits', 'attributes', 'treasury', 'cashups', 'expenses', 'expenses_categories', 'accounting'];
$djAdminGroupPlan = [
    'team'   => ['employees'],
    'comms'  => ['messages'],
    'system' => ['taxes', 'config', 'office'],
];

$djAdminGroups = [];
foreach ($djAdminGroupPlan as $groupId => $groupModuleIds) {
    foreach ($groupModuleIds as $moduleId) {
        if (isset($allowedModuleById[$moduleId])) {
            $djAdminGroups[$groupId][] = ['href' => $moduleId, 'label' => 'Module.' . $moduleId];
        }
    }
}

foreach (array_keys($allowedModuleById) as $moduleId) {
    if (!in_array($moduleId, $djCoveredModules, true) && !in_array($moduleId, array_merge(...array_values($djAdminGroupPlan)), true)) {
        $djAdminGroups['system'][] = ['href' => $moduleId, 'label' => 'Module.' . $moduleId];
    }
}

/*
 * The database backup page is not a grantable module of its own; it is an
 * administration surface, so it shares the `config` grant and is only offered
 * when that grant is present. The audit trail needs the accounting setup grant.
 */
$canAdminister = isset($allowedModuleById['config']);
if ($canAdminister) {
    $djAdminGroups['system'][] = ['href' => 'backups', 'label' => 'Common.backup'];
}
if (isset($allowedModuleById['accounting']) && $djHasGrant('accounting_setup')) {
    $djAdminGroups['system'][] = ['href' => 'accounting/audit', 'label' => 'Accounting.audit_trail'];
}

$adminIsActive = false;
foreach ($djAdminGroups as $djItems) {
    foreach ($djItems as $djItem) {
        $adminIsActive = $adminIsActive || ruya_nav_is_active($djItem['href'], $activeSegment, $activeSegment2);
    }
}
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= get_text_direction() ?>">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= base_url() ?>">
    <title><?= esc($config['company']) . ' | ' . esc(lang('Common.software_short')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <?php $theme = (empty($config['theme']) ? 'flatly' : esc($config['theme'])); ?>
    <link rel="stylesheet" href="resources/bootswatch/<?= "$theme" ?>/bootstrap.min.css">

    <?php if (ENVIRONMENT == 'development' || get_cookie('debug') == 'true' || $request->getGet('debug') == 'true') : ?>
        <!-- inject:debug:css -->
        <link rel="stylesheet" href="resources/css/jquery-ui-fe010342cb.css">
        <link rel="stylesheet" href="resources/css/bootstrap-dialog-1716ef6e7c.css">
        <link rel="stylesheet" href="resources/css/jasny-bootstrap-40bf85f3ed.css">
        <link rel="stylesheet" href="resources/css/bootstrap-datetimepicker-66374fba71.css">
        <link rel="stylesheet" href="resources/css/bootstrap-select-66d5473b84.css">
        <link rel="stylesheet" href="resources/css/bootstrap-table-ed9d1a3360.css">
        <link rel="stylesheet" href="resources/css/bootstrap-table-sticky-header-07d65e7533.css">
        <link rel="stylesheet" href="resources/css/daterangepicker-85523b7dfe.css">
        <link rel="stylesheet" href="resources/css/chartist-c19aedb81a.css">
        <link rel="stylesheet" href="resources/css/chartist-plugin-tooltip-2e0ec92e60.css">
        <link rel="stylesheet" href="resources/css/bootstrap-tagsinput-5a6d46a06c.css">
        <link rel="stylesheet" href="resources/css/bootstrap-toggle-e12db6c1f3.css">
        <link rel="stylesheet" href="resources/css/bootstrap-292fc0ad3b.autocomplete.css">
        <link rel="stylesheet" href="resources/css/invoice-1eae5e39b9.css">
        <link rel="stylesheet" href="resources/css/ospos_print-2ba645b044.css">
        <link rel="stylesheet" href="resources/css/ospos-40ea746d31.css">
        <link rel="stylesheet" href="resources/css/popupbox-7b616030b0.css">
        <link rel="stylesheet" href="resources/css/receipt-a171207d8e.css">
        <link rel="stylesheet" href="resources/css/register-32d6791f29.css">
        <link rel="stylesheet" href="resources/css/reports-407b727797.css">
        <!-- endinject -->
        <!-- inject:debug:js -->
        <script src="resources/js/jquery-12e87d2f3a.js"></script>
        <script src="resources/js/jquery-4fa896f615.form.js"></script>
        <script src="resources/js/jquery-a0350e8820.validate.js"></script>
        <script src="resources/js/jquery-ui-cbc65ff85e.js"></script>
        <script src="resources/js/bootstrap-894d79839f.js"></script>
        <script src="resources/js/bootstrap-dialog-27123abb65.js"></script>
        <script src="resources/js/jasny-bootstrap-7c6d7b8adf.js"></script>
        <script src="resources/js/bootstrap-datetimepicker-25e39b7ef8.js"></script>
        <script src="resources/js/bootstrap-select-b01896a67b.js"></script>
        <script src="resources/js/bootstrap-table-bdb06552ea.js"></script>
        <script src="resources/js/bootstrap-table-export-6389dc2aa5.js"></script>
        <script src="resources/js/bootstrap-table-mobile-fc655b68ab.js"></script>
        <script src="resources/js/bootstrap-table-sticky-header-cb4d83d172.js"></script>
        <script src="resources/js/moment-ad3346f890.min.js"></script>
        <script src="resources/js/daterangepicker-048c56a690.js"></script>
        <script src="resources/js/es6-promise-855125e6f5.js"></script>
        <script src="resources/js/FileSaver-e73b1946e8.js"></script>
        <script src="resources/js/html2canvas-e1d3a8d7cd.js"></script>
        <script src="resources/js/jspdf-bbbebb610c.umd.js"></script>
        <script src="resources/js/purify-d54e267e45.js"></script>
        <script src="resources/js/jspdf-92d87e47e8.plugin.autotable.js"></script>
        <script src="resources/js/xlsx-6b3130af1c.full.min.js"></script>
        <script src="resources/js/tableExport-3d506dfa61.min.js"></script>
        <script src="resources/js/chartist-8a7ecb4445.js"></script>
        <script src="resources/js/chartist-plugin-pointlabels-0a1ab6aa4e.js"></script>
        <script src="resources/js/chartist-plugin-tooltip-116cb48831.js"></script>
        <script src="resources/js/chartist-plugin-axistitle-80a1198058.js"></script>
        <script src="resources/js/chartist-plugin-barlabels-4165273742.js"></script>
        <script src="resources/js/bootstrap-notify-376bc6eb87.js"></script>
        <script src="resources/js/bootstrap-tagsinput-855a7c7670.js"></script>
        <script src="resources/js/bootstrap-toggle-1c7a19a049.js"></script>
        <script src="resources/js/clipboard-908af414ab.js"></script>
        <script src="resources/js/imgpreview-62e42c15a0.full.jquery.js"></script>
        <script src="resources/js/manage_tables-d407fc802d.js"></script>
        <script src="resources/js/nominatim-becb3f84b2.autocomplete.js"></script>
        <!-- endinject -->
    <?php else : ?>
        <!--inject:prod:css -->
        <link rel="stylesheet" href="resources/opensourcepos-8d27f4a995.min.css">
        <!-- endinject -->

        <!-- Tweaks to the UI for a particular theme should drop here  -->
        <?php if ($config['theme'] != 'flatly' && file_exists($_SERVER['DOCUMENT_ROOT'] . '/public/css/' . esc($config['theme']) . '.css')) { ?>
            <link rel="stylesheet" href="<?= 'css/' . esc($config['theme']) . '.css' ?>">
        <?php } ?>
        <!-- inject:prod:js -->
        <script src="resources/jquery-2c872dbe60.min.js"></script>
        <script src="resources/opensourcepos-ed50d739b8.min.js"></script>
        <!-- endinject -->
    <?php endif; ?>

    <!-- Theme overrides load after the bundled application stylesheets so they win the cascade. -->
    <?php
    /*
     * The build fingerprints the bundled stylesheets but not these two hand-written
     * layers, so version them by modification time to avoid stale CSS after a deploy.
     */
    $dj_theme_version = static function (string $file): string {
        return defined('FCPATH') && is_file(FCPATH . $file) ? '?v=' . filemtime(FCPATH . $file) : '';
    };
    ?>
    <link rel="stylesheet" href="css/dahrouj.css<?= $dj_theme_version('css/dahrouj.css') ?>">
    <link rel="stylesheet" href="css/direction.css<?= $dj_theme_version('css/direction.css') ?>">
    <link rel="stylesheet" href="css/rtl.css<?= $dj_theme_version('css/rtl.css') ?>">
    <link rel="stylesheet" href="css/ruya.css<?= $dj_theme_version('css/ruya.css') ?>">

    <?= view('partial/header_js') ?>
    <script src="js/nav_history.js"></script>
    <script src="js/ruya.js<?= $dj_theme_version('js/ruya.js') ?>"></script>
    <?= view('partial/lang_lines') ?>

    <style>
        html {
            overflow: auto;
        }
    </style>
</head>

<body>
    <?php
    $employeeDisplayName = trim((string)$user_info->first_name . ' ' . (string)$user_info->last_name);
    $employeeInitials    = mb_strtoupper(mb_substr((string)$user_info->first_name, 0, 1) . mb_substr((string)$user_info->last_name, 0, 1));
    ?>
    <div class="wrapper">
        <header class="dj-header">
            <div class="dj-header-inner">
                <a class="dj-brand" href="<?= site_url() ?>" title="<?= esc($config['company'], 'attr') ?>">
                    <span class="dj-brand-mark" lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(get_text_direction(), 'attr') ?>"><?= esc(lang('Common.software_short')) ?></span>
                    <span class="dj-brand-sub"><?= esc(lang('Common.software_descriptor')) ?></span>
                </a>

                <button type="button" class="dj-nav-toggle collapsed" data-toggle="collapse" data-target="#dj-primary-nav" aria-expanded="false" aria-controls="dj-primary-nav" aria-label="<?= esc(lang('Bootstrap_tables.toggle'), 'attr') ?>">
                    <span class="dj-nav-toggle-bar"></span>
                    <span class="dj-nav-toggle-bar"></span>
                    <span class="dj-nav-toggle-bar"></span>
                </button>

                <nav class="dj-nav collapse" id="dj-primary-nav">
                    <ul class="dj-nav-list">
                        <?php if (isset($allowedModuleById['home'])): ?>
                            <?php $isActiveModule = $activeSegment === 'home' || $activeSegment === ''; ?>
                            <li class="dj-nav-item">
                                <a class="dj-nav-link dj-nav-link--home<?= $isActiveModule ? ' is-active' : '' ?>" href="<?= base_url('home') ?>" title="<?= esc(lang('Common.dashboard'), 'attr') ?>"<?= $isActiveModule ? ' aria-current="page"' : '' ?>>
                                    <span class="glyphicon glyphicon-dashboard" aria-hidden="true"></span>
                                    <?= lang('Common.dashboard') ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php foreach ($djNavGroups as $groupId => $group): ?>
                            <?php
                            $groupIsActive = false;
                            foreach ($group['items'] as $item) {
                                $groupIsActive = $groupIsActive || (isset($item['href']) && ruya_nav_is_active($item['href'], $activeSegment, $activeSegment2));
                            }
                            ?>
                            <li class="dj-nav-item dropdown dj-nav-group dj-nav-group--<?= esc($groupId, 'attr') ?><?= $groupIsActive ? ' is-active' : '' ?>">
                                <a class="dj-nav-link dropdown-toggle<?= $groupIsActive ? ' is-active' : '' ?>" href="#" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                                    <span class="dj-nav-label"><?= lang($group['label']) ?></span><span class="caret" aria-hidden="true"></span>
                                </a>
                                <ul class="dropdown-menu dj-nav-overflow">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <?php if (isset($item['heading'])): ?>
                                            <li class="dj-nav-group-heading" role="presentation"><?= esc(lang($item['heading'])) ?></li>
                                            <?php continue; ?>
                                        <?php endif; ?>
                                        <?php $isActiveItem = ruya_nav_is_active($item['href'], $activeSegment, $activeSegment2); ?>
                                        <li<?= $isActiveItem ? ' class="active"' : '' ?>>
                                            <a href="<?= base_url($item['href']) ?>"<?= $isActiveItem ? ' aria-current="page"' : '' ?>><?= lang($item['label']) ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>

                        <?php if (isset($allowedModuleById['reports'])): ?>
                            <?php $isActiveModule = $activeSegment === 'reports' && $activeSegment2 !== 'detailed_receivings' && $activeSegment2 !== 'inventory_low'; ?>
                            <li class="dj-nav-item">
                                <a class="dj-nav-link<?= $isActiveModule ? ' is-active' : '' ?>" href="<?= base_url('reports') ?>"<?= $isActiveModule ? ' aria-current="page"' : '' ?>><?= lang('Module.reports') ?></a>
                            </li>
                        <?php endif; ?>

                        <?php if ($djAdminGroups !== []): ?>
                            <li class="dj-nav-item dropdown dj-nav-admin<?= $adminIsActive ? ' is-active' : '' ?>">
                                <a class="dj-nav-link dropdown-toggle" href="#" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false" title="<?= esc(lang('Common.admin'), 'attr') ?>">
                                    <span class="glyphicon glyphicon-cog" aria-hidden="true"></span>
                                    <span class="dj-nav-label"><?= lang('Common.admin') ?></span><span class="caret" aria-hidden="true"></span>
                                </a>
                                <ul class="dropdown-menu dj-nav-overflow">
                                    <?php foreach ($djAdminGroups as $adminGroupId => $adminItems): ?>
                                        <li class="dj-nav-group-heading" role="presentation"><?= esc(lang('Common.admin_group_' . $adminGroupId)) ?></li>
                                        <?php foreach ($adminItems as $item): ?>
                                            <?php $isActiveItem = ruya_nav_is_active($item['href'], $activeSegment, $activeSegment2); ?>
                                            <li<?= $isActiveItem ? ' class="active"' : '' ?>>
                                                <a href="<?= base_url($item['href']) ?>"<?= $isActiveItem ? ' aria-current="page"' : '' ?>><?= lang($item['label']) ?></a>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>


                <div class="dj-header-actions">
                    <div class="dj-clock">
                        <span class="glyphicon glyphicon-time" aria-hidden="true"></span>
                        <span id="liveclock"><?= date($config['dateformat'] . ' ' . $config['timeformat']) ?></span>
                    </div>

                    <div class="dropdown dj-account">
                        <button type="button" class="dj-account-toggle dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="dj-account-avatar" aria-hidden="true"><?= esc($employeeInitials) ?></span>
                            <span class="dj-account-name"><?= esc($employeeDisplayName) ?></span>
                            <span class="caret" aria-hidden="true"></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-right dj-account-menu">
                            <div class="dj-account-menu-header">
                                <span class="dj-account-menu-name"><?= esc($employeeDisplayName) ?></span>
                            </div>

                            <div class="dj-account-lang">
                                <?= view('partial/language_switcher', [
                                    'activeLocale' => current_language_code(),
                                    'redirectPath' => ltrim($request->getUri()->getPath(), '/'),
                                ]) ?>
                            </div>

                            <ul class="dj-account-links">
                                <li>
                                    <?= anchor("home/changePassword/$user_info->person_id", '<span class="glyphicon glyphicon-lock" aria-hidden="true"></span>' . lang('Employees.change_password'), ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Employees.change_password')]) ?>
                                </li>
                                <li>
                                    <?= anchor('home/logout', '<span class="glyphicon glyphicon-log-out" aria-hidden="true"></span>' . lang('Login.logout')) ?>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main id="dj-main" class="container">
            <div class="row">
