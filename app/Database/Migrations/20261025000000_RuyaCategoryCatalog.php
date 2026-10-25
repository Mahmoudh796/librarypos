<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Makes the approved category list usable:
 *
 * - Adds the `items_categories` sub-permission that gates creating, renaming,
 *   merging and removing approved categories. It is granted to whoever already
 *   held `attributes` (the only previous way to edit the hidden category
 *   definition) and to the default admin, so nobody loses capability.
 * - Approves every category already in use by an active item. Before this, an
 *   item whose category was missing from the approved list opened with no
 *   category selected in the dropdown, and saving it cleared the category.
 *
 * No item data is changed. Spelling variants are merged from the Categories
 * screen, by a person, rather than guessed here.
 */
class RuyaCategoryCatalog extends Migration
{
    private const CATEGORY_DEFINITION_ID = -1;

    public function up(): void
    {
        $this->db->table('permissions')->ignore(true)->insert([
            'permission_id' => 'items_categories',
            'module_id'     => 'items',
        ]);

        $holders = $this->db->table('grants')
            ->select('person_id')
            ->where('permission_id', 'attributes')
            ->get()
            ->getResultArray();
        $personIds = array_unique(array_merge([1], array_map('intval', array_column($holders, 'person_id'))));

        $existingPeople = array_map('intval', array_column(
            $this->db->table('employees')->select('person_id')->whereIn('person_id', $personIds)->get()->getResultArray(),
            'person_id'
        ));

        $grants = [];
        foreach ($existingPeople as $personId) {
            $grants[] = ['permission_id' => 'items_categories', 'person_id' => $personId, 'menu_group' => '--'];
        }

        if ($grants !== []) {
            $this->db->table('grants')->ignore(true)->insertBatch($grants);
        }

        $this->approveCategoriesInUse();
    }

    private function approveCategoriesInUse(): void
    {
        $definition = $this->db->table('attribute_definitions')
            ->where('definition_id', self::CATEGORY_DEFINITION_ID)
            ->where('deleted', 0)
            ->get()
            ->getRow();

        // The definition only exists while the category dropdown is enabled.
        if ($definition === null) {
            return;
        }

        $approved = array_column($this->db->table('attribute_links')
            ->select('attribute_values.attribute_value')
            ->join('attribute_values', 'attribute_values.attribute_id = attribute_links.attribute_id')
            ->where('attribute_links.definition_id', self::CATEGORY_DEFINITION_ID)
            ->where('attribute_links.item_id', null)
            ->get()
            ->getResultArray(), 'attribute_value');

        $inUse = array_column($this->db->table('items')
            ->distinct()
            ->select('category')
            ->where('deleted', 0)
            ->where('category !=', '')
            ->get()
            ->getResultArray(), 'category');

        foreach ($inUse as $category) {
            if (in_array($category, $approved, true)) {
                continue;
            }

            $value = $this->db->table('attribute_values')->select('attribute_id')->where('attribute_value', $category)->get()->getRow();

            if ($value === null) {
                $this->db->table('attribute_values')->insert(['attribute_value' => $category]);
                $attributeId = (int) $this->db->insertID();
            } else {
                $attributeId = (int) $value->attribute_id;
            }

            $this->db->table('attribute_links')->insert([
                'attribute_id'  => $attributeId,
                'definition_id' => self::CATEGORY_DEFINITION_ID,
                'item_id'       => null,
            ]);
            $approved[] = $category;
        }
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', 'items_categories')->delete();
        $this->db->table('permissions')->where('permission_id', 'items_categories')->delete();
        // Approved categories are left in place: they are ordinary data an operator may have edited since.
    }
}
