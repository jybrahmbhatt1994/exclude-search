<?php

/**
 * Plugin Name: Exclude Search
 * Plugin URI: https://jainishbrahmbhatt.wordpress.com/
 * Description: A plugin to exclude specific posts/pages/product/custom post from WordPress search results.
 * Version: 1.0.0
 * Author: Jainish Brahmbhatt
 * Author URI: https://jainishbrahmbhatt.wordpress.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.1.7
 * Requires PHP: 7.4
 * Text Domain: exclude-search
 * Domain Path: /languages
 *
 * @package ExcludeFromSearch
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

/**
 * Main plugin class
 */
class Exclude_From_Search
{

    /**
     * Instance of this class
     *
     * @var object
     */
    protected static $instance = null;

    /**
     * Current active tab
     *
     * @var string
     */
    private $active_tab;

    /**
     * Items per page
     *
     * @var int
     */
    private $items_per_page = 15;

    /**
     * Option name for storing excluded items
     *
     * @var string
     */
    private $option_name = 'excluded_from_search_items';

    /**
     * Initialize the plugin
     */
    private function __construct()
    {
        // Hook into WordPress
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'handle_form_submission'));
        add_filter('pre_get_posts', array($this, 'exclude_items_from_search'));
    }

    /**
     * Get an instance of this class
     *
     * @return Exclude_From_Search
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add menu item to the admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('Exclude From Search', 'exclude-search'),
            __('Exclude From Search', 'exclude-search'),
            'manage_options',
            'exclude-search',
            array($this, 'display_admin_page'),
            'dashicons-filter',
            26 // After Comments
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_assets($hook)
    {
        if ('toplevel_page_exclude-search' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'exclude-search-admin',
            plugins_url('assets/css/admin-style.css', __FILE__),
            array(),
            '1.0.0',
            'all'
        );
    }

    /**
     * Get excluded items from database
     *
     * @return array
     */
    private function get_excluded_items()
    {
        return get_option($this->option_name, array());
    }

    /**
     * Get available post types for tabs
     *
     * @return array
     */
    private function get_available_post_types()
    {
        $post_types = array(
            'page' => __('Pages', 'exclude-search'),
            'post' => __('Posts', 'exclude-search')
        );

        // Add WooCommerce products if available
        if (class_exists('WooCommerce')) {
            $post_types['product'] = __('Products', 'exclude-search');
        }

        // Get custom post types
        $custom_post_types = get_post_types(
            array(
                'public'   => true,
                '_builtin' => false,
                'exclude_from_search' => false,
            ),
            'objects'
        );

        // Remove WooCommerce product if it exists (we already added it)
        unset($custom_post_types['product']);

        // Add remaining custom post types
        foreach ($custom_post_types as $post_type) {
            $post_types[$post_type->name] = $post_type->labels->name;
        }

        return $post_types;
    }

    /**
     * Handle form submission
     */
    public function handle_form_submission()
    {
        if (
            ! isset($_POST['exclude_from_search_nonce']) ||
            ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['exclude_from_search_nonce'])), 'exclude_from_search_action')
        ) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        $excluded_items = $this->get_excluded_items();
        $post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : '';

        // Remove existing items for this post type
        $excluded_items = array_filter($excluded_items, function ($item) use ($post_type) {
            return get_post_type($item) !== $post_type;
        });

        // Add newly selected items
        if (isset($_POST['exclude_items']) && is_array($_POST['exclude_items'])) {
			$sanitized_array = array_map('sanitize_text_field', wp_unslash($_POST['exclude_items']));
            foreach ($sanitized_array as $item_id) {
                $item_id = absint($item_id);
                if ($item_id && get_post($item_id)) {
                    $excluded_items[] = $item_id;
                }
            }
        }

        update_option($this->option_name, array_unique($excluded_items));

        add_settings_error(
            'exclude_from_search',
            'settings_updated',
            __('Settings saved successfully.', 'exclude-search'),
            'updated'
        );
    }

    /**
     * Exclude items from search results
     *
     * @param WP_Query $query The WP_Query instance.
     * @return WP_Query
     */
    public function exclude_items_from_search($query)
    {
        if (! is_admin() && $query->is_search() && $query->is_main_query()) {
            $excluded_items = $this->get_excluded_items();
            if (! empty($excluded_items)) {
                $query->set('post__not_in', $excluded_items);
            }
        }
        return $query;
    }

    /**
     * Get items for the current post type
     *
     * @return WP_Query
     */
    private function get_items_for_current_tab()
    {
		$verify_nonce = false;
        if (isset($_GET['exclude_from_search_nonce'])) {
            $verify_nonce = wp_verify_nonce(
                sanitize_key(wp_unslash($_GET['exclude_from_search_nonce'])),
                'exclude_from_search_action'
            );
        }
        $paged = 1;
		if ($verify_nonce && isset($_GET['paged'])) {
            $paged = max(1, intval($_GET['paged']));
        }
        $args = array(
            'post_type'      => $this->active_tab,
            'posts_per_page' => $this->items_per_page,
            'paged'          => $paged,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish'
        );

        // Add search functionality if search query exists and nonce is verified
        if ($verify_nonce && isset($_GET['s']) && !empty($_GET['s'])) {
            $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
        }

        return new WP_Query($args);
    }

    /**
     * Display the admin page
     */
    public function display_admin_page()
    {
        // Verify nonce for tab switching
        $default_tab = 'page';
        $this->active_tab = $default_tab;
		if (
            isset($_GET['exclude_from_search_nonce']) &&
            wp_verify_nonce(
                sanitize_key(wp_unslash($_GET['exclude_from_search_nonce'])),
                'exclude_from_search_action'
            )
        ) {
            $this->active_tab = isset($_GET['tab']) ? 
                sanitize_text_field(wp_unslash($_GET['tab'])) : 
                $default_tab;
        }
        $post_types = $this->get_available_post_types();
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php
                foreach ($post_types as $slug => $label) {
                    $class = ($this->active_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
                    $nonce_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'page' => 'exclude-search',
                                'tab' => $slug
                            )
                        ),
                        'exclude_from_search_action',
                        'exclude_from_search_nonce'
                    );
                    printf(
                        '<a href="%1$s" class="%2$s">%3$s</a>',
                        esc_url($nonce_url),
                        esc_attr($class),
                        esc_html($label)
                    );
                }
                ?>
            </nav>

            <div class="tab-content">
                <?php $this->display_tab_content(); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Display content for the current tab
     */
    private function display_tab_content()
    {
        $query = $this->get_items_for_current_tab();
        $post_type_obj = get_post_type_object($this->active_tab);
        $excluded_items = $this->get_excluded_items();
		
		$search_value = '';
		if (
            isset($_GET['exclude_from_search_nonce']) &&
            wp_verify_nonce(
                sanitize_key(wp_unslash($_GET['exclude_from_search_nonce'])),
                'exclude_from_search_action'
            )
        ) {
            $search_value = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        }
    ?>
        <div class="tab-panel">

            <form method="get" action="" class="search-form">
                <?php wp_nonce_field('exclude_from_search_action', 'exclude_from_search_nonce'); ?>
                <input type="hidden" name="page" value="exclude-search">
                <input type="hidden" name="tab" value="<?php echo esc_attr($this->active_tab); ?>">

                <!-- Search Box -->
                <div class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search_value); ?>"
                        placeholder="<?php /* translators: %s is the post */ printf(esc_attr__('Search %s', 'exclude-search'), esc_html($post_type_obj->labels->name)); ?>">
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e('Search', 'exclude-search'); ?>" name="search">
                </div>
            </form>

            <form method="post" action="" class="exclusion-form">
                <?php wp_nonce_field('exclude_from_search_action', 'exclude_from_search_nonce'); ?>
                <input type="hidden" name="post_type" value="<?php echo esc_attr($this->active_tab); ?>">

                <?php settings_errors('exclude_from_search'); ?>

                <?php if ($query->have_posts()) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="check-column">
                                    <input type="checkbox" id="cb-select-all">
                                </th>
                                <th scope="col"><?php esc_html_e('Title', 'exclude-search'); ?></th>
                                <th scope="col"><?php esc_html_e('Date', 'exclude-search'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            while ($query->have_posts()) :
                                $query->the_post();
                                $is_excluded = in_array(get_the_ID(), $excluded_items);
                            ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="exclude_items[]"
                                            value="<?php echo esc_attr(get_the_ID()); ?>"
                                            id="cb-select-<?php echo esc_attr(get_the_ID()); ?>"
                                            <?php checked($is_excluded); ?>>
                                    </th>
                                    <td>
                                        <strong><?php echo esc_html(get_the_title()); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo esc_html(get_the_date()); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($query->max_num_pages > 1) : ?>
						<div class="tablenav bottom">
							<div class="tablenav-pages">
								<?php
								$current_page = 1;
								if (
									isset($_GET['exclude_from_search_nonce']) &&
									wp_verify_nonce(
										sanitize_key(wp_unslash($_GET['exclude_from_search_nonce'])),
										'exclude_from_search_action'
									) &&
									isset($_GET['paged'])
								) {
									$current_page = max(1, intval($_GET['paged']));
								}

								echo wp_kses(
									paginate_links(array(
										'base' => add_query_arg(
											array(
												'paged' => '%#%',
												'exclude_from_search_nonce' => wp_create_nonce('exclude_from_search_action')
											)
										),
										'format'    => '',
										'prev_text' => __('&laquo;', 'exclude-search'),
										'next_text' => __('&raquo;', 'exclude-search'),
										'total'     => $query->max_num_pages,
										'current'   => $current_page,
									)),
									array(
										'a' => array(
											'href' => array(),
											'class' => array()
										),
										'span' => array(
											'class' => array(),
											'aria-current' => array()
										)
									)
								);
								?>
							</div>
						</div>
					<?php endif; ?>

                <?php else : ?>
                    <br><br><br><p><?php /* translators: %s is the post */ printf(esc_html__('No %s found.', 'exclude-search'), esc_html($post_type_obj->labels->name)); ?></p>
                <?php endif;
                wp_reset_postdata();
                ?>

                <!-- Save Changes Button -->
                <div class="alignleft actions">
                    <input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'exclude-search'); ?>">
                </div>
            </form>
        </div>
<?php
    }
}

// Initialize the plugin
function run_exclude_from_search()
{
    return Exclude_From_Search::get_instance();
}

// Start the plugin
run_exclude_from_search();
