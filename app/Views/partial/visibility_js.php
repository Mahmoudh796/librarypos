<?php // used in reports ?>
// Utility functions for safe localStorage access
function safeSetItem(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        console.error(`Failed to set item in localStorage: ${e.message}`);
    }
}

function safeGetItem(key) {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        console.error(`Failed to get item from localStorage: ${e.message}`);
        return null; // Default fallback
    }
}

function safeRemoveItem(key) {
    try {
        localStorage.removeItem(key);
    } catch (e) {
        console.error(`Failed to remove item from localStorage: ${e.message}`);
    }
}

// Load saved column visibility from localStorage.
// A first-time visitor (no stored state) sees cost/profit columns and
// summary rows until the toggle hides them.
const savedVisibility = JSON.parse(safeGetItem('columnVisibility')) || { cost: true, profit: true };
let visibleColumns = savedVisibility;

// Function to save column visibility to localStorage
function saveColumnVisibility(visibility) {
    safeSetItem('columnVisibility', JSON.stringify(visibility));
}

// Apply saved column visibility on table initialization.
// The bootstrapTable is created with these columns pre-filtered by the
// caller, so only refresh an already-initialized table here.
function applyColumnVisibility(columns) {
    return columns.map(function (col) {
        if (visibleColumns[col.field] !== undefined) {
            col.visible = visibleColumns[col.field]; // Apply visibility from localStorage
        }
        return col;
    });
}

function isTableInitialized() {
    return $('#table').data('bootstrap.table') !== undefined;
}

// Event listener for column visibility toggle
$('#table').on('column-switch.bs.table', function (e, field, checked) {
    visibleColumns[field] = checked; // Save the visibility of this column
    saveColumnVisibility(visibleColumns); // Store it in localStorage
});

// Ensure that saved column visibility is applied immediately after table load
if (isTableInitialized()) {
    $('#table').bootstrapTable('refreshOptions', {
        columns: $('#table').bootstrapTable('getOptions').columns // Force refresh to apply column visibility
    });
}

// Initialize visibility settings from localStorage
let summaryVisibility = JSON.parse(safeGetItem('summaryVisibility')) || { cost: true, profit: true };

// A summary row is a cost/profit row when its data-key mentions either term.
function summaryRowState(key) {
    if (/cost/.test(key)) {
        return summaryVisibility.cost;
    }
    if (/profit/.test(key)) {
        return summaryVisibility.profit;
    }
    return true;
}

// Function to apply visibility for cost and profit rows
function applySummaryVisibility() {
    $('.ruya-kpis .ruya-kpi[data-key]').each(function () {
        summaryRowState($(this).data('key')) ? $(this).show() : $(this).hide();
    });
}

// Toggle visibility when the button is clicked
$('#toggleCostProfitButton').click(function () {
    summaryVisibility.cost = !summaryVisibility.cost;
    summaryVisibility.profit = !summaryVisibility.profit;

    safeSetItem('summaryVisibility', JSON.stringify(summaryVisibility));
    applySummaryVisibility();

    // Mirror the state on matching cost/profit columns of the report table
    if (isTableInitialized()) {
        ['cost', 'profit'].forEach(function (field) {
            if ($('#table th[data-field="' + field + '"]').length > 0) {
                visibleColumns[field] = summaryVisibility[field];
                if (summaryVisibility[field]) {
                    $('#table').bootstrapTable('showColumn', field);
                } else {
                    $('#table').bootstrapTable('hideColumn', field);
                }
            }
        });
        saveColumnVisibility(visibleColumns);
    }
});

// Apply saved visibility state on page load
applySummaryVisibility();

// Initialize dialog (if editable)
const init_dialog = function () {
<?php if (isset($editable)): ?>
        table_support.submit_handler('<?php echo site_url("reports/get_detailed_{$editable}_row") ?>');
    dialog_support.init("a.modal-dlg");
<?php endif; ?>
};
