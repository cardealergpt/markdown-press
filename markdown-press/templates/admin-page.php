<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div id="markdown-press-export-result"></div>

    <div class="markdown-press-export-options">
        <form id="markdown-press-export-form" method="post">
            <?php wp_nonce_field('markdown_press_export', 'markdown_press_nonce'); ?>
            
            <h2><?php _e('Export Options', 'markdown-press'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="export-type"><?php _e('Export Type', 'markdown-press'); ?></label>
                    </th>
                    <td>
                        <select name="export_type" id="export-type">
                            <option value="all"><?php _e('All Content', 'markdown-press'); ?></option>
                            <option value="selected"><?php _e('Selected Content', 'markdown-press'); ?></option>
                            <option value="taxonomy"><?php _e('By Category/Tag', 'markdown-press'); ?></option>
                        </select>
                    </td>
                </tr>

                <tr class="export-posts-selection" style="display: none;">
                    <th scope="row">
                        <label for="post-select"><?php _e('Select Content', 'markdown-press'); ?></label>
                    </th>
                    <td>
                        <select name="post_ids[]" id="post-select" multiple style="width: 100%; max-width: 400px; height: 200px;">
                            <?php
                            $post_types = get_post_types(['public' => true], 'objects');
                            
                            foreach ($post_types as $post_type) {
                                $posts = get_posts([
                                    'post_type' => $post_type->name,
                                    'posts_per_page' => -1,
                                    'orderby' => 'title',
                                    'order' => 'ASC',
                                    'post_status' => ['publish', 'draft', 'pending', 'private', 'future']
                                ]);

                                if (!empty($posts)) {
                                    printf(
                                        '<optgroup label="%s">',
                                        esc_attr($post_type->labels->name)
                                    );

                                    foreach ($posts as $post) {
                                        $status = get_post_status_object($post->post_status);
                                        $status_label = $status ? $status->label : $post->post_status;
                                        
                                        printf(
                                            '<option value="%d">%s (%s)</option>',
                                            $post->ID,
                                            esc_html($post->post_title),
                                            esc_html($status_label)
                                        );
                                    }

                                    echo '</optgroup>';
                                }
                            }
                            ?>
                        </select>
                        <p class="description"><?php _e('Hold Ctrl/Cmd to select multiple items', 'markdown-press'); ?></p>
                    </td>
                </tr>

                <tr class="export-taxonomy-selection" style="display: none;">
                    <th scope="row">
                        <label for="taxonomy-select"><?php _e('Select Taxonomy', 'markdown-press'); ?></label>
                    </th>
                    <td>
                        <?php
                        $taxonomies = get_taxonomies(['public' => true], 'objects');
                        if (!empty($taxonomies)) :
                        ?>
                            <select name="taxonomy" id="taxonomy-select">
                                <?php
                                foreach ($taxonomies as $taxonomy) {
                                    printf(
                                        '<option value="%s">%s</option>',
                                        esc_attr($taxonomy->name),
                                        esc_html($taxonomy->label)
                                    );
                                }
                                ?>
                            </select>

                            <?php
                            // Create term dropdowns for each taxonomy
                            foreach ($taxonomies as $taxonomy) {
                                $terms = get_terms([
                                    'taxonomy' => $taxonomy->name,
                                    'hide_empty' => false
                                ]);

                                printf(
                                    '<select name="term_id" class="term-select" data-taxonomy="%s" style="margin-left: 10px; %s">',
                                    esc_attr($taxonomy->name),
                                    $taxonomy === reset($taxonomies) ? '' : 'display: none;'
                                );

                                if (!empty($terms)) {
                                    foreach ($terms as $term) {
                                        printf(
                                            '<option value="%d">%s</option>',
                                            $term->term_id,
                                            esc_html($term->name)
                                        );
                                    }
                                } else {
                                    printf(
                                        '<option value="">%s</option>',
                                        sprintf(
                                            /* translators: %s: Taxonomy label */
                                            __('No %s found', 'markdown-press'),
                                            strtolower($taxonomy->label)
                                        )
                                    );
                                }

                                echo '</select>';
                            }
                            ?>
                        <?php else : ?>
                            <p class="description"><?php _e('No taxonomies found', 'markdown-press'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php _e('Export', 'markdown-press'); ?>
                </button>
            </p>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var $exportType = $('#export-type');
    var $postsSelection = $('.export-posts-selection');
    var $taxonomySelection = $('.export-taxonomy-selection');
    var $taxonomySelect = $('#taxonomy-select');
    
    // Show/hide appropriate fields based on export type
    $exportType.on('change', function() {
        var type = $(this).val();
        $postsSelection.toggle(type === 'selected');
        $taxonomySelection.toggle(type === 'taxonomy');
    });
    
    // Update term dropdowns when taxonomy changes
    $taxonomySelect.on('change', function() {
        var selectedTaxonomy = $(this).val();
        $('.term-select').hide()
            .filter('[data-taxonomy="' + selectedTaxonomy + '"]').show()
            .prop('name', 'term_id');
    });
    
    // Trigger initial state
    $taxonomySelect.trigger('change');
});
</script>
