<?php
/**
 * Plugin Name: Bulk ACF Editor
 * Description: Bulk edit CPTs and their ACF fields in one spot.
 * Version: 1.0
 * Author: Ryan Lyons
 * Author URI: https://ryanlyons.dev/
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu', function() {
    $post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
    $post_types['post'] = get_post_type_object('post');
    $post_types['page'] = get_post_type_object('page');

    foreach ($post_types as $pt) {
        add_submenu_page(
            'edit.php?post_type=' . $pt->name,
            'Bulk Editor', 'Bulk Editor', 'manage_options',
            'bulk-edit-' . $pt->name,
            function() use ($pt) { render_smart_bulk_editor_v13($pt->name); }
        );
    }
});

function render_smart_bulk_editor_v13($post_type) {
    echo '
    <style>
        .wp-list-table thead th { position: sticky; top: 32px; z-index: 10; background: #f0f0f1; box-shadow: inset 0 -1px 0 #ccd0d4; }
        .controls-wrap { background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .col-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; border-top: 1px solid #eee; padding-top: 10px; margin-top: 15px; }
        .flex-row { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .status-tag { font-size: 9px; text-transform: uppercase; background: #dcdcde; padding: 2px 4px; border-radius: 3px; margin-top: 4px; display: inline-block; }
        .cat-link { font-size: 11px; text-decoration: none; background: #f6f7f7; border: 1px solid #dcdcde; padding: 1px 4px; border-radius: 2px; margin-right: 2px; }
    </style>';

    $all_fields = [];
    if (function_exists('acf_get_field_groups')) {
        $groups = acf_get_field_groups(['post_type' => $post_type]);
        foreach ($groups as $group) {
            $fields = acf_get_fields($group['key']);
            if($fields) {
                foreach ($fields as $field) {
                    $all_fields[$field['name']] = ['label' => $field['label'], 'type' => $field['type'], 'key' => $field['key']];
                }
            }
        }
    }

    $selected_columns = isset($_GET['show_fields']) ? $_GET['show_fields'] : array_keys($all_fields);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $status_filter = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'any';
    $tax_filter = isset($_GET['tax_term']) ? sanitize_text_field($_GET['tax_term']) : '';
    $paged = max(1, $_GET['paged'] ?? 1);
    $search = $_GET['s_bulk'] ?? '';

    if (isset($_POST['bulk_save']) && check_admin_referer('bulk_save_nonce')) {
        foreach ($_POST['post_data'] as $post_id => $data) {
            wp_update_post(['ID' => $post_id, 'post_title' => sanitize_text_field($data['post_title'])]);
            foreach ($all_fields as $name => $cfg) {
                if (isset($data[$name])) {
                    update_field($name, $data[$name], $post_id);
                }
            }
        }
        echo '<div class="updated"><p>Changes saved!</p></div>';
    }

    $tax_args = [];
    if (!empty($tax_filter)) {
        list($tax_name, $term_slug) = explode(':', $tax_filter);
        $tax_args[] = [
            'taxonomy' => $tax_name,
            'field'    => 'slug',
            'terms'    => $term_slug,
        ];
    }

    $query = new WP_Query([
        'post_type'      => $post_type, 
        'posts_per_page' => $limit, 
        'paged'          => $paged, 
        's'              => $search,
        'post_status'    => $status_filter,
        'tax_query'      => $tax_args
    ]);

    ?>
    <div class="wrap">
        <h1>Bulk Edit: <?php echo get_post_type_object($post_type)->label; ?></h1>

        <div class="controls-wrap">
            <form method="get" class="flex-row">
                <input type="hidden" name="post_type" value="<?php echo $post_type; ?>">
                <input type="hidden" name="page" value="bulk-edit-<?php echo $post_type; ?>">
                
                <div>
                    <strong>Status:</strong>
                    <select name="post_status">
                        <option value="any" <?php selected($status_filter, 'any'); ?>>All Statuses</option>
                        <option value="publish" <?php selected($status_filter, 'publish'); ?>>Published</option>
                        <option value="draft" <?php selected($status_filter, 'draft'); ?>>Draft</option>
                    </select>
                </div>

                <div>
                    <strong>Category:</strong>
                    <select name="tax_term">
                        <option value="">All Categories</option>
                        <?php 
                        $taxonomies = get_object_taxonomies($post_type, 'objects');
                        foreach ($taxonomies as $tax) :
                            $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => true]);
                            if ($terms) :
                                echo '<optgroup label="'.esc_attr($tax->label).'">';
                                foreach ($terms as $term) :
                                    $val = $tax->name . ':' . $term->slug;
                                    echo '<option value="'.esc_attr($val).'" '.selected($tax_filter, $val, false).'>'.esc_html($term->name).'</option>';
                                endforeach;
                                echo '</optgroup>';
                            endif;
                        endforeach;
                        ?>
                    </select>
                </div>

                <div>
                    <strong>Limit:</strong>
                    <input type="number" name="limit" value="<?php echo $limit; ?>" style="width: 60px;">
                </div>

                <div>
                    <input type="search" name="s_bulk" value="<?php echo esc_attr($search); ?>" placeholder="Search title...">
                    <input type="submit" class="button button-secondary" value="Apply Filters">
                </div>

                <div class="col-grid">
                    <?php foreach($all_fields as $name => $cfg): ?>
                        <label><input type="checkbox" name="show_fields[]" value="<?php echo $name; ?>" <?php checked(in_array($name, $selected_columns)); ?>> <?php echo esc_html($cfg['label']); ?></label>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>

        <form method="post">
            <?php wp_nonce_field('bulk_save_nonce'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:250px">Title</th>
                        <th style="width:180px">Taxonomies</th>
                        <?php foreach($selected_columns as $name): ?>
                            <th><?php echo esc_html($all_fields[$name]['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($query->have_posts()) : $query->the_post(); $pid = get_the_ID(); ?>
                        <tr>
                            <td>
                                <input type="text" name="post_data[<?php echo $pid; ?>][post_title]" value="<?php echo esc_attr(get_the_title()); ?>" style="width:100%">
                                <?php if ($status_filter === 'any') : ?>
                                    <span class="status-tag"><?php echo get_post_status(); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                foreach(get_object_taxonomies($post_type) as $tax) {
                                    $terms = get_the_terms($pid, $tax);
                                    if($terms) { 
                                        foreach($terms as $t) { echo '<span class="cat-link">'.esc_html($t->name).'</span> '; } 
                                    }
                                }
                                ?>
                            </td>
                            <?php foreach ($selected_columns as $name): 
                                $cfg = $all_fields[$name]; $val = get_field($name, $pid); ?>
                                <td>
                                    <?php if ($cfg['type'] === 'select'): $field_obj = acf_get_field($cfg['key']); ?>
                                        <select name="post_data[<?php echo $pid; ?>][<?php echo $name; ?>]" style="width:100%">
                                            <?php foreach ($field_obj['choices'] as $cv => $cl): ?><option value="<?php echo $cv; ?>" <?php selected($val, $cv); ?>><?php echo $cl; ?></option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?php echo ($cfg['type'] === 'date_picker') ? 'date' : 'text'; ?>" name="post_data[<?php echo $pid; ?>][<?php echo $name; ?>]" value="<?php echo esc_attr($val); ?>" style="width:100%">
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <div class="tablenav bottom">
                <input type="submit" name="bulk_save" class="button button-primary button-large" value="Save All Changes">
                <div class="tablenav-pages">
                    <?php echo paginate_links(['total' => $query->max_num_pages, 'current' => $paged, 'add_args' => ['limit' => $limit, 's_bulk' => $search, 'post_status' => $status_filter, 'tax_term' => $tax_filter]]); ?>
                </div>
            </div>
        </form>
    </div>
    <?php
    wp_reset_postdata();
}