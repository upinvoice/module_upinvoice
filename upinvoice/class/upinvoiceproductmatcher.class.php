<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

/**
 * Links document lines with Dolibarr products by the reference shown on the document.
 *
 * Same rule as the web (invoice.blade.php, findProductByRefs):
 * 1. Supplier codes first: the reference is compared with ref_fourn (the code this supplier uses
 *    for the product), only for the supplier of the document. References are tried in order.
 * 2. Then internal references: ref > ref_ext > label (inside a field, exact match before key match).
 * In each pass, references are tried in order, and the first one with a unique product wins.
 * If a reference gives several products, it is skipped (next reference); if none is unique,
 * the line is not linked and the user chooses the product by hand.
 * Within a level, several candidates are broken by the type the AI gave the line (product/service);
 * a single candidate is linked whatever its type.
 */
class UpInvoiceProductMatcher
{
    /** @var DoliDB */
    private $db;

    /** Rows read per page and maximum pages per search */
    const PAGE_SIZE = 20;
    const MAX_PAGES = 5;

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Link the lines with products. A linked line gets fk_product, product_label, product_type
     * (the product's type) and product_ref (the reference of the product that matched).
     *
     * @param array $lines      Lines as returned by the API (product_ref, product_refs, product_type...)
     * @param int   $supplierId Supplier of the document; 0 or less skips the supplier code pass
     * @return array            The same lines, the linked ones completed
     */
    public function linkLines(array $lines, $supplierId)
    {
        $supplierId = (int) $supplierId;

        foreach ($lines as $key => $line) {
            if (empty($line['product_ref'])) {
                continue;
            }
            // product_refs only comes when the document shows several references for the line
            $refs = !empty($line['product_refs']) && is_array($line['product_refs']) ? $line['product_refs'] : array($line['product_ref']);
            $lineType = isset($line['product_type']) && $line['product_type'] !== '' ? (int) $line['product_type'] : null;

            $product = $this->linkPass($refs, $lineType, 'supplier', $supplierId);
            if (!$product) {
                $product = $this->linkPass($refs, $lineType, 'internal', $supplierId);
            }
            if ($product) {
                // The line takes the product's type and keeps the reference that matched
                $lines[$key]['fk_product'] = $product->rowid;
                $lines[$key]['product_label'] = $product->label;
                $lines[$key]['product_type'] = $product->fk_product_type;
                $lines[$key]['product_ref'] = $product->ref;
            }
        }

        return $lines;
    }

    /**
     * Comparison key for references: letters and digits only, unaccented and uppercase, so that
     * "SWITCH'GSMIP4G12V" (document) matches "SWITCH_GSMIP4G12V" (Product::create replaces spaces,
     * quotes, '/', ',' etc. with '_'), as is done with supplier VAT numbers
     *
     * @param string $ref Reference
     * @return string     Key
     */
    public static function refKey($ref)
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]/u', '', dol_string_unaccent((string) $ref)));
    }

    /**
     * Product for the first reference (in order) that gives a unique product in this pass, or null
     *
     * @param array    $refs       References of the line
     * @param int|null $lineType   Type given by the AI (0 product, 1 service) or null
     * @param string   $pass       'supplier' (ref_fourn) or 'internal' (ref, ref_ext, label)
     * @param int      $supplierId Supplier of the document
     * @return object|null         Product row (rowid, ref, label, fk_product_type...) or null
     */
    private function linkPass($refs, $lineType, $pass, $supplierId)
    {
        foreach ($refs as $ref) {
            $ref = trim((string) $ref);
            $key = self::refKey($ref);
            if ($key === '') {
                continue;
            }
            $candidates = $pass === 'supplier' ? $this->supplierCandidates($ref, $key, $supplierId) : $this->internalCandidates($ref, $key);
            $product = $this->pickUnique($candidates, $lineType);
            if ($product !== null) {
                return $product;
            }
        }
        return null;
    }

    /**
     * Tokens of a reference (pieces of letters and digits) joined with '%', for the LIKE searches
     *
     * @param string $ref Reference
     * @return string     Escaped LIKE pattern, '' if the reference has no letters or digits
     */
    private function likeOf($ref)
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $ref, -1, PREG_SPLIT_NO_EMPTY);
        return empty($tokens) ? '' : implode('%', array_map(array($this->db, 'escape'), $tokens));
    }

    /**
     * Reads up to MAX_PAGES pages of PAGE_SIZE rows; keeps one candidate per product (its best level)
     *
     * @param string   $sql     Query without limit
     * @param callable $levelOf Level of a row (lower is better) or null if the row does not match
     * @return array            List of array('level' => int, 'obj' => object)
     */
    private function collect($sql, $levelOf)
    {
        $found = array();
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $resql = $this->db->query($sql.$this->db->plimit(self::PAGE_SIZE, $page * self::PAGE_SIZE));
            if (!$resql) {
                break;
            }
            $rows = 0;
            while ($obj = $this->db->fetch_object($resql)) {
                $rows++;
                $level = $levelOf($obj);
                if ($level !== null && (!isset($found[$obj->rowid]) || $level < $found[$obj->rowid]['level'])) {
                    $found[$obj->rowid] = array('level' => $level, 'obj' => $obj);
                }
            }
            $this->db->free($resql);
            if ($rows < self::PAGE_SIZE) {
                break;
            }
        }
        return array_values($found);
    }

    /**
     * The product of the candidates at the best level, if there is exactly one (type breaks ties), or null
     *
     * @param array    $candidates Result of collect()
     * @param int|null $lineType   Type given by the AI or null
     * @return object|null
     */
    private function pickUnique($candidates, $lineType)
    {
        if (empty($candidates)) {
            return null;
        }
        $bestLevel = min(array_column($candidates, 'level'));
        $pool = array_values(array_filter($candidates, function ($c) use ($bestLevel) {
            return $c['level'] === $bestLevel;
        }));
        if (count($pool) > 1 && $lineType !== null) {
            $sameType = array_values(array_filter($pool, function ($c) use ($lineType) {
                return (int) $c['obj']->fk_product_type === $lineType;
            }));
            if (!empty($sameType)) {
                $pool = $sameType;
            }
        }
        return count($pool) === 1 ? $pool[0]['obj'] : null;
    }

    /**
     * Pass 1 candidates: products whose supplier code (ref_fourn) for the document supplier matches the reference
     *
     * @param string $ref        Reference as printed
     * @param string $key        refKey() of the reference
     * @param int    $supplierId Supplier of the document
     * @return array
     */
    private function supplierCandidates($ref, $key, $supplierId)
    {
        $like = $this->likeOf($ref);
        if ($supplierId <= 0 || $like === '') {
            return array();
        }
        $sql = "SELECT p.rowid, p.ref, p.ref_ext, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.fk_product_type, pfp.ref_fourn";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pfp.fk_product";
        $sql .= " WHERE pfp.fk_soc = ".((int) $supplierId)." AND pfp.ref_fourn LIKE '%".$like."%'";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " ORDER BY p.rowid ASC";
        return $this->collect($sql, function ($obj) use ($ref, $key) {
            $value = (string) $obj->ref_fourn;
            if ($value === '') {
                return null;
            }
            if ($value === $ref) {
                return 0;
            }
            return UpInvoiceProductMatcher::refKey($value) === $key ? 1 : null;
        });
    }

    /**
     * Pass 2 candidates: products whose ref, ref_ext or label matches the reference
     *
     * @param string $ref Reference as printed
     * @param string $key refKey() of the reference
     * @return array
     */
    private function internalCandidates($ref, $key)
    {
        $like = $this->likeOf($ref);
        if ($like === '') {
            return array();
        }
        $sql = "SELECT p.rowid, p.ref, p.ref_ext, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.fk_product_type";
        $sql .= " FROM ".MAIN_DB_PREFIX."product as p";
        $sql .= " WHERE (p.ref LIKE '%".$like."%' OR p.ref_ext LIKE '%".$like."%' OR p.label LIKE '%".$like."%')";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " ORDER BY p.rowid ASC";
        return $this->collect($sql, function ($obj) use ($ref, $key) {
            // Field priority: ref (0), ref_ext (1), label (2); exact match (0) before key match (1)
            foreach (array('ref', 'ref_ext', 'label') as $i => $field) {
                $value = (string) $obj->$field;
                if ($value === '') {
                    continue;
                }
                if ($value === $ref) {
                    return $i * 2;
                }
                if (UpInvoiceProductMatcher::refKey($value) === $key) {
                    return $i * 2 + 1;
                }
            }
            return null;
        });
    }
}
