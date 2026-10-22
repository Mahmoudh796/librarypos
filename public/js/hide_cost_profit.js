/**
 * public/js/hide_cost_profit.js
 * toggle cost and profit in graphical report.
 */
$(function () {
    const safeSetItem = (key, value) => {
        try {
            localStorage.setItem(key, value);
        } catch (e) {
            console.error("Storage error", e);
        }
    };

    const safeGetItem = (key) => {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    };

    // A first-time visitor (no stored state) sees every summary row until the
    // toggle hides the cost/profit ones.
    let summaryVisibility = JSON.parse(safeGetItem("summaryVisibility")) || {
        cost: true,
        profit: true,
    };

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

    function applySummaryVisibility() {
        $("#chart_report_summary .summary_row[data-key]").each(function () {
            summaryRowState($(this).data("key")) ? $(this).show() : $(this).hide();
        });
    }

    $("#toggleCostProfitButton").on("click", function () {
        summaryVisibility.cost = !summaryVisibility.cost;
        summaryVisibility.profit = !summaryVisibility.profit;
        safeSetItem("summaryVisibility", JSON.stringify(summaryVisibility));
        applySummaryVisibility();
    });

    applySummaryVisibility();
});
