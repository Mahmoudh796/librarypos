<?php

namespace App\Controllers;

use App\Libraries\Accounting\Costing;
use App\Libraries\Accounting\Posting;
use App\Libraries\Barcode_lib;
use App\Libraries\Item_lib;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Stock_location;
use App\Models\Supplier;
use App\Models\Tax_category;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Images\Handlers\BaseHandler;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Validation\FormatRules;
use Config\Database;
use Config\OSPOS;
use Config\Services;
use Exception;
use ReflectionException;
use Throwable;

require_once('Secure_Controller.php');

class Items extends Secure_Controller
{
    private BaseConnection $db;
    private BaseHandler $image;
    private Barcode_lib $barcode_lib;
    private Item_lib $item_lib;
    private Attribute $attribute;
    private Category $category;
    private Inventory $inventory;
    private Item $item;
    private Item_kit $item_kit;
    private Item_quantity $item_quantity;
    private Item_taxes $item_taxes;
    private Stock_location $stock_location;
    private Supplier $supplier;
    private Tax_category $tax_category;
    private array $config;


    public function __construct()
    {
        parent::__construct('items');

        $this->db = Database::connect();

        $this->session = Services::session();

        $this->image = Services::image();

        $this->barcode_lib = new Barcode_lib();
        $this->item_lib = new Item_lib();

        $this->attribute = model(Attribute::class);
        $this->category = model(Category::class);
        $this->inventory = model(Inventory::class);
        $this->item = model(Item::class);
        $this->item_kit = model(Item_kit::class);
        $this->item_quantity = model(Item_quantity::class);
        $this->item_taxes = model(Item_taxes::class);
        $this->stock_location = model(Stock_location::class);
        $this->supplier = model(Supplier::class);
        $this->tax_category = model(Tax_category::class);
        $this->config = config(OSPOS::class)->settings;
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        $this->session->set('allow_temp_items', 0);

        $data['table_headers'] = getItemsManageTableHeaders();

        // Restore stock_location from URL or session
        $stockLocation = $this->request->getGet('stock_location', FILTER_SANITIZE_NUMBER_INT);
        $data['stock_location'] = $stockLocation
            ? $stockLocation
            : $this->item_lib->get_item_location();
        $data['stock_locations'] = $this->stock_location->get_allowed_locations();

        // Filters that will be loaded in the multiselect dropdown
        $data['filters'] = [
            'empty_upc'      => lang('Items.empty_upc_items'),
            'low_inventory'  => lang('Items.low_inventory_items'),
            'out_of_stock'   => lang('Items.out_of_stock_items'),
            'is_serialized'  => lang('Items.serialized_items'),
            'no_description' => lang('Items.no_description_items'),
            'search_custom'  => lang('Items.search_attributes'),
            'is_deleted'     => lang('Items.is_deleted'),
            'temporary'      => lang('Items.temp')
        ];

        $data['category_filter_options'] = ['' => lang('Items.all_categories')];
        foreach ($this->category->getAllWithCounts() as $category) {
            $data['category_filter_options'][$category['name']] = $category['name'];
        }

        $data['supplier_filter_options'] = ['' => lang('Items.all_suppliers')];
        foreach ($this->supplier->get_all()->getResultArray() as $row) {
            $data['supplier_filter_options'][$row['person_id']] = $row['company_name'];
        }

        $data['selected_category'] = (string) $this->request->getGet('category');
        $data['selected_supplier'] = (string) $this->request->getGet('supplier_id', FILTER_SANITIZE_NUMBER_INT);
        $data['can_manage_categories'] = $this->canManageCategories();

        // Restore filters from URL
        $data = array_merge($data, restoreTableFilters($this->request));

        return view('items/manage', $data);
    }

    /**
     * Returns Items table data rows. This will be called with AJAX.
     * @noinspection PhpUnused
     **/
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $limit = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $order = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $this->item_lib->set_item_location($this->request->getGet('stock_location'));

        $definitionNamesWithTypes = $this->attribute->getDefinitionsByFlags(Attribute::SHOW_IN_ITEMS, true);
        $definitionIds = array_keys($definitionNamesWithTypes);

        $sort = $this->sanitizeSortColumn(itemSortColumns($definitionIds), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'items.item_id');

        $filters = [
            'start_date'        => $this->request->getGet('start_date'),
            'end_date'          => $this->request->getGet('end_date'),
            'stock_location_id' => $this->item_lib->get_item_location(),
            'empty_upc'         => false,
            'low_inventory'     => false,
            'is_serialized'     => false,
            'no_description'    => false,
            'search_custom'     => false,
            'is_deleted'        => false,
            'temporary'         => false,
            'out_of_stock'      => false,
            'definition_ids'    => $definitionIds
        ];

