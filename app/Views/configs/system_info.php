<?php
/**
 * @var string $dbVersion
 * @var array $config
 */

use Config\OSPOS;

/*
 * Diagnostics page: read-only reporting of the running stack. Rows are
 * data-driven so every section renders the same calm meta list, statuses are
 * text-plus-colour (never colour-only), and the POSIX permission verdicts run
 * only where POSIX semantics exist — on Windows they would report misleading
 * octal values, so ACL-managed directories simply report writability.
 */
$isWindows = PHP_OS_FAMILY === 'Windows';

$appTimezone = !empty($config['timezone']) ? (string) $config['timezone'] : date_default_timezone_get();
$phpIniTimezone = ini_get('date.timezone') ?: '(unset)';

$filesystemPaths = [
    WRITEPATH . 'logs/'                => '[writable/logs]',
    FCPATH . 'uploads/'                => '[public/uploads]',
    FCPATH . 'uploads/item_pics/'      => '[public/uploads/item_pics]',
];

$importTemplate = WRITEPATH . 'uploads/importCustomers.csv'; // TODO: This variable does not follow naming conventions for the project.

$filesystemRows = [];

foreach ($filesystemPaths as $path => $label) {
    $perms = decoct(fileperms($path) ?: 0);
    $writable = is_writable($path);

    if ($isWindows) {
        $filesystemRows[$label] = [
            'value'   => $writable ? lang('Config.diag_writable') : lang('Config.diag_not_writable'),
            'status'  => $writable ? 'ok' : 'attention',
        ];
    } else {
        $secure = $writable && substr($perms, -4) === '750';
        $filesystemRows[$label] = [
            'value'   => $perms . ' · ' . ($writable ? lang('Config.diag_writable') : lang('Config.diag_not_writable')),
            'status'  => $secure ? 'ok' : 'attention',
        ];
    }

    clearstatcache();
}

if (is_readable($importTemplate)) {
    $perms = decoct(fileperms($importTemplate) ?: 0);

    if ($isWindows) {
        $filesystemRows['[importCustomers.csv]'] = [
            'value'  => lang('Config.diag_writable'),
            'status' => 'ok',
        ];
    } else {
        $secure = (substr($perms, -4) === '640' || substr($perms, -4) === '660');
        $filesystemRows['[importCustomers.csv]'] = [
            'value'  => $perms . ' · ' . lang('Config.diag_writable'),
            'status' => $secure ? 'ok' : 'attention',
        ];
    }
} else {
    $filesystemRows['[importCustomers.csv]'] = [
        'value'  => lang('Config.diag_not_present'),
        'status' => 'neutral',
    ];
}

clearstatcache();
?>

<script type="text/javascript" src="js/clipboard.min.js"></script>

