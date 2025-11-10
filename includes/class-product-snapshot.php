<?php
/**
 * Handles storing Planet product data snapshots.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Planet_Sync_Product_Snapshot {

    /**
     * @var string
     */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'planet_product_snapshots';
    }

    /**
     * Insert or update a snapshot row for a Planet product.
     *
     * @param array $product_data Full Planet product payload.
     * @param array|null $first_category First-level category data.
     */
    public function upsert(array $product_data, $first_category = null) {
        global $wpdb;

        if (empty($product_data)) {
            return;
        }

        $product_id = isset($product_data['id']) ? (int) $product_data['id'] : 0;
        $slug       = $product_data['slug'] ?? '';

        if ($product_id === 0 && $slug === '') {
            return;
        }

        $data = array(
            'product_id'       => $product_id,
            'name'             => $product_data['name'] ?? '',
            'slug'             => $slug,
            'description'      => $product_data['desc'] ?? '',
            'release_date'     => !empty($product_data['release_date']) ? $product_data['release_date'] : null,
            'parents'          => !empty($product_data['parents']) ? wp_json_encode($product_data['parents']) : null,
            'first_categories' => $first_category ? wp_json_encode($first_category) : (!empty($product_data['1st_categories']) ? wp_json_encode($product_data['1st_categories']) : null),
            'snapshot_data'    => wp_json_encode($product_data),
            'updated_at'       => current_time('mysql'),
        );

        $formats = array('%d','%s','%s','%s','%s','%s','%s','%s');

        $existing = $this->get_by_product($product_id, $slug);

        if ($existing) {
            $wpdb->update(
                $this->table,
                $data,
                array('id' => (int) $existing->id),
                $formats,
                array('%d')
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $formats[] = '%s';
            $wpdb->insert(
                $this->table,
                $data,
                $formats
            );
        }
    }

    /**
     * Retrieve an existing snapshot row.
     *
     * @param int $product_id
     * @param string $slug
     * @return object|null
     */
    private function get_by_product($product_id, $slug) {
        global $wpdb;

        if ($product_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE product_id = %d LIMIT 1",
                $product_id
            ));
            if ($row) {
                return $row;
            }
        }

        if ($slug !== '') {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE slug = %s LIMIT 1",
                $slug
            ));
        }

        return null;
    }
}