        // Check if any filter is set in the multiselect dropdown
        $requestFilters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $requestFilters);

        // Exact-match filters, combined with the free-text search and each other.
        $filters['category'] = (string) ($this->request->getGet('category') ?? '');
        $supplierId = (int) $this->request->getGet('supplier_id', FILTER_SANITIZE_NUMBER_INT);
        $filters['supplier_id'] = $supplierId > 0 ? $supplierId : null;

        // When search_custom is enabled, include attributes that are searchable but may not be visible in table
        if (!empty($filters['search_custom'])) {
            $searchableDefinitions = $this->attribute->getDefinitionsByFlags(Attribute::SHOW_IN_ITEMS | Attribute::SHOW_IN_SEARCH);
            $filters['definition_ids'] = array_keys($searchableDefinitions);
        }

        $items = $this->item->search($search, $filters, $limit, $offset, $sort, $order);
        $itemResults = $items->getResult();
        $totalRows = $this->item->get_found_rows($search, $filters);
        $taxPercentsByItemId = $this->buildTaxPercentsByItem($itemResults);

        $dataRows = [];

        foreach ($itemResults as $item) {
            $dataRows[] = getItemDataRow($item, $definitionNamesWithTypes, $taxPercentsByItemId);

            if ($item->pic_filename !== null) {
                $this->update_pic_filename($item);
            }
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $dataRows]);
    }

    /**
     * @return array item_id => formatted tax percents string
     */
    private function buildTaxPercentsByItem(array $items): array
    {
        $config = config(OSPOS::class)->settings;
        $taxPercentsByItemId = [];

        if ($config['use_destination_based_tax']) {
            $taxCategoryIds = array_unique(array_filter(array_map(fn ($item) => $item->tax_category_id, $items)));

            if (empty($taxCategoryIds)) {
                return [];
            }

            $taxCategoryNames = [];
            foreach ($this->tax_category->get_multiple_info($taxCategoryIds)->getResult() as $taxCategoryInfo) {
                $taxCategoryNames[$taxCategoryInfo->tax_category_id] = $taxCategoryInfo->tax_category;
            }

            foreach ($items as $item) {
                if ($item->tax_category_id !== null && isset($taxCategoryNames[$item->tax_category_id])) {
                    $taxPercentsByItemId[$item->item_id] = $taxCategoryNames[$item->tax_category_id];
                }
            }
        } else {
            $itemIds = array_map(fn ($item) => $item->item_id, $items);
            $itemTaxesByItemId = $this->item_taxes->getInfoMultiple($itemIds);

            foreach ($itemIds as $itemId) {
                $taxPercents = '';
                foreach ($itemTaxesByItemId[$itemId] ?? [] as $taxInfo) {
                    $taxPercents .= to_tax_decimals($taxInfo['percent']) . '%, ';
                }

                // Remove ', ' from last item
                $taxPercents = substr($taxPercents, 0, -2);
                $taxPercentsByItemId[$itemId] = !$taxPercents ? '-' : $taxPercents;
            }
        }

        return $taxPercentsByItemId;
    }

    /**
     * AJAX function. Processes thumbnail of image. Called via tabular_helper
     * @param string $pic_filename
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getPicThumb(string $pic_filename): ResponseInterface
    {
        helper('file');

        // Security: Sanitize filename to prevent path traversal
        // Use basename() to strip directory components and prevent '../' attacks
        $pic_filename = basename(rawurldecode($pic_filename));
        $file_extension = strtolower(pathinfo($pic_filename, PATHINFO_EXTENSION));

        // Validate file extension against system-configured allowed image types
        // Handle both legacy pipe-separated and current comma-separated formats
        // Fallback to types that GD library can process for thumbnail generation
        $allowed_types = $this->config['image_allowed_types'] ?? 'jpg,jpeg,gif,png,webp,bmp,tif,tiff';
        $allowed_extensions = strpos($allowed_types, '|') !== false
            ? explode('|', $allowed_types)
            : explode(',', $allowed_types);

        if (!in_array($file_extension, $allowed_extensions, true)) {
            return $this->response->setStatusCode(400)->setBody('Invalid file type');
        }

        $images = glob("./uploads/item_pics/$pic_filename");
        $base_path = './uploads/item_pics/' . pathinfo($pic_filename, PATHINFO_FILENAME);

        if (sizeof($images) > 0) {
            $image_path = $images[0];
            $thumb_path = $base_path . "_thumb.$file_extension";

            if (sizeof($images) < 2 && !file_exists($thumb_path)) {
                $image = Services::image('gd2');
                $image->withFile($image_path)
                    ->resize(52, 32, true, 'height')
                    ->save($thumb_path);
            }

            $this->response->setContentType(mime_content_type($thumb_path));
            $this->response->setBody(file_get_contents($thumb_path));
        }

        return $this->response;
    }

    /**
     * Gives search suggestions based on what is being searched for
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function suggest_search(): ResponseInterface
    {
        $options = [
            'search_custom' => $this->request->getPost('search_custom'),
            'is_deleted'    => $this->request->getPost('is_deleted') !== null
        ];

        $search = $this->request->getPost('term');
        $suggestions = $this->item->get_search_suggestions($search, $options);

        return $this->response->setJSON($suggestions);
    }

    /**
     * AJAX Function used to get search suggestions from the model and return them in JSON format
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSuggest(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true);

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSuggestLowSell(): ResponseInterface
    {
        $suggestions = $this->item->get_low_sell_suggestions($this->request->getPostGet('name'));

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSuggestKits(): ResponseInterface
    {
        $suggestions = $this->item->get_kit_search_suggestions($this->request->getGet('term'), ['search_custom' => false, 'is_deleted' => false], true);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Gives search suggestions based on what is being searched for. Called from the view.
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSuggestCategory(): ResponseInterface
    {
        $suggestions = $this->item->get_category_suggestions((string) $this->request->getGet('term'));

        return $this->response->setJSON($suggestions);
    }

    /**
     * Approved category list with item counts. Viewable by anyone with the items
     * grant; changing it needs items_categories.
     * @noinspection PhpUnused
     */
    public function getCategories(): string
    {
        return view('items/categories', [
            'categories'            => $this->category->getAllWithCounts(),
            'can_manage_categories' => $this->canManageCategories(),
        ]);
    }

    /**
     * Creates a category, or renames/merges one when `original` is posted.
     * @noinspection PhpUnused
     */
    public function postSaveCategory(): ResponseInterface
    {
        if (!$this->canManageCategories()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Items.category_not_authorized')]);
        }

        $name = Category::cleanName((string) $this->request->getPost('name'));
        $original = (string) $this->request->getPost('original');

        if ($name === '') {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.category_required')]);
        }

        if ($original === '') {
            $duplicate = $this->category->findApprovedMatch($name);

            if ($duplicate !== null) {
                return $this->response->setJSON(['success' => false, 'message' => lang('Items.category_duplicate', [$duplicate])]);
            }

            $success = $this->category->create($name);

            if ($success) {
                $this->audit('category_create', 'category', $name, null, ['name' => $name]);
            }

            return $this->response->setJSON([
                'success' => $success,
                'message' => lang($success ? 'Items.category_saved' : 'Items.category_save_failed'),
            ]);
        }

        $moved = $this->category->rename($original, $name);

        if ($moved !== false) {
            $this->audit('category_rename', 'category', $original, ['name' => $original], ['name' => $name, 'items_moved' => $moved]);
        }

        if ($moved === false) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.category_save_failed')]);
        }

        return $this->response->setJSON(['success' => true, 'message' => lang('Items.category_renamed', [$moved])]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function postDeleteCategory(): ResponseInterface
    {
        if (!$this->canManageCategories()) {
            return $this->response->setStatusCode(403)->setJSON(['success' => false, 'message' => lang('Items.category_not_authorized')]);
        }

        $name = (string) $this->request->getPost('name');

        if ($this->category->countActiveItems($name) > 0) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.category_in_use')]);
        }

        $success = $this->category->remove($name);

        if ($success) {
            $this->audit('category_delete', 'category', $name, ['name' => $name]);
        }

        return $this->response->setJSON([
            'success' => $success,
            'message' => lang($success ? 'Items.category_deleted' : 'Items.category_save_failed'),
        ]);
    }

    /**
     * Values a manual stock change at the item's average cost in the ledger.
     */
    private function postInventoryAdjustment(int $inventoryTransId, int $itemId, float $quantityDelta, int $employeeId, string $comment, bool $opening = false): bool
    {
        if (!Posting::enabled() || $quantityDelta == 0.0) {
            return true;
        }

        try {
            (new Posting($this->db))->postInventoryAdjustment($inventoryTransId, $itemId, $quantityDelta, $employeeId, $comment, null, $opening);

            return true;
        } catch (Throwable $e) {
            log_message('error', "Posting inventory adjustment for item $itemId failed: " . $e->getMessage());

            return false;
        }
    }

    /**
     * A hand-edited cost revalues the stock on hand; the change is kept in the
     * cost history and the difference posted to inventory cost variance.
     */
    private function postCostChange(int $itemId, float $previousCost, float $newCost, int $employeeId): bool
    {
        if (round($previousCost, 2) === round($newCost, 2)) {
            return true;
        }

        try {
            $costing = new Costing($this->db);
            $revaluation = $costing->recordManualChange($itemId, $previousCost, $newCost, $employeeId);
            $this->audit('cost_change', 'item', $itemId, ['cost_price' => $previousCost], ['cost_price' => $newCost, 'stock_revaluation' => $revaluation]);

            if (Posting::enabled()) {
                (new Posting($this->db))->postCostRevaluation((int) $this->db->insertID(), $itemId, $revaluation, $employeeId);
            }

            return true;
        } catch (Throwable $e) {
            log_message('error', "Recording the cost change of item $itemId failed: " . $e->getMessage());

            return false;
        }
    }

    private function canManageCategories(): bool
    {
        return $this->employee->has_grant('items_categories', $this->employee->get_logged_in_employee_info()->person_id);
    }

    /**
     * Maps a posted category onto its approved spelling.
     *
     * Returns null when the category dropdown is on and the value is neither
     * approved nor the item's existing category, so a hand-crafted POST cannot
     * create the spelling variants the dropdown exists to prevent. With the
     * dropdown off, free text is still accepted but snapped to an approved
     * spelling when one matches.
     */
    private function resolveCategory(string $posted, int $itemId): ?string
    {
        $posted = Category::cleanName($posted);

        if ($posted === '' || $this->category->isApproved($posted)) {
            return $posted;
        }

        $approved = $this->category->findApprovedMatch($posted);

        if ($approved !== null) {
            return $approved;
        }

        if ($this->config['category_dropdown'] !== '1') {
            return $posted;
        }

        if ($itemId !== NEW_ENTRY && (string) $this->item->get_info($itemId)->category === $posted) {
            return $posted;
        }

        return null;
    }

    /**
     * Gives search suggestions based on what is being searched for.
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getSuggestLocation(): ResponseInterface
    {
        $suggestions = $this->item->get_location_suggestions($this->request->getGet('term'));

        return $this->response->setJSON($suggestions);
    }

    /**
     * @param string $itemIds
     * @return ResponseInterface
     */
    public function getRow(string $itemIds): ResponseInterface    // TODO: An array would be better for parameter.
    {
        $itemInfos = $this->item->get_multiple_info(explode(':', $itemIds), $this->item_lib->get_item_location());
        $itemResults = $itemInfos->getResult();

        $definitionNames = $this->attribute->getDefinitionsByFlags(Attribute::SHOW_IN_ITEMS, true);
        $taxPercentsByItemId = $this->buildTaxPercentsByItem($itemResults);

        $result = [];

        foreach ($itemResults as $itemInfo) {
            $result[$itemInfo->item_id] = getItemDataRow($itemInfo, $definitionNames, $taxPercentsByItemId);
        }

        return $this->response->setJSON($result);
    }

    /**
     * @param int $item_id
     * @return string
     */
    public function getView(int $item_id = NEW_ENTRY): string    // TODO: Long function. Perhaps we need to refactor out some methods.
    {
        $item_id ??= NEW_ENTRY;

        if ($item_id === NEW_ENTRY) {
            $data = [];
        }

        $data['allow_temp_item'] = $this->session->get('allow_temp_items'); // allow_temp_items is set in the index function of items.php or sales.php
        $data['item_tax_info'] = $this->item_taxes->get_info($item_id);
        $data['default_tax_1_rate'] = '';
        $data['default_tax_2_rate'] = '';
        $data['item_kit_disabled'] = !$this->employee->has_grant('item_kits', $this->employee->get_logged_in_employee_info()->person_id);
        $data['definition_values'] = $this->attribute->get_attributes_by_item($item_id);
        $data['definition_names'] = $this->attribute->getDefinitionNames();

        foreach ($data['definition_values'] as $definition_id => $definition) {
            unset($data['definition_names'][$definition_id]);
        }

        $item_info = $this->item->get_info($item_id);

        $data['allow_temp_item'] = ($data['allow_temp_item'] === 1 && $item_id !== NEW_ENTRY && $item_info->item_type != ITEM_TEMP) ? 0 : 1;

        $use_destination_based_tax = (bool)$this->config['use_destination_based_tax'];
        $data['include_hsn'] = $this->config['include_hsn'] === '1';
        $data['category_dropdown'] = $this->config['category_dropdown'];

        if ($data['category_dropdown'] === '1') {
            $data['categories'] = $this->category->getDropdownOptions(lang('Items.choose_category'), (string) $item_info->category);
            $data['selected_category'] = $item_info->category;
        }

        $data['can_manage_categories'] = $this->canManageCategories();

        if ($item_id === NEW_ENTRY) {
            $data['default_tax_1_rate'] = $this->config['default_tax_1_rate'];
            $data['default_tax_2_rate'] = $this->config['default_tax_2_rate'];

            $item_info->receiving_quantity = 1;
            $item_info->reorder_level = 1;
            $item_info->item_type = ITEM;    // Standard
            $item_info->item_id = $item_id;
            $item_info->stock_type = HAS_STOCK;
            $item_info->tax_category_id = null;
            $item_info->qty_per_pack = 1;
            $item_info->pack_name = lang('Items.default_pack_name');

            if ($use_destination_based_tax) {
                $item_info->tax_category_id = $this->config['default_tax_category'];
            }
        }

        $data['standard_item_locked'] = (
            $data['item_kit_disabled']
            && $item_info->item_type == ITEM_KIT
            && !$data['allow_temp_item']
            && !($this->config['derive_sale_quantity'] === '1')
        );

        $data['item_info'] = $item_info;

        $suppliers = ['' => lang('Items.none')];

        foreach ($this->supplier->get_all()->getResultArray() as $row) {
            $suppliers[$row['person_id']] = $row['company_name'];
        }

        $data['suppliers'] = $suppliers;
        $data['selected_supplier'] = $item_info->supplier_id;

        $data['hsn_code'] = $data['include_hsn']
            ? $item_info->hsn_code
            : '';

        if ($use_destination_based_tax) {
            $data['use_destination_based_tax'] = true;
            $tax_categories = [];

            foreach ($this->tax_category->get_all()->getResultArray() as $row) {
                $tax_categories[$row['tax_category_id']] = $row['tax_category'];
            }

            $tax_category = '';

            if ($item_info->tax_category_id !== null) {
                $tax_category_info = $this->tax_category->get_info($item_info->tax_category_id);
                $tax_category = $tax_category_info->tax_category;
            }

            $data['tax_categories'] = $tax_categories;
            $data['tax_category'] = $tax_category;
            $data['tax_category_id'] = $item_info->tax_category_id;
        } else {
            $data['use_destination_based_tax'] = false;
            $data['tax_categories'] = [];
            $data['tax_category'] = '';
        }

        $data['logo_exists'] = $item_info->pic_filename !== null;
        if ($item_info->pic_filename != null) {
            $file_extension = pathinfo($item_info->pic_filename, PATHINFO_EXTENSION);
            if (empty($file_extension)) {
                $images = glob("./uploads/item_pics/$item_info->pic_filename.*");
            } else {
                $images = glob("./uploads/item_pics/$item_info->pic_filename");
            }
            $data['image_path']    = sizeof($images) > 0 ? base_url(implode('/', array_map('rawurlencode', explode('/', ltrim($images[0], './'))))) : '';
        } else {
            $data['image_path']    = '';
        }

        $stock_locations = $this->stock_location->get_undeleted_all()->getResultArray();

        foreach ($stock_locations as $location) {
            $quantity = $this->item_quantity->get_item_quantity($item_id, $location['location_id'])->quantity;
            $quantity = ($item_id === NEW_ENTRY) ? 0 : $quantity;
            $location_array[$location['location_id']] = ['location_name' => $location['location_name'], 'quantity' => $quantity];
            $data['stock_locations'] = $location_array;
        }

        $data['selected_low_sell_item_id'] = $item_info->low_sell_item_id;

        if ($item_id !== NEW_ENTRY && $item_info->item_id !== $item_info->low_sell_item_id) {
            $low_sell_item_info = $this->item->get_info($item_info->low_sell_item_id);
            $data['selected_low_sell_item'] = implode(NAME_SEPARATOR, [$low_sell_item_info->name, $low_sell_item_info->pack_name]);
        } else {
            $data['selected_low_sell_item'] = '';
        }

        return view('items/form', $data);
    }

    /**
     * AJAX called function which returns the update inventory form view for an item
     *
     * @param int $item_id
     * @return string
     * @noinspection PhpUnused
     */
    public function getInventory(int $item_id = NEW_ENTRY): string
    {
        $item_info = $this->item->get_info($item_id);    // TODO: Duplicate code

        foreach (get_object_vars($item_info) as $property => $value) {
            $item_info->$property = $value;
        }

        $data['item_info'] = $item_info;
        $data['stock_locations'] = [];
        $stock_locations = $this->stock_location->get_undeleted_all()->getResultArray();

        foreach ($stock_locations as $location) {
            $quantity = $this->item_quantity->get_item_quantity($item_id, $location['location_id'])->quantity;

            $data['stock_locations'][$location['location_id']] = $location['location_name'];
            $data['item_quantities'][$location['location_id']] = $quantity;
        }

        return view('items/form_inventory', $data);
    }

    /**
     * @param int $item_id
     * @return string
     * @noinspection PhpUnused
     */
    public function getCountDetails(int $item_id = NEW_ENTRY): string
    {
        $item_info = $this->item->get_info($item_id);    // TODO: Duplicate code

        foreach (get_object_vars($item_info) as $property => $value) {
            $item_info->$property = $value;
        }

        $data['item_info'] = $item_info;
        $data['stock_locations'] = [];
        $stock_locations = $this->stock_location->get_undeleted_all()->getResultArray();

        foreach ($stock_locations as $location) {
            $quantity = $this->item_quantity->get_item_quantity($item_id, $location['location_id'])->quantity;

            $data['stock_locations'][$location['location_id']] = $location['location_name'];
            $data['item_quantities'][$location['location_id']] = $quantity;
        }

        return view('items/form_count_details', $data);
    }

    /**
     *  AJAX called function that generates barcodes for selected items.
     *
     * @param string $item_ids Colon separated list of item_id values to generate barcodes for.
     * @return string
     * @noinspection PhpUnused
     */
    public function getGenerateBarcodes(string $item_ids): string    // TODO: Passing these through as a string instead of an array limits the contents of the item_ids. Perhaps a better approach would to serialize as JSON in an array and pass through post variables?
    {
        $item_ids = explode(':', $item_ids);
        $result = $this->item->get_multiple_info($item_ids, $this->item_lib->get_item_location())->getResultArray();
        $data['barcode_config'] = $this->barcode_lib->get_barcode_config();

        foreach ($result as &$item) {
            if (isset($item['item_number']) && empty($item['item_number']) && $this->config['barcode_generate_if_empty']) {
                if (isset($item['item_id'])) {
                    $save_item = ['item_number' => $item['item_number']];
                    $this->item->save_value($save_item, $item['item_id']);
                }
            }
        }
        $data['items'] = $result;

        return view('barcodes/barcode_sheet', $data);
    }

    /**
     * Gathers attribute value information for an item and returns it in a view.
     *
     * @param int $item_id
     * @return string
     */
    public function getAttributes(int $item_id = NEW_ENTRY): string
    {
        $data['item_id'] = $item_id;
        $definition_ids = json_decode($this->request->getGet('definition_ids') ?? '', true);
        $data['definition_values'] = $this->attribute->get_attributes_by_item($item_id) + $this->attribute->get_values_by_definitions($definition_ids);
        $data['definition_names'] = $this->attribute->getDefinitionNames();

        foreach ($data['definition_values'] as $definition_id => $definition_value) {
            $attribute_value = $this->attribute->getAttributeValue($item_id, $definition_id);
            $attribute_id = (empty($attribute_value) || empty($attribute_value->attribute_id)) ? null : $attribute_value->attribute_id;
            $values = &$data['definition_values'][$definition_id];
            $values['attribute_id'] = $attribute_id;
            $values['attribute_value'] = $attribute_value;
            $values['selected_value'] = '';

            if ($definition_value['definition_type'] === DROPDOWN) {
                $values['values'] = $this->attribute->getDefinitionValues($definition_id);
                $link_value = $this->attribute->get_link_value($item_id, $definition_id);
                $values['selected_value'] = (empty($link_value)) ? '' : $link_value->attribute_id;
            }

            if (!empty($definition_ids[$definition_id])) {
                $values['selected_value'] = $definition_ids[$definition_id];
            }

            unset($data['definition_names'][$definition_id]);
        }

        return view('attributes/item', $data);
    }

    /**
     * @param int $item_id
     * @return string
     * @noinspection PhpUnused
     */
    public function postAttributes(int $item_id = NEW_ENTRY): string
    {
        $data['item_id'] = $item_id;
        $definition_ids = json_decode($this->request->getPost('definition_ids'), true);
        $data['definition_values'] = $this->attribute->get_attributes_by_item($item_id) + $this->attribute->get_values_by_definitions($definition_ids);
        $data['definition_names'] = $this->attribute->getDefinitionNames();

        foreach ($data['definition_values'] as $definition_id => $definition_value) {
            $attribute_value = $this->attribute->getAttributeValue($item_id, $definition_id);
            $attribute_id = (empty($attribute_value) || empty($attribute_value->attribute_id)) ? null : $attribute_value->attribute_id;
            $values = &$data['definition_values'][$definition_id];
            $values['attribute_id'] = $attribute_id;
            $values['attribute_value'] = $attribute_value;
            $values['selected_value'] = '';

            if ($definition_value['definition_type'] === DROPDOWN) {
                $values['values'] = $this->attribute->getDefinitionValues($definition_id);
                $link_value = $this->attribute->get_link_value($item_id, $definition_id);
                $values['selected_value'] = (empty($link_value)) ? '' : $link_value->attribute_id;
            }

            if (!empty($definition_ids[$definition_id])) {
                $values['selected_value'] = $definition_ids[$definition_id];
            }

            unset($data['definition_names'][$definition_id]);
        }

        return view('attributes/item', $data);
    }

    /**
     * Edit multiple items. Used in app/Views/items/manage.php
     *
     * @return string
     * @noinspection PhpUnused
     */
    public function getBulkEdit(): string
    {
        $suppliers = [
            ''                          => lang('Items.do_nothing'),
            Item::CLEAR_SUPPLIER_OPTION => lang('Items.none')
        ];

        foreach ($this->supplier->get_all()->getResultArray() as $row) {
            $suppliers[$row['person_id']] = $row['company_name'];
        }

        $data['suppliers'] = $suppliers;
        $data['allow_alt_description_choices'] = [
            '' => lang('Items.do_nothing'),
            1  => lang('Items.change_all_to_allow_alt_desc'),
            0  => lang('Items.change_all_to_not_allow_allow_desc')
        ];

        $data['serialization_choices'] = [
            '' => lang('Items.do_nothing'),
            1  => lang('Items.change_all_to_serialized'),
            0  => lang('Items.change_all_to_unserialized')
        ];

        return view('items/form_bulk', $data);
    }

    /**
     * Apparel variant definitions are ordinary DROPDOWN attributes so that they
     * show up as sortable columns and in the named attribute search of the
     * Items page, without changing the schema. The names are fixed strings and
     * reused wherever an existing definition already carries them, so stores
     * that already have a Size or Color definition keep using it.
     */
    private const VARIANT_DEFINITION_FLAGS = Attribute::SHOW_IN_ITEMS | Attribute::SHOW_IN_SEARCH;
    private const VARIANT_COLOR_DEFINITION = 'Color';
    private const VARIANT_SIZE_DEFINITION = 'Size';

    /**
     * Renders the variant builder page used to create apparel variants
     * (colors x sizes) as ordinary items in one go.
     */
    public function getVariants(): string
    {
        return view('items/form_variants', $this->variantFormData());
    }

    /**
     * Returns the generated variant matrix for review without writing anything.
     * Used by the builder page to render the editable preview table.
     *
     * @return ResponseInterface
     */
    public function postVariantPreview(): ResponseInterface
    {
        $rows = $this->buildVariantRows($this->variantInput());

        if ($rows === []) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Items.variant_no_options'),
            ]);
        }

        $existing = array_map('mb_strtolower', $this->item->get_existing_item_numbers(array_column($rows, 'sku')));

        foreach ($rows as &$row) {
            $row['exists'] = in_array(mb_strtolower($row['sku']), $existing, true);
        }
        unset($row);

        return $this->response->setJSON(['success' => true, 'rows' => $rows]);
    }

    /**
     * Creates every reviewed variant in a single transaction. Either all
     * variants are stored or none are, so a failure can never leave a partial
     * matrix behind, and SKU/barcode collisions are rejected before any write.
     *
     * @return ResponseInterface
     */
    public function postCreateVariants(): ResponseInterface
    {
        $input = $this->variantInput();
        $rows = $this->variantRowsFromRequest();

        if ($rows === []) {
            $rows = $this->buildVariantRows($input);
        }

        if ($input['name'] === '') {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.name_required')]);
        }

        if ($rows === []) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Items.variant_no_options'),
            ]);
        }

        $validation = $this->validateVariantRows($rows);
        if ($validation !== null) {
            return $this->response->setJSON(['success' => false, 'message' => $validation]);
        }

        // Definitions and their selectable values are metadata, not stock, so
        // they are prepared before the atomic item write below. They are safe
        // to keep even if that transaction has to roll back.
        $colorDefinition = $this->attribute->ensureDefinition(self::VARIANT_COLOR_DEFINITION, DROPDOWN, self::VARIANT_DEFINITION_FLAGS);
        $sizeDefinition = $this->attribute->ensureDefinition(self::VARIANT_SIZE_DEFINITION, DROPDOWN, self::VARIANT_DEFINITION_FLAGS);

        if ($colorDefinition === NO_DEFINITION_ID || $sizeDefinition === NO_DEFINITION_ID) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.error_adding_updating')]);
        }

        foreach ($rows as $row) {
            if ($row['color'] !== null) {
                $this->attribute->ensureDefinitionValue($colorDefinition, $row['color'], DROPDOWN);
            }

            if ($row['size'] !== null) {
                $this->attribute->ensureDefinitionValue($sizeDefinition, $row['size'], DROPDOWN);
            }
        }

        $useDestinationBasedTax = (bool) $this->config['use_destination_based_tax'];
        $stockLocations = $this->stock_location->get_undeleted_all()->getResultArray();

        $db = db_connect();
        $db->transBegin();

        $success = true;
        $created = 0;

        foreach ($rows as $row) {
            $itemData = [
                'name'                  => $row['name'],
                'description'           => $input['description'],
                'category'              => $input['category'],
                'item_type'             => ITEM,
                'stock_type'            => $input['stock_type'],
                'supplier_id'           => $input['supplier_id'],
                'item_number'           => $row['sku'],
                'cost_price'            => $row['cost_price'],
                'unit_price'            => $row['unit_price'],
                'reorder_level'         => $input['reorder_level'],
                'receiving_quantity'    => 1,
                'allow_alt_description' => false,
                'is_serialized'         => false,
                'qty_per_pack'          => 1,
                'pack_name'             => lang('Items.default_pack_name'),
                'low_sell_item_id'      => NEW_ITEM,
                'deleted'               => false,
                'hsn_code'              => '',
                'tax_category_id'       => $input['tax_category_id'],
            ];

            $success = $this->item->save_value($itemData);

            if (!$success) {
                break;
            }

            $itemId = (int) $itemData['item_id'];

            if (!$useDestinationBasedTax) {
                $itemsTaxesData = [];

                foreach ($input['tax_percents'] as $taxNameIndex => $taxPercent) {
                    $taxPercentage = parse_tax($taxPercent);

                    if (is_numeric($taxPercentage)) {
                        $itemsTaxesData[] = ['name' => $input['tax_names'][$taxNameIndex] ?? '', 'percent' => $taxPercentage];
                    }
                }

                $success = $this->item_taxes->save_value($itemsTaxesData, $itemId);
            }

            if (!$success) {
                break;
            }

            foreach ($stockLocations as $location) {
                $success = $this->item_quantity->save_value([
                    'item_id'     => $itemId,
                    'location_id' => $location['location_id'],
                    'quantity'    => 0,
                ], $itemId, $location['location_id']) && $success;
            }

            if (!$success) {
                break;
            }

            if ($row['color'] !== null) {
                $success = $this->attribute->saveAttributeValue($row['color'], $colorDefinition, $itemId, false, DROPDOWN) !== 0 && $success;
            }

            if ($success && $row['size'] !== null) {
                $success = $this->attribute->saveAttributeValue($row['size'], $sizeDefinition, $itemId, false, DROPDOWN) !== 0 && $success;
            }

            if (!$success) {
                break;
            }

            $created++;
        }

        if (!$success) {
            $db->transRollback();

            return $this->response->setJSON(['success' => false, 'message' => lang('Items.error_adding_updating')]);
        }

        $db->transCommit();

        return $this->response->setJSON([
            'success' => true,
            'message' => lang('Items.variant_saved', [$created]),
            'count'   => $created,
        ]);
    }

    /**
     * Shared view data for the variant builder page: the same suppliers,
     * categories and tax switches the regular item form uses.
     *
     * @return array
     */
    private function variantFormData(): array
    {
        $data['suppliers'] = ['' => lang('Items.none')];

        foreach ($this->supplier->get_all()->getResultArray() as $row) {
            $data['suppliers'][$row['person_id']] = $row['company_name'];
        }

        $data['categoryDropdown'] = $this->config['category_dropdown'] === '1';

        if ($data['categoryDropdown']) {
            $data['categories'] = $this->category->getDropdownOptions(lang('Items.none'));
        }

        $data['useDestinationBasedTax'] = (bool) $this->config['use_destination_based_tax'];

        if ($data['useDestinationBasedTax']) {
            $taxCategories = [];

            foreach ($this->tax_category->get_all()->getResultArray() as $row) {
                $taxCategories[$row['tax_category_id']] = $row['tax_category'];
            }

            $data['taxCategories'] = $taxCategories;
        }

        $data['defaultTax1Rate'] = $this->config['default_tax_1_rate'];
        $data['defaultTax2Rate'] = $this->config['default_tax_2_rate'];

        return $data;
    }

    /**
     * Reads and normalizes the base variant builder fields.
     *
     * @return array
     */
    private function variantInput(): array
    {
        return [
            'name'            => trim((string) $this->request->getPost('name')),
            'category'        => trim((string) $this->request->getPost('category')),
            'supplier_id'     => empty($this->request->getPost('supplier_id')) ? null : (int) $this->request->getPost('supplier_id'),
            'cost_price'      => parse_decimals($this->request->getPost('cost_price')),
            'unit_price'      => parse_decimals($this->request->getPost('unit_price')),
            'reorder_level'   => parse_quantity((string) $this->request->getPost('reorder_level')),
            'description'     => $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '',
            'stock_type'      => $this->request->getPost('stock_type') === null ? HAS_STOCK : (int) $this->request->getPost('stock_type'),
            'tax_names'       => $this->request->getPost('tax_names') ?? [],
            'tax_percents'    => $this->request->getPost('tax_percents') ?? [],
            'tax_category_id' => empty($this->request->getPost('tax_category_id')) ? null : (int) $this->request->getPost('tax_category_id'),
            'colors'          => variant_split_options((string) $this->request->getPost('colors')),
            'sizes'           => variant_split_options((string) $this->request->getPost('sizes')),
            'sku_prefix'      => trim((string) $this->request->getPost('sku_prefix')),
        ];
    }

    /**
     * Builds the color x size matrix rows with deterministic names and SKUs.
     * Option labels that normalize to the same SKU token are disambiguated with
     * a numeric suffix so auto-generation can never produce a duplicate.
     *
     * @param array $input
     * @return array
     */
    private function buildVariantRows(array $input): array
    {
        $rows = [];
        $usedSkus = [];

        foreach (variant_build_combinations($input['colors'], $input['sizes']) as $combination) {
            $name = variant_build_name($input['name'], $combination['options']);
            $baseSku = variant_build_sku($input['sku_prefix'], $combination['options']);
            $sku = $baseSku;
            $suffix = 2;

            while ($sku !== '' && isset($usedSkus[$sku])) {
                $sku = $baseSku . '-' . $suffix;
                $suffix++;
            }

            if ($sku !== '') {
                $usedSkus[$sku] = true;
            }

            $rows[] = [
                'name'       => $name,
                'sku'        => $sku,
                'color'      => $combination['color'],
                'size'       => $combination['size'],
                'cost_price' => $input['cost_price'],
                'unit_price' => $input['unit_price'],
            ];
        }

        return $rows;
    }

    /**
     * Reads the reviewed (and possibly edited) rows posted by the builder page.
     * Rows whose include checkbox was left unchecked are not submitted and are
     * therefore skipped here.
     *
     * @return array
     */
    private function variantRowsFromRequest(): array
    {
        $rawRows = $this->request->getPost('rows');

        if (!is_array($rawRows)) {
            return [];
        }

        $rows = [];

        foreach ($rawRows as $row) {
            if (!is_array($row) || empty($row['include'])) {
                continue;
            }

            $rows[] = [
                'name'       => trim((string) ($row['name'] ?? '')),
                'sku'        => trim((string) ($row['sku'] ?? '')),
                'color'      => ($row['color'] ?? '') === '' ? null : variant_option_label((string) $row['color']),
                'size'       => ($row['size'] ?? '') === '' ? null : variant_option_label((string) $row['size']),
                'cost_price' => parse_decimals((string) ($row['cost_price'] ?? '')),
                'unit_price' => parse_decimals((string) ($row['unit_price'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Validates the variant rows before anything is written. Returns an
     * error message, or null when the rows may be created.
     *
     * @param array $rows
     * @return string|null
     */
    private function validateVariantRows(array $rows): ?string
    {
        $duplicates = [];
        $seen = [];
        $skus = [];

        foreach ($rows as $row) {
            if ($row['name'] === '') {
                return lang('Items.name_required');
            }

            if ($row['sku'] === '') {
                return lang('Items.number_required');
            }

            if (preg_match('/[^\w.\-]/u', $row['sku'])) {
                return lang('Items.item_number_invalid');
            }

            $key = mb_strtolower($row['sku']);

            if (isset($seen[$key])) {
                $duplicates[] = $row['sku'];
            }

            $seen[$key] = true;
            $skus[] = $row['sku'];
        }

        if ($duplicates !== []) {
            return lang('Items.variant_duplicate_sku') . implode(', ', array_values(array_unique($duplicates)));
        }

        $existing = $this->item->get_existing_item_numbers($skus);

        if ($existing !== []) {
            return lang('Items.variant_sku_in_use') . implode(', ', $existing);
        }

        return null;
    }

    private function validateItemFields(int $itemId): ?ResponseInterface
    {
        $itemNumber = $this->request->getPost('item_number');

        if (!empty($itemNumber)) {
            $rules = [
                'item_number' => 'alpha_numeric_punct',
            ];
            $messages = [
                'item_number' => [
                    'alpha_numeric_punct' => lang('Items.item_number_invalid'),
                ],
            ];

            $error = $this->validateFields($rules, $messages, $itemId);
            if ($error !== null) {
                return $error;
            }
        }

        $taxNamesInput = $this->request->getPost('tax_names');

        if (!empty($taxNamesInput)) {
            // Blank tax rows (no default tax configured) are allowed here and skipped when saving.
            $rules = [
                'tax_names.*' => 'permit_empty|max_length[255]|unicode_alpha_numeric_punct',
            ];
            $messages = [
                'tax_names.*' => [
                    'max_length'                  => lang('Items.tax_name_invalid'),
                    'unicode_alpha_numeric_punct' => lang('Items.tax_name_invalid'),
                ],
            ];

            $error = $this->validateFields($rules, $messages, $itemId);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function validateBulkUpdateFields(): ?ResponseInterface
    {
        $taxNamesInput = $this->request->getPost('tax_names');

        if (!empty($taxNamesInput)) {
            $rules = [
                'tax_names.*' => 'max_length[255]|unicode_alpha_numeric_punct',
            ];
            $messages = [
                'tax_names.*' => [
                    'max_length'                  => lang('Items.tax_name_invalid'),
                    'unicode_alpha_numeric_punct' => lang('Items.tax_name_invalid'),
                ],
            ];

            return $this->validateFields($rules, $messages, NEW_ENTRY);
        }

        return null;
    }

    /**
     * @param int $itemId
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function postSave(int $itemId = NEW_ENTRY): ResponseInterface
    {
        $validationError = $this->validateItemFields($itemId);

        if ($validationError !== null) {
            return $validationError;
        }

        $uploadData = $this->upload_image();
        $uploadSuccess = empty($uploadData['error']);

        $rawReceivingQuantity = $this->request->getPost('receiving_quantity');

        $receivingQuantity = parse_quantity($rawReceivingQuantity);
        $itemType = $this->request->getPost('item_type') === null ? ITEM : intval($this->request->getPost('item_type'));

        if ($receivingQuantity === 0.0 && $itemType !== ITEM_TEMP) {
            $receivingQuantity = 1;
        }

        $defaultPackName = lang('Items.default_pack_name');

        $costPrice = parse_decimals($this->request->getPost('cost_price'));
        $unitPrice = parse_decimals($this->request->getPost('unit_price'));
        $reorderLevel = parse_quantity($this->request->getPost('reorder_level'));
        $qtyPerPack = parse_quantity($this->request->getPost('qty_per_pack') ?? '');

        // Save item data
        $itemData = [
            'name'                  => $this->request->getPost('name'),
            'description'           => $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'category'              => $this->request->getPost('category'),
            'item_type'             => $itemType,
            'stock_type'            => $this->request->getPost('stock_type') === null ? HAS_STOCK : intval($this->request->getPost('stock_type')),
            'supplier_id'           => empty($this->request->getPost('supplier_id')) ? null : intval($this->request->getPost('supplier_id')),
            'item_number'           => empty($this->request->getPost('item_number')) ? null : $this->request->getPost('item_number'),
            'cost_price'            => $costPrice,
            'unit_price'            => $unitPrice,
            'reorder_level'         => $reorderLevel,
            'receiving_quantity'    => $receivingQuantity,
            'allow_alt_description' => $this->request->getPost('allow_alt_description') != null,
            'is_serialized'         => $this->request->getPost('is_serialized') != null,
            'qty_per_pack'          => $this->request->getPost('qty_per_pack') == null ? 1 : parse_quantity($qtyPerPack),
            'pack_name'             => $this->request->getPost('pack_name') == null ? $defaultPackName : $this->request->getPost('pack_name'),
            'low_sell_item_id'      => $this->request->getPost('low_sell_item_id') === null ? $itemId : intval($this->request->getPost('low_sell_item_id')),
            'deleted'               => $this->request->getPost('is_deleted') != null,
            'hsn_code'              => $this->request->getPost('hsn_code') === null ? '' : $this->request->getPost('hsn_code')
        ];

        if ($itemData['item_type'] == ITEM_TEMP) {
            $itemData['stock_type'] = HAS_NO_STOCK;
            $itemData['receiving_quantity'] = 0;
            $itemData['reorder_level'] = 0;
        }

        $category = $this->resolveCategory((string) $itemData['category'], $itemId);

        // With the approved-category list in use, every product belongs to one; the
        // form marks it required, but its picker is hidden from client validation.
        if ($category === '' && $this->config['category_dropdown'] === '1') {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Items.category_required'),
                'id'      => $itemId,
            ]);
        }

        if ($category === null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Items.category_not_approved'),
                'id'      => $itemId,
            ]);
        }

        $itemData['category'] = $category;

        $taxCategoryId = $this->request->getPost('tax_category_id');

        if (!isset($taxCategoryId)) {
            $itemData['tax_category_id'] = null;
        } else {
            $itemData['tax_category_id'] = empty($this->request->getPost('tax_category_id')) ? null : intval($this->request->getPost('tax_category_id'));
        }

        if (!empty($uploadData['orig_name']) && $uploadData['raw_name']) {
            $itemData['pic_filename'] = $uploadData['raw_name'] . '.' . $uploadData['file_ext'];
        }

        $employeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $previousCost = $itemId === NEW_ENTRY ? null : (float) $this->item->get_info($itemId)->cost_price;

        // Wrap the entire save sequence in a single transaction for atomicity
        $db = db_connect();
        $db->transBegin();

        $success = $this->item->save_value($itemData, $itemId);
        $newItem = false;

        if ($success) {
            if ($itemId === NEW_ENTRY) {
                $itemId = $itemData['item_id'];
                $newItem = true;
            }

            // Revalue the quantity on hand before any quantity edit below, which is then valued at the new cost.
            if ($previousCost !== null && $itemData['item_type'] != ITEM_TEMP) {
                $success = $this->postCostChange((int) $itemId, $previousCost, (float) $costPrice, $employeeId);
            }

            $useDestinationBasedTax = (bool)$this->config['use_destination_based_tax'];

            if (!$useDestinationBasedTax) {
                $itemsTaxesData = [];
                $taxNames = $this->request->getPost('tax_names');
                $taxPercents = $this->request->getPost('tax_percents');

                $taxNameIndex = 0;
                $seenTaxes = [];

                foreach ($taxPercents ?? [] as $taxPercent) {
                    $taxPercentage = parse_tax((string) $taxPercent);
                    $taxName = trim((string) ($taxNames[$taxNameIndex] ?? ''));
                    $taxNameIndex++;

                    // (item_id, name, percent) is the primary key: a blank name or a repeated
                    // row would make the insert fail and roll back the whole item save.
                    if ($taxName === '' || !is_numeric($taxPercentage)) {
                        continue;
                    }

                    $taxKey = mb_strtolower($taxName) . '|' . (float) $taxPercentage;

                    if (isset($seenTaxes[$taxKey])) {
                        continue;
                    }

                    $seenTaxes[$taxKey] = true;
                    $itemsTaxesData[] = ['name' => $taxName, 'percent' => $taxPercentage];
                }
                $success = $success && $this->item_taxes->save_value($itemsTaxesData, $itemId);
            }

            // Save item quantity
            $stockLocations = $this->stock_location->get_undeleted_all()->getResultArray();
            foreach ($stockLocations as $location) {
                $updatedQuantity = parse_quantity($this->request->getPost('quantity_' . $location['location_id']));

                if ($itemData['item_type'] == ITEM_TEMP) {
                    $updatedQuantity = 0;
                }

                $locationDetail = [
                    'item_id'     => $itemId,
                    'location_id' => $location['location_id'],
                    'quantity'    => $updatedQuantity
                ];

                $itemQuantity = $this->item_quantity->get_item_quantity($itemId, $location['location_id']);

                if ($itemQuantity->quantity != $updatedQuantity || $newItem) {
                    $success = $success && $this->item_quantity->save_value($locationDetail, $itemId, $location['location_id']);

                    $invData = [
                        'trans_date'      => date('Y-m-d H:i:s'),
                        'trans_items'     => $itemId,
                        'trans_user'      => $employeeId,
                        'trans_location'  => $location['location_id'],
                        'trans_comment'   => lang('Items.manually_editing_of_quantity'),
                        'trans_inventory' => $updatedQuantity - $itemQuantity->quantity
                    ];

                    $success = $success && $this->inventory->insert($invData, false);
                    $success = $success && $this->postInventoryAdjustment((int) $db->insertID(), (int) $itemId, (float) $invData['trans_inventory'], $employeeId, $invData['trans_comment'], $newItem);
                }
            }
            $success = $success && $this->saveItemAttributes($itemId);
        }

        // Check all success conditions before committing
        if ($success && $uploadSuccess) {
            $db->transCommit();
            $message = lang('Items.successful_' . ($newItem ? 'adding' : 'updating')) . ' ' . $itemData['name'];

            return $this->response->setJSON(['success' => true, 'message' => $message, 'id' => $itemId]);
        }

        // Rollback on failure
        $db->transRollback();
        $message = $uploadSuccess ? lang('Items.error_adding_updating') . ' ' . $itemData['name'] : strip_tags($uploadData['error']);

        return $this->response->setJSON(['success' => false, 'message' => $message, 'id' => $itemId]);
    }

    /**
     * Let files be uploaded with their original name
     * @return array
     */
    private function upload_image(): array
    {
        $file = $this->request->getFile('items_image');
        if (!$file) {
            return [];
        }

        helper(['form']);
        $validation_rule = [
            'items_image' => [
                'label' => 'Item Image',
                'rules' => [
                    'uploaded[items_image]',
                    'is_image[items_image]',
                    'max_size[items_image,' . $this->config['image_max_size'] . ']',
                    'mime_in[items_image,image/png,image/jpg,image/jpeg,image/gif]',
                    'ext_in[items_image,' . $this->config['image_allowed_types'] . ']',
                    'max_dims[items_image,' . $this->config['image_max_width'] . ',' . $this->config['image_max_height'] . ']'
                ]
            ]
        ];

        if (!$this->validate($validation_rule)) {
            return (['error' => $this->validator->getError('items_image')]);
        }

        $filename = $file->getClientName();
        $info = pathinfo($filename);

        // Sanitize filename to remove problematic characters like spaces
        $sanitized_name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $info['filename']);

        $file_info = [
            'orig_name' => $filename,
            'raw_name'  => $sanitized_name,
            'file_ext'  => $file->guessExtension()
        ];

        $file->move(FCPATH . 'uploads/item_pics/', $file_info['raw_name'] . '.' . $file_info['file_ext'], true);
        return ($file_info);
    }


    /**
     * Ajax call to check to see if the item number, a.k.a. barcode, is already used by another item
     * If it exists then that is an error condition so return true for "error found"
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postCheckItemNumber(): ResponseInterface
    {
        $exists = $this->item->item_number_exists($this->request->getPost('item_number'), $this->request->getPost('item_id'));

        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * Checks to see if an item kit with the same name as the item already exists.
     *
     * @return ResponseInterface
     */
    public function check_kit_exists(): ResponseInterface    // TODO: This function appears to be never called in the code.  Need to confirm.
    {
        if ($this->request->getPost('item_number') === NEW_ENTRY) {
            $exists = $this->item_kit->item_kit_exists_for_name($this->request->getPost('name'));    // TODO: item_kit_exists_for_name doesn't exist in Item_kit.  I looked at the blame and it appears to have never existed.
        } else {
            $exists = false;
        }

        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }

    /**
     * @param $item_id
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getRemoveLogo($item_id): ResponseInterface
    {
        $item_data = ['pic_filename' => null];
        $result = $this->item->save_value($item_data, $item_id);

        return $this->response->setJSON(['success' => $result]);
    }

    /**
     * @return ResponseInterface
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postSaveInventory($item_id = NEW_ENTRY): ResponseInterface
    {
        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $cur_item_info = $this->item->get_info($item_id);
        $location_id = $this->request->getPost('stock_location');

        // The stock location must be one the employee is granted for the items
        // module. The location dropdown is only a convenience; a tampered
        // request must not be able to adjust stock at a location the employee
        // cannot access.
        if ($location_id === null || !$this->stock_location->is_allowed_location((int) $location_id, 'items')) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Items.error_adding_updating') . ' ' . $cur_item_info->name,
                'id'      => NEW_ENTRY,
            ]);
        }

        $quantity_change = parse_quantity($this->request->getPost('newquantity'));
        $inv_data = [
            'trans_date'      => date('Y-m-d H:i:s'),
            'trans_items'     => $item_id,
            'trans_user'      => $employee_id,
            'trans_location'  => $location_id,
            'trans_comment'   => $this->request->getPost('trans_comment'),
            'trans_inventory' => $quantity_change
        ];

        $db = \Config\Database::connect();
        $db->transStart();

        $this->inventory->insert($inv_data, false);
        $inventoryTransId = (int) $db->insertID();

        // Update stock quantity with the same atomic delta-based write as sales
        // and receivings, so concurrent adjustments cannot lose updates; the
        // zero floor rejects an adjustment that would drive the location below
        // zero.
        $success = $this->item_quantity->changeQuantity((int) $item_id, (int) $location_id, $quantity_change, 0.0);
        $success = $success && $this->postInventoryAdjustment($inventoryTransId, (int) $item_id, (float) $quantity_change, $employee_id, (string) $inv_data['trans_comment']);

        // A refused adjustment must not leave its history row behind.
        if (!$success) {
            $db->transRollback();
        } else {
            $db->transComplete();
        }

        if ($success && $db->transStatus()) {
            $this->audit('inventory_adjustment', 'item', (int) $item_id, null, ['location_id' => (int) $location_id, 'quantity_change' => $quantity_change], (string) $inv_data['trans_comment']);
            $message = lang('Items.successful_updating') . " $cur_item_info->name";

            return $this->response->setJSON(['success' => true, 'message' => $message, 'id' => $item_id]);
        }

        $message = lang('Items.error_adding_updating') . " $cur_item_info->name";

        return $this->response->setJSON(['success' => false, 'message' => $message, 'id' => NEW_ENTRY]);
    }

    /**
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postBulkUpdate(): ResponseInterface
    {
        $validationError = $this->validateBulkUpdateFields();

        if ($validationError !== null) {
            return $validationError;
        }

        $itemsToUpdate = $this->request->getPost('item_ids');
        $itemData = Item::filterBulkEditFields($this->request->getPost() ?? []);

        // Item data could be empty if tax information is being updated
        if (empty($itemData) || $this->item->updateMultiple($itemData, $itemsToUpdate)) {
            $itemsTaxesData = [];
            $taxNames = $this->request->getPost('tax_names');
            $taxPercents = $this->request->getPost('tax_percents');
            $taxUpdated = false;

            foreach ($taxPercents as $tax_percent) {
                if (!empty($taxNames[$tax_percent]) && is_numeric($taxPercents[$tax_percent])) {
                    $taxUpdated = true;
                    $itemsTaxesData[] = ['name' => $taxNames[$tax_percent], 'percent' => $taxPercents[$tax_percent]];
                }
            }

            if ($taxUpdated) {
                $this->item_taxes->save_multiple($itemsTaxesData, $itemsToUpdate);
            }

            return $this->response->setJSON(['success' => true, 'message' => lang('Items.successful_bulk_edit'), 'id' => $itemsToUpdate]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.error_updating_multiple')]);
        }
    }

    /**
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $items_to_delete = $this->request->getPost('ids');

        if ($this->item->delete_list($items_to_delete)) {
            $message = lang('Items.successful_deleted') . ' ' . count($items_to_delete) . ' ' . lang('Items.one_or_multiple');
            return $this->response->setJSON(['success' => true, 'message' => $message]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Items.cannot_be_deleted')]);
        }
    }

    /**
     * Generates a template CSV file for item import/update containing headers for current stock locations and attributes. Used in app/Views/items/form_csv_import.php
     *
     * @return DownloadResponse
     * @noinspection PhpUnused
     */
    public function getGenerateCsvFile(): DownloadResponse
    {
        helper('importfile');
        $name = 'import_items.csv';
        $allowed_locations = $this->stock_location->get_allowed_locations();
        $allowedAttributes = $this->attribute->getDefinitionNames();
        $data = generate_import_items_csv($allowed_locations, $allowedAttributes);

        return $this->response->download($name, $data);
    }

    /**
     * @return string
     * @noinspection PhpUnused
     */
    public function getCsvImport(): string
    {
        return view('items/form_csv_import');
    }

    /**
     * Imports items from a CSV formatted file.
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function postImportCsvFile(): ResponseInterface
    {
        helper('importfile');
        try {
            if ($_FILES['file_path']['error'] !== UPLOAD_ERR_OK) {
                return $this->response->setJSON(['success' => false, 'message' => lang('Items.csv_import_failed')]);
            } else {
                if (file_exists($_FILES['file_path']['tmp_name'])) {
                    set_time_limit(240);

                    $failCodes = [];
                    $csvRows = get_csv_file($_FILES['file_path']['tmp_name']);
                    $allowedStockLocations = $this->stock_location->get_allowed_locations();
                    $attributeDefinitionNames    = $this->attribute->getDefinitionNames();

                    if (!csvImportHasRequiredItemHeaders($csvRows, $allowedStockLocations, $attributeDefinitionNames)) {
                        return $this->response->setJSON(['success' => false, 'message' => lang('Items.csv_import_nodata_wrongformat')]);
                    }

                    $employeeId = $this->employee->get_logged_in_employee_info()->person_id;

                    unset($attributeDefinitionNames[NEW_ENTRY]);    // Removes the common_none_selected_text from the array

                    $attributeData = [];


                    foreach ($attributeDefinitionNames as $definitionName) {
                        $attributeData[$definitionName] = $this->attribute->getDefinitionByName($definitionName);

                        if ($attributeData[$definitionName]['definition_type'] === DROPDOWN) {
                            $attributeData[$definitionName]['dropdown_values'] = $this->attribute->getDefinitionValues($attributeData[$definitionName]['definition_id']);
                        }
                    }
                    $db = db_connect();
                    $db->transBegin();    // TODO: This section needs to be reworked so that the data array is being created then passed to the Item model because $db doesn't exist in the controller without being instantiated, but database operations should be restricted to the model

                    foreach ($csvRows as $key => $row) {
                        $isFailedRow = false;
                        $itemId = (int)$row['Id'];
                        $isUpdate = ($itemId > 0);
                        $itemData = [
                            'item_id'       => $itemId,
                            'name'          => $row['Item Name'],
                            'description'   => filter_var($row['Description'], FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                            'category'      => $row['Category'],
                            'cost_price'    => $row['Cost Price'],
                            'unit_price'    => $row['Unit Price'],
                            'reorder_level' => $row['Reorder Level'],
                            'deleted'       => false,
                            'hsn_code'      => $row['HSN'],
                            'pic_filename'  => $row['Image']
                        ];

                        if (!empty($row['Supplier ID'])) {
                            $itemData['supplier_id'] = $this->supplier->exists($row['Supplier ID']) ? $row['Supplier ID'] : null;
                        }

                        if ($isUpdate) {
                            $itemData['allow_alt_description'] = $row['Allow Alt Description'] === '' ? null : $row['Allow Alt Description'];
                            $itemData['is_serialized'] = $row['Item has Serial Number'] === '' ? null : $row['Item has Serial Number'];
                        } else {
                            $itemData['allow_alt_description'] = $row['Allow Alt Description'] === '' ? '0' : '1';
                            $itemData['is_serialized'] = $row['Item has Serial Number'] === '' ? '0' : '1';
                        }

                        if (!empty($row['Barcode'])) {
                            $itemData['item_number'] = $row['Barcode'];
                            $isFailedRow = $this->item->item_number_exists($itemData['item_number'], $itemId);
                        }

                        if (!$isFailedRow) {
                            $allowedStockLocations = $this->stock_location->get_allowed_locations();
                            $isFailedRow = $this->validateCSVData($row, $itemData, $allowedStockLocations, $attributeDefinitionNames, $attributeData);
                            if (!empty($invalidLocations)) {
                                $isFailedRow = true;
                                log_message('error', 'CSV import: Invalid stock location(s) found: ' . implode(', ', $invalidLocations));
                            }
                        }

                        // Remove false, null, '' and empty strings but keep 0
                        $itemData = array_filter($itemData, function ($value) {
                            return $value !== null && strlen($value);
                        });

                        if (!$isFailedRow && $this->item->save_value($itemData, $itemId)) {
                            if (!$this->save_tax_data($row, $itemData)) {
                                $isFailedRow = true;
                            }
                            if (!$this->save_inventory_quantities($row, $itemData, $allowedStockLocations, $employeeId)) {
                                $isFailedRow = true;
                            }
                            $csvAttributeValues = $this->extractAttributeData($row);
                            if (!$this->attribute->saveCSVRowAttributeData($csvAttributeValues, $itemData, $attributeData)) {
                                $isFailedRow = true;
                            }
                            if ($isFailedRow) {
                                $failedRow = $key + 2;
                                $failCodes[] = $failedRow;
                                log_message('error', "CSV Item import failed on line $failedRow while saving item.");
                                continue;
                            }

                            if ($isUpdate) {
                                $itemData = array_merge($itemData, get_object_vars($this->item->get_info_by_id_or_number($itemId)));
                            }
                        } else {
                            $failedRow = $key + 2;
                            $failCodes[] = $failedRow;
                            log_message('error', "CSV Item import failed on line $failedRow. This item was not imported.");
                        }

                        unset($csvRows[$key]);
                    }

                    $csvRows = null;

                    if (count($failCodes) > 0) {
                        $message = lang('Items.csv_import_partially_failed', [count($failCodes), implode(', ', $failCodes)]);
                        $db->transRollback();
                        return $this->response->setJSON(['success' => false, 'message' => $message]);
                    } else {
                        $db->transCommit();
                        $this->attribute->deleteOrphanedValues();

                        return $this->response->setJSON(['success' => true, 'message' => lang('Items.csv_import_success')]);
                    }
                } else {
                    return $this->response->setJSON(['success' => false, 'message' => lang('Items.csv_import_nodata_wrongformat')]);
                }
            }
        } catch (Exception $e) {
            return $this->response->setJSON(['success' => false, 'message' => $e->getMessage()]);
        }

    }

    private function extractAttributeData(array $row): array
    {
        $attributeData = [];

        foreach ($row as $key => $value) {
            if (str_starts_with($key, 'attribute_')) {
                $definitionName = substr($key, 10);
                $attributeData[$definitionName] = $value;
            }
        }

        return $attributeData;
    }

    /**
     * Validates that stock location columns in CSV row are valid locations
     *
     * @param array $row
     * @param array $allowedLocations
     * @return array Returns array of invalid location names, empty if all valid
     */
    private function validateCSVStockLocations(array $row, array $allowedLocations): array
    {
        $invalidLocations = [];
        $allowedLocationNames = array_values($allowedLocations);

        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, 'location_')) {
                $locationName = substr($key, 9);
                if (!in_array($locationName, $allowedLocationNames)) {
                    $invalidLocations[] = $locationName;
                }
            }
        }

        return $invalidLocations;
    }

    /**
     * Checks the entire line of data in an import file for errors
     *
     * @param array $row
     * @param array $itemData
     * @param array $allowedStockLocations
     * @param array $definitionNames
     * @param array $attributeData
     * @return    bool    Returns false if all data checks out and true when there is an error in the data
     */
    private function validateCSVData(array $row, array $itemData, array $allowedStockLocations, array $definitionNames, array $attributeData): bool    // TODO: Long function and large number of parameters in the declaration... perhaps refactoring is needed
    {
        $itemId = $row['Id'];
        $isUpdate = (bool)$itemId;

        // Check for empty required fields
        $valuesToCheckForEmpty = [
            'name'       => $itemData['name'],
            'category'   => $itemData['category'],
            'unit_price' => $itemData['unit_price']
        ];

        foreach ($valuesToCheckForEmpty as $key => $value) {
            if (($value === null || $value === '') && !$isUpdate) {
                log_message('error', "Empty required value in $key.");
                return true;
            }
        }

        if (!$isUpdate) {
            $itemData['cost_price'] = empty($itemData['cost_price']) ? 0 : $itemData['cost_price'];    // Allow for zero wholesale price
        } else {
            if (!$this->item->exists($itemId)) {
                log_message('error', "non-existent item_id: '$itemId' when either existing item_id or no item_id is required.");
                return true;
            }
        }

        // Build array of fields to check for numerics
        $valuesToCheckForNumeric = [
            'cost_price'    => $itemData['cost_price'],
            'unit_price'    => $itemData['unit_price'],
            'reorder_level' => $itemData['reorder_level'],
            'supplier_id'   => $row['Supplier ID'],
            'Tax 1 Percent' => $row['Tax 1 Percent'],
            'Tax 2 Percent' => $row['Tax 2 Percent']
        ];

        foreach ($allowedStockLocations as $location_name) {
            $valuesToCheckForNumeric[] = $row["location_$location_name"];
        }

        // Check for non-numeric values which require numeric
        foreach ($valuesToCheckForNumeric as $key => $value) {
            if (!is_numeric($value) && !empty($value)) {
                log_message('error', "non-numeric: '$value' for '$key' when numeric is required");
                return true;
            }
        }

        // Check item_number for disallowed characters
        if (!empty($itemData['item_number'])) {
            $formatRules = new FormatRules();

            if (!$formatRules->alpha_numeric_punct($itemData['item_number'])) {
                log_message('error', "invalid item_number: '{$itemData['item_number']}' contains disallowed characters");
                return true;
            }
        }

        // Check stock locations
        $invalidLocations = $this->validateCSVStockLocations($row, $allowedStockLocations);
        if (!empty($invalidLocations)) {
            log_message('error', 'CSV import: Invalid stock location(s) found: ' . implode(', ', $invalidLocations));
            return true;
        }

        // Check Attribute Data
        foreach ($definitionNames as $definitionName) {
            $attributeColumn = "attribute_$definitionName";
            if (array_key_exists($attributeColumn, $row) && $row[$attributeColumn] != '') {
                $definitionType = $attributeData[$definitionName]['definition_type'];
                $attributeValue = $row[$attributeColumn];

                if (strcasecmp($attributeValue, '_DELETE_') === 0) {
                    continue;
                }

                switch ($definitionType) {
                    case DROPDOWN:
                        $dropdownValues = $attributeData[$definitionName]['dropdown_values'];
                        $dropdownValues[] = '';

                        if (!empty($attributeValue) && !in_array($attributeValue, $dropdownValues)) {
                            log_message('error', "Value: '$attributeValue' is not an acceptable DROPDOWN value");
                            return true;
                        }
                        break;
                    case DECIMAL:
                        if (!is_numeric($attributeValue) && !empty($attributeValue)) {
                            log_message('error', "'$attributeValue' is not an acceptable DECIMAL value");
                            return true;
                        }
                        break;
                    case DATE:
                        if (!isValidDate($attributeValue) && !empty($attributeValue)) {
                            log_message('error', "'$attributeValue' is not an acceptable DATE value. The value must match the set locale.");
                            return true;
                        }
                        break;
                }
            }
        }

        return false;
    }

    /**
     * Saves inventory quantities for the row in the appropriate stock locations.
     *
     * @param array $row
     * @param array $item_data
     * @param array $allowed_locations
     * @param int $employee_id
     * @return bool Returns true on success, false on failure
     * @throws ReflectionException
     */
    private function save_inventory_quantities(array $row, array $item_data, array $allowed_locations, int $employee_id): bool
    {
        // Quantities & Inventory Section
        $comment = lang('Items.inventory_CSV_import_quantity');
        $is_update = (bool)$row['Id'];
        $success = true;

        foreach ($allowed_locations as $location_id => $location_name) {
            $item_quantity_data = ['item_id' => $item_data['item_id'], 'location_id' => $location_id];

            $csv_data = [
                'trans_items'    => $item_data['item_id'],
                'trans_user'     => $employee_id,
                'trans_comment'  => $comment,
                'trans_location' => $location_id
            ];

            if (!empty($row["location_$location_name"]) || $row["location_$location_name"] === '0') {
                $previousQuantity = (float) $this->item_quantity->get_item_quantity((int) $item_data['item_id'], (int) $location_id)->quantity;
                $item_quantity_data['quantity'] = $row["location_$location_name"];
                $success &= $this->item_quantity->save_value($item_quantity_data, $item_data['item_id'], $location_id);

                // The stock history records the change, not the new level, so that
                // quantity on hand stays the sum of its movements on re-import.
                $csv_data['trans_inventory'] = (float) $row["location_$location_name"] - $previousQuantity;
                $success &= (bool)$this->inventory->insert($csv_data, false);
                $success &= $this->postInventoryAdjustment((int) $this->db->insertID(), (int) $item_data['item_id'], (float) $csv_data['trans_inventory'], $employee_id, $comment, !$is_update);
            } elseif ($is_update) {
                continue;
            } else {
                $item_quantity_data['quantity'] = 0;
                $success &= $this->item_quantity->save_value($item_quantity_data, $item_data['item_id'], $location_id);

                $csv_data['trans_inventory'] = 0;
                $success &= (bool)$this->inventory->insert($csv_data, false);
            }
        }

        return (bool)$success;
    }

    /**
     * Saves the tax data found in the line of the CSV items import file
     *
     * @param array $row
     * @param array $item_data
     * @return bool Returns true on success, false on failure
     */
    private function save_tax_data(array $row, array $item_data): bool
    {
        $items_taxes_data = [];

        if (is_numeric($row['Tax 1 Percent']) && $row['Tax 1 Name'] !== '') {
            $items_taxes_data[] = ['name' => $row['Tax 1 Name'], 'percent' => $row['Tax 1 Percent']];
        }

        if (is_numeric($row['Tax 2 Percent']) && $row['Tax 2 Name'] !== '') {
            $items_taxes_data[] = ['name' => $row['Tax 2 Name'], 'percent' => $row['Tax 2 Percent']];
        }

        if (!empty($items_taxes_data)) {
            return $this->item_taxes->save_value($items_taxes_data, $item_data['item_id']);
        }

        return true;
    }

    /**
     * Guess whether file extension is not in the table field, if it isn't, then it's an old-format (formerly pic_id) field, so we guess the right filename and update the table
     *
     * @param $item object item to update
     */
    private function update_pic_filename(object $item): void
    {
        $filename = pathinfo($item->pic_filename, PATHINFO_FILENAME);

        // If the field is empty there's nothing to check
        if (!empty($filename)) {
            $ext = pathinfo($item->pic_filename, PATHINFO_EXTENSION);
            if (empty($ext)) {
                $images = glob(FCPATH . "uploads/item_pics/$item->pic_filename.*");
                if (sizeof($images) > 0) {
                    $new_pic_filename = pathinfo($images[0], PATHINFO_BASENAME);
                    $item_data = ['pic_filename' => $new_pic_filename];
                    $this->item->save_value($item_data, $item->item_id);
                }
            }
        }
    }

    /**
     * Saves item attributes for a given item.
     *
     * @param int $itemId The item for which attributes need to be saved to.
     * @return bool Returns true when item attributes are successfully saved and false on error.
     */
    public function saveItemAttributes(int $itemId): bool
    {
        $success = true;
        $attributeLinks = $this->request->getPost('attribute_links') ?? [];
        $attributeIds = $this->request->getPost('attribute_ids');

        $this->attribute->deleteAttributeLinks($itemId);

        foreach ($attributeLinks as $definitionId => $attributeValue) {
            $definitionType = $this->attribute->getAttributeInfo($definitionId)->definition_type;

            switch ($definitionType) {
                case DROPDOWN:
                    $attributeId = $attributeValue;
                    $success = $success && $this->attribute->saveAttributeLink($itemId, $definitionId, $attributeId);
                    break;
                case DECIMAL:
                    $attributeValue = parse_decimals($attributeValue);
                    // no break
                default:
                    $attributeId = $this->attribute->saveAttributeValue($attributeValue, $definitionId, $itemId, $attributeIds[$definitionId], $definitionType);
                    $success = $success && ($attributeId > 0);
                    break;
            }
        }

        return $success && $this->attribute->deleteOrphanedValues();
    }
}