<div id="issuetemplate" class="dj-diag">
    <p><?= lang('Config.server_notice') ?></p>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-book" aria-hidden="true"></span><?= lang('Config.diag_application') ?></h2>
        </div>
        <dl class="dj-meta-list">
            <dt><?= lang('Common.software_short') ?></dt>
            <dd><span class="dj-ltr"><?= esc(config(OSPOS::class)->commit_sha1) ?></span></dd>
            <dt><?= lang('Config.language') ?></dt>
            <dd><span class="dj-ltr"><?= esc(current_language_code()) ?></span></dd>
        </dl>
    </div>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-cog" aria-hidden="true"></span><?= lang('Config.diag_runtime') ?></h2>
        </div>
        <dl class="dj-meta-list">
            <dt><?= lang('Config.diag_php_version') ?></dt>
            <dd><span class="dj-ltr"><?= PHP_VERSION ?></span></dd>
            <dt><?= lang('Config.diag_server_software') ?></dt>
            <dd><span class="dj-ltr"><?= esc($_SERVER['SERVER_SOFTWARE'] ?? '') ?></span></dd>
            <dt><?= lang('Config.diag_operating_system') ?></dt>
            <dd><span class="dj-ltr"><?= esc(php_uname('s') . ' ' . php_uname('r')) ?></span></dd>
            <dt><?= lang('Config.diag_server_port') ?></dt>
            <dd><span class="dj-ltr"><?= esc($_SERVER['SERVER_PORT'] ?? '') ?></span></dd>
            <dt><?= lang('Config.diag_browser') ?></dt>
            <dd><span class="dj-ltr"><?= esc(getBrowserNameAndVersion($_SERVER['HTTP_USER_AGENT'] ?? '')) ?></span></dd>
        </dl>
    </div>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-hdd" aria-hidden="true"></span><?= lang('Config.diag_database') ?></h2>
        </div>
        <dl class="dj-meta-list">
            <dt><?= lang('Config.diag_db_version') ?></dt>
            <dd><span class="dj-ltr"><?= esc($dbVersion) ?></span></dd>
        </dl>
    </div>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-th-large" aria-hidden="true"></span><?= lang('Config.diag_extensions') ?></h2>
        </div>
        <ul class="dj-diag-chips">
            <?php foreach (['gd' => 'GD', 'bcmath' => 'BC Math', 'intl' => 'intl', 'openssl' => 'OpenSSL', 'mbstring' => 'Multibyte String', 'curl' => 'cURL', 'json' => 'JSON', 'xml' => 'XML'] as $extensionName => $extensionLabel) { ?>
                <li<?= extension_loaded($extensionName) ? ' class="dj-diag-chip is-ok"' : ' class="dj-diag-chip is-attention"' ?>>
                    <span class="dj-diag-chip-mark"><?= extension_loaded($extensionName) ? '&#x2713;' : '&#x2717;' ?></span><?= $extensionLabel ?>
                </li>
            <?php } ?>
        </ul>
    </div>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-globe" aria-hidden="true"></span><?= lang('Config.diag_timezone') ?></h2>
        </div>
        <dl class="dj-meta-list">
            <dt><?= lang('Config.diag_app_timezone') ?></dt>
            <dd>
                <span class="dj-ltr"><?= esc($appTimezone) ?></span>
                <span class="dj-status <?= $appTimezone === 'Africa/Cairo' ? 'dj-status-normal' : 'dj-status-low' ?>">
                    <?= $appTimezone === 'Africa/Cairo' ? '&#x2713; ' . lang('Config.diag_status_healthy') : '! ' . lang('Config.diag_status_attention') ?>
                </span>
            </dd>
            <dt><?= lang('Config.diag_php_timezone') ?></dt>
            <dd>
                <span class="dj-ltr"><?= esc($phpIniTimezone) ?></span>
                <span class="dj-hint"><?= lang('Config.diag_php_timezone_note') ?></span>
            </dd>
            <dt><?= lang('Config.diag_browser_timezone') ?></dt>
            <dd id="timeerror"><span class="dj-hint"><?= lang('Config.diag_browser_timezone_note') ?></span></dd>
        </dl>
    </div>

    <div class="dj-panel">
        <div class="dj-section-head">
            <h2 class="dj-section-title"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span><?= lang('Config.diag_filesystem') ?></h2>
        </div>

        <?php if ($isWindows): ?>
            <p class="dj-hint"><?= lang('Config.diag_windows_note') ?></p>
        <?php endif; ?>

        <dl class="dj-meta-list">
            <?php foreach ($filesystemRows as $label => $row): ?>
                <dt><code class="dj-ltr"><?= esc($label) ?></code></dt>
                <dd>
                    <span class="dj-status <?= $row['status'] === 'ok' ? 'dj-status-normal' : 'dj-status-low' ?>">
                        <?= $row['status'] === 'ok' ? '&#x2713; ' . esc($row['value']) : '! ' . esc($row['value']) ?>
                    </span>
                </dd>
            <?php endforeach; ?>
        </dl>

        <?php if ($isWindows): ?>
            <p class="dj-hint"><?= lang('Config.diag_windows_summary') ?></p>
        <?php elseif (in_array('attention', array_column($filesystemRows, 'status'), true)): ?>
            <p class="dj-hint"><?= lang('Config.security_issue') ?> — <?= lang('Config.perm_risk') ?></p>
        <?php else: ?>
            <p class="dj-hint"><?= lang('Config.no_risk') ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-xs-12 text-center">
        <br>
        <a class="copy" role="button" data-clipboard-action="copy" data-clipboard-target="#issuetemplate"><?= lang('Config.diag_copy_info') ?></a>
        <script type="text/javascript">
            const clipboard = new ClipboardJS('.copy');

            clipboard.on('success', function(e) {
                document.getSelection().removeAllRanges();
            });

            /*
             * The browser timezone is informational: display and reporting run on
             * the application timezone, so a difference is a note, not a fault.
             */
            const userTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const appTimezone = '<?= esc($appTimezone, 'attr') ?>';

            if (userTimezone !== appTimezone) {
                document.getElementById('timeerror').innerHTML =
                    '<span class="dj-ltr"><strong>' + userTimezone + '</strong></span>' +
                    '<div class="dj-hint"><?= esc(lang('Config.diag_timezone_note'), 'attr') ?></div>';
            }
        </script>
    </div>
</div>

<?php
/**
 * @param string $userAgent
 * @return string
 */
function getBrowserNameAndVersion(string $userAgent): string
{
    $browser = match (true) {
        strpos($userAgent, 'Opera')   !== false || strpos($userAgent, 'OPR/') !== false => 'Opera',
        strpos($userAgent, 'Edge')    !== false => 'Edge',
        strpos($userAgent, 'Chrome')  !== false => 'Chrome',
        strpos($userAgent, 'Safari')  !== false => 'Safari',
        strpos($userAgent, 'Firefox') !== false => 'Firefox',
        strpos($userAgent, 'MSIE')    !== false || strpos($userAgent, 'Trident/7') !== false => 'Internet Explorer',
        default                       => 'Other',
    };

    return $browser;
}
