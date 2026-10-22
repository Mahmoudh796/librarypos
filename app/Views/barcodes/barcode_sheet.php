<?php
/**
 * @var array $barcode_config
 * @var array $items
 */

use App\Libraries\Barcode_lib;

$barcode_lib = new Barcode_lib();
?>

<!doctype html>
<html lang="<?= current_language_code() ?>">

<head>
    <meta charset="utf-8">
    <title><?= esc(lang('Items.generate_barcodes')) ?></title>
    <link rel="stylesheet" href="<?= esc(base_url('css/barcode_font.css')) ?>">
    <style>
        /* Label-sheet presentation only: the barcode encoding, size and the
         * configurable cell spacing/page width behave exactly as configured. */
        @page {
            margin: 10mm;
        }

        body {
            margin: 0;
            padding: 8px;
            background-color: #ffffff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Screen-only navigation bar: deterministic return to the Items module
         * and a print action, both suppressed on the printed sheet. */
        .barcode-sheet-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            max-width: 98%;
            margin: 0 auto 10px;
            padding: 8px 12px;
            border: 1px solid #d5dde5;
            border-radius: 6px;
            background: #f7fafc;
            font-family: "Segoe UI", Tahoma, sans-serif;
            font-size: 14px;
        }

        .barcode-sheet-bar a {
            color: #0b6e6e;
            font-weight: 600;
            text-decoration: none;
        }

        .barcode-sheet-bar a:hover,
        .barcode-sheet-bar a:focus {
            text-decoration: underline;
        }

        .barcode-sheet-bar button {
            padding: 5px 14px;
            border: 1px solid #0b6e6e;
            border-radius: 4px;
            background: #0b6e6e;
            color: #ffffff;
            font: inherit;
            cursor: pointer;
        }

        @media print {
            .barcode-sheet-bar {
                display: none;
            }
        }

        table {
            border-collapse: separate;
        }

        td > table > tbody > tr > td {
            padding: 2px 6px;
            vertical-align: middle;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        .barcode {
            display: block;
            line-height: 0;
        }

        .barcode svg {
            display: block;
            height: <?= (int) $barcode_config['barcode_height'] ?>px;
            width: <?= (int) $barcode_config['barcode_width'] ?>px;
        }
    </style>
</head>

<body class="<?= esc('font_' . $barcode_lib->get_font_name($barcode_config['barcode_font']), 'attr') ?>" style="font-size: <?= (int) $barcode_config['barcode_font_size'] ?>px;">
    <div class="barcode-sheet-bar">
        <a href="<?= esc(site_url('items')) ?>"><?= esc(lang('Common.back_to') . ' ' . lang('Module.items')) ?></a>
        <button type="button" onclick="window.print()"><?= esc(lang('Common.print')) ?></button>
    </div>
    <table style="border-spacing: <?= (int) $barcode_config['barcode_page_cellspacing'] ?>px; width: <?= (int) $barcode_config['barcode_page_width'] ?>%;">
        <tr>
            <?php
                $count = 0;
                foreach ($items as $item) {
                    if ($count % $barcode_config['barcode_num_in_row'] == 0 && $count != 0) {
                        echo '</tr><tr>';
                    }
                    echo '<td class="barcode-label">' . $barcode_lib->display_barcode($item, $barcode_config) . '</td>';
                    $count++;
                }
            ?>
        </tr>
    </table>
</body>

</html>
