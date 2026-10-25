<?php
/**
 * @var ?array $entry
 * @var list<array> $lines
 * @var bool $can_reverse
 */
?>
<?= view('partial/header') ?>

<div class="dj-page-head">
    <?= view('accounting/partials/subnav', ['active' => 'journal', 'leaf' => $entry ? $entry['entry_number'] : lang('Accounting.journal_entry')]) ?>
    <h1 class="dj-page-title"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> <?= lang('Accounting.journal_entry') ?> <?= $entry ? '<span class="dj-ltr">' . esc($entry['entry_number']) . '</span>' : '' ?></h1>
    <?= view('partial/page_nav') ?>
</div>


<?php if ($entry === null): ?>
    <div class="dj-empty-state"><?= lang('Accounting.entry_not_found') ?></div>
<?php else: ?>
    <dl class="dl-horizontal dj-meta-list">
        <dt><?= lang('Accounting.date') ?></dt><dd class="dj-num"><?= esc($entry['entry_date']) ?></dd>
        <dt><?= lang('Accounting.source') ?></dt><dd><?= lang('Accounting.source_' . ($entry['source_type'] ?? 'manual')) ?><?= $entry['source_id'] ? ' <span class="dj-ltr">#' . (int) $entry['source_id'] . '</span>' : '' ?></dd>
        <dt><?= lang('Accounting.reference') ?></dt><dd class="dj-ltr"><?= esc($entry['reference']) ?></dd>
        <dt><?= lang('Accounting.description') ?></dt><dd dir="auto"><?= esc($entry['description']) ?></dd>
        <dt><?= lang('Accounting.employee') ?></dt><dd><?= esc(trim($entry['first_name'] . ' ' . $entry['last_name'])) ?></dd>
        <?php if ($entry['reverses_entry_id'] !== null): ?>
            <dt><?= lang('Accounting.reverses') ?></dt><dd><a class="dj-ltr" href="<?= esc(base_url('journal/view/' . $entry['reverses_entry_id']), 'attr') ?>">#<?= (int) $entry['reverses_entry_id'] ?></a></dd>
        <?php endif; ?>
        <?php if ($entry['reversed_by_entry_id'] !== null): ?>
            <dt><?= lang('Accounting.reversed_by') ?></dt><dd><a class="dj-ltr" href="<?= esc(base_url('journal/view/' . $entry['reversed_by_entry_id']), 'attr') ?>">#<?= (int) $entry['reversed_by_entry_id'] ?></a></dd>
        <?php endif; ?>
    </dl>

    <div class="table-responsive">
        <table class="table table-bordered table-condensed dj-ledger-table">
            <thead>
                <tr>
                    <th><?= lang('Accounting.account') ?></th>
                    <th><?= lang('Accounting.description') ?></th>
                    <th><?= lang('Accounting.party') ?></th>
                    <th><?= lang('Accounting.dimensions') ?></th>
                    <th class="text-right"><?= lang('Accounting.debit') ?></th>
                    <th class="text-right"><?= lang('Accounting.credit') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><span class="dj-ltr"><?= esc($line['code']) ?></span> <?= esc($line['name']) ?></td>
                        <td dir="auto"><?= esc($line['description']) ?></td>
                        <td><?= $line['party_type'] ? esc(lang('Accounting.party_' . $line['party_type'])) . ' <span class="dj-ltr">#' . (int) $line['party_id'] . '</span>' : '' ?></td>
                        <td><?= esc(implode('، ', array_map(static fn ($d) => $d['dimension'] . ': ' . $d['value'], $line['dimensions']))) ?></td>
                        <td class="text-right dj-num"><?= (float) $line['debit'] != 0.0 ? to_currency($line['debit']) : '' ?></td>
                        <td class="text-right dj-num"><?= (float) $line['credit'] != 0.0 ? to_currency($line['credit']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="dj-total-row">
                    <th colspan="4"><?= lang('Accounting.total') ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($lines, 'debit'))) ?></th>
                    <th class="text-right dj-num"><?= to_currency((string) array_sum(array_column($lines, 'credit'))) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($can_reverse): ?>
        <button type="button" class="btn btn-warning btn-sm print_hide" id="reverse_entry"><span class="glyphicon glyphicon-repeat" aria-hidden="true"></span> <?= lang('Accounting.reverse') ?></button>
        <script type="text/javascript">
            $('#reverse_entry').on('click', function() {
                const reason = window.prompt(<?= json_encode(lang('Accounting.reverse_reason_prompt')) ?>);
                if (reason === null) {
                    return;
                }
                $.post('<?= site_url('journal/reverse/' . (int) $entry['entry_id']) ?>', { reason: reason }, function(response) {
                    $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
                    if (response.success) {
                        window.location.href = '<?= site_url('journal/view') ?>/' + response.id;
                    }
                }, 'json');
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?= view('partial/footer') ?>
