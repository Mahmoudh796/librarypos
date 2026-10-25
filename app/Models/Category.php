<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Approved item categories.
 *
 * Items store their category as free text in items.category. The approved list
 * is the set of DROPDOWN values linked to the hidden CATEGORY_DEFINITION_ID
 * attribute definition (item_id IS NULL); that is what the item form offers when
 * the category_dropdown setting is on.
 *
 * attribute_values rows are shared between definitions, so a category is never
 * renamed by editing its attribute_values row in place: the new value is linked
 * and the old link removed, leaving other definitions that use the same text alone.
 */
class Category extends Model
{
    protected $table = 'items';
    protected $primaryKey = 'item_id';

    /**
     * Folds the spelling variants Arabic data entry produces (hamza forms, alef
     * maqsura, taa marbuta, tatweel, diacritics, case and spacing) so
     * "أقلام", "اقلام" and "أقلام " are recognised as the same category.
     */
    public static function normalizeKey(string $name): string
    {
        $key = self::cleanName($name);
        $key = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $key);
        $key = strtr($key, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        ]);

        return mb_strtolower($key);
    }

    /**
     * Trims and collapses internal whitespace, keeping the spelling as typed.
     */
    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @return string[] approved category names, sorted
     */
    public function getApproved(): array
    {
        $builder = $this->db->table('attribute_links');
        $builder->select('attribute_values.attribute_value');
        $builder->join('attribute_values', 'attribute_values.attribute_id = attribute_links.attribute_id');
        $builder->where('attribute_links.definition_id', CATEGORY_DEFINITION_ID);
        $builder->where('attribute_links.item_id', null);
        $builder->orderBy('attribute_values.attribute_value', 'ASC');

        return array_values(array_unique(array_column($builder->get()->getResultArray(), 'attribute_value')));
    }

    /**
     * Options for an item form dropdown. The item's current category is always
     * included, even when it is not approved, so opening and saving an item can
     * never silently clear its category.
     *
     * @return array<string, string>
     */
    public function getDropdownOptions(string $noneLabel, ?string $currentCategory = null): array
    {
        $options = ['' => $noneLabel];

        foreach ($this->getApproved() as $name) {
            $options[$name] = $name;
        }

        if ($currentCategory !== null && $currentCategory !== '' && !isset($options[$currentCategory])) {
            $options[$currentCategory] = $currentCategory;
        }

        return $options;
    }

    /**
     * Approved categories plus categories that items use without being approved,
     * each with its count of active items.
     *
     * @return list<array{name: string, approved: bool, item_count: int}>
     */
    public function getAllWithCounts(): array
    {
        $counts = [];
        $builder = $this->db->table('items');
        $builder->select('category, COUNT(*) AS item_count');
        $builder->where('deleted', 0);
        $builder->where('category !=', '');
        $builder->groupBy('category');

        foreach ($builder->get()->getResultArray() as $row) {
            $counts[$row['category']] = (int) $row['item_count'];
        }

        $rows = [];

        foreach ($this->getApproved() as $name) {
            $rows[$name] = ['name' => $name, 'approved' => true, 'item_count' => $counts[$name] ?? 0];
        }

        foreach ($counts as $name => $count) {
            $rows[$name] ??= ['name' => (string) $name, 'approved' => false, 'item_count' => $count];
        }

        uasort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_values($rows);
    }

    /**
     * Returns the approved spelling that matches $name after normalisation, or null.
     */
    public function findApprovedMatch(string $name, ?string $ignore = null): ?string
    {
        $key = self::normalizeKey($name);

        if ($key === '') {
            return null;
        }

        foreach ($this->getApproved() as $approved) {
            if ($approved !== $ignore && self::normalizeKey($approved) === $key) {
                return $approved;
            }
        }

        return null;
    }

    public function isApproved(string $name): bool
    {
        return in_array($name, $this->getApproved(), true);
    }

    /**
     * Adds a category to the approved list. Returns false when the name is empty
     * or a spelling variant of an existing approved category.
     */
    public function create(string $name): bool
    {
        $name = self::cleanName($name);

        if ($name === '' || $this->findApprovedMatch($name) !== null) {
            return false;
        }

        return model(Attribute::class)->saveAttributeValue($name, CATEGORY_DEFINITION_ID) > 0;
    }

    /**
     * Renames a category and moves every item in it. Renaming onto an existing
     * approved category (or a spelling variant of one) merges the two, which is
     * how fragmented spellings are consolidated.
     *
     * @return int|false number of items moved, or false on failure
     */
    public function rename(string $from, string $to): int|false
    {
        $to = self::cleanName($to);

        if ($to === '' || $from === '') {
            return false;
        }

        $to = $this->findApprovedMatch($to, $from) ?? $to;

        if ($to === $from) {
            return 0;
        }

        $this->db->transStart();

        $attribute = model(Attribute::class);

        if (!$this->isApproved($to)) {
            $attribute->saveAttributeValue($to, CATEGORY_DEFINITION_ID);
        }

        $builder = $this->db->table('items');
        $builder->set('category', $to);
        $builder->where('category', $from);
        $builder->update();
        $moved = $this->db->affectedRows();

        if ($this->isApproved($from)) {
            $attribute->deleteDropdownAttributeValue($from, CATEGORY_DEFINITION_ID);
        }

        $this->db->transComplete();

        return $this->db->transStatus() ? $moved : false;
    }

    /**
     * Removes a category from the approved list. Refused while active items use it.
     */
    public function remove(string $name): bool
    {
        if ($this->countActiveItems($name) > 0) {
            return false;
        }

        if (!$this->isApproved($name)) {
            return true;
        }

        return model(Attribute::class)->deleteDropdownAttributeValue($name, CATEGORY_DEFINITION_ID);
    }

    public function countActiveItems(string $name): int
    {
        return $this->db->table('items')
            ->where('category', $name)
            ->where('deleted', 0)
            ->countAllResults();
    }
}
