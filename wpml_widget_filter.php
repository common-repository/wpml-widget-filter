<?php
/*
  Plugin Name:    WPML Widget Filter
  Plugin URI:     http://shop.zanto.org/
  Description:    Restrict Widgets or Sidebars to a particular language
  Version:        0.1
  Author:         Ayebare Mucunguzi
  Author URI:     http://zanto.org
  Text Domain:    wpml-wf
  Domain Path:   /languages/
 */

$plugin_dir = basename(dirname(__FILE__));
load_plugin_textdomain('wpml-wf', false, dirname(plugin_basename(__FILE__)) . '/languages/');

global $wpml_wf_options;
$wwf_load_points = array('plugins_loaded' => __('when plugin starts (default)', 'wpml-wf'),
    'after_setup_theme' => __('after theme loads', 'wpml-wf'),
    'wp_loaded' => __('when all PHP loaded', 'wpml-wf'),
    'wp_head' => __('during page header', 'wpml-wf')
);

if ((!$wpml_wf_options = get_option('wpml_wf')) || !is_array($wpml_wf_options))
    $wpml_wf_options = array();
if (!defined('ICL_SITEPRESS_VERSION') || ICL_PLUGIN_INACTIVE) {
    add_action('admin_notices', 'wpml_wf_no_wpml_warning');
} else {
    if (is_admin()) {
        add_filter('widget_update_callback', 'wpml_wf_ajax_update_callback', 10, 3);     // widget changes submitted by ajax method
        add_action('sidebar_admin_setup', 'wpml_wf_expand_control');        // before any HTML output save widget changes and add controls to each widget on the widget admin page
        add_action('sidebar_admin_page', 'wpml_wf_options_control');        // add WPML Widget Filter specific options on the widget admin page
        add_filter('plugin_action_links', 'wwf_action_links', 10, 2);         // add my justgiving page link to the plugin admin page
        add_action('admin_menu', 'wpml_wf_settings_page');
        add_action('admin_init', 'wpml_wf_import_export');
    } else {
        if (isset($wpml_wf_options['wpml_wf-options-load_point']) &&
                ($wpml_wf_options['wpml_wf-options-load_point'] != 'plugins_loaded') &&
                array_key_exists($wpml_wf_options['wpml_wf-options-load_point'], $wwf_load_points)
        )
            add_action($wpml_wf_options['wpml_wf-options-load_point'], 'wpml_wf_sidebars_widgets_filter_add');
        else
            wpml_wf_sidebars_widgets_filter_add();
    }
}

function wpml_wf_no_wpml_warning() {
    ?>
    <div class="message error"><p><?php printf(__('WPML Widget Filter is enabled but not effective. It requires <a href="%s">WPML</a> in order to work.', 'wpml-wf'), 'http://wpml.org/'); ?></p></div>
    <?php
}

function wpml_wf_sidebars_widgets_filter_add() {
    add_filter('sidebars_widgets', 'wpml_wf_filter_sidebars_widgets', 10);     // actually remove the widgets from the front end depending on WPML Widget Filter options specified
}

// wp-admin/widgets.php explicitly checks current_user_can('edit_theme_options')
// which is enough security, I believe. If you think otherwise please contact me
// CALLED VIA 'widget_update_callback' FILTER (ajax update of a widget)
function wpml_wf_ajax_update_callback($instance, $new_instance, $this_widget) {
    global $wpml_wf_options;
    $widget_id = $this_widget->id;
    if (isset($_POST[$widget_id . '-wpml_wf'])) {
        $wpml_wf_options[$widget_id] = trim($_POST[$widget_id . '-wpml_wf']);
        update_option('wpml_wf', $wpml_wf_options);
    }
    return $instance;
}

// CALLED VIA 'sidebar_admin_setup' ACTION
// adds in the admin control per widget, but also processes import/export
function wpml_wf_expand_control() {
    global $wp_registered_widgets, $wp_registered_widget_controls, $wpml_wf_options, $sitepress;

    // ADD EXTRA WIDGET  FIELD TO EACH WIDGET CONTROL
    // pop the widget id on the params array (as it's not in the main params so not provided to the callback)
    foreach ($wp_registered_widgets as $id => $widget) { // controll-less widgets need an empty function so the callback function is called.
        if (!isset($wp_registered_widget_controls[$id]))
            wp_register_widget_control($id, $widget['name'], 'wpml_wf_empty_control');
			$wp_registered_widget_controls[$id]['callback_wpml_redirect'] = $wp_registered_widget_controls[$id]['callback'];
        $wp_registered_widget_controls[$id]['callback'] = 'wpml_wf_extra_control';
        array_push($wp_registered_widget_controls[$id]['params'], $id);
    }

    // UPDATE WPML WIDGET FILTER  OPTIONS (via accessibility mode?)
    if ('post' == strtolower($_SERVER['REQUEST_METHOD'])) {
        foreach ((array) $_POST['widget-id'] as $widget_number => $widget_id)
            if (isset($_POST[$widget_id . '-wpml_wf'])) {
                $languages = $sitepress->get_ls_languages();
                $show_array = array();
                foreach ($_POST[$widget_id . '-wpml_wf'] as $lang_code => $status) {
                    if (isset($languages[$lang_code])) {
                        $show_array[] = $lang_code;
                    }
                }
                $wpml_wf_options[$widget_id] = $show_array;
            }


        // clean up empty options (in PHP5 use array_intersect_key)
        $regd_plus_new = array_merge(array_keys($wp_registered_widgets), array_values((array) $_POST['widget-id']), array('wpml_wf-options-filter', 'wpml_wf-options-wp_reset_query', 'wpml_wf-options-load_point'));
        foreach (array_keys($wpml_wf_options) as $key)
            if (!in_array($key, $regd_plus_new))
                unset($wpml_wf_options[$key]);
    }

    // UPDATE OTHER WPML WIDGET FILTER OPTIONS
    // must update this to use http://codex.wordpress.org/Settings_API
    if (isset($_POST['wpml_wf-options-submit'])) {
        $wpml_wf_options['wpml_wf-options-load_point'] = $_POST['wpml_wf-options-load_point'];
        foreach ($_POST['wpml_sb'] as $key => $value) {
            $sb_array[] = $key;
        }
        $wpml_wf_options['wpml_wf-side_bar-options'] = $sb_array;
    }


    update_option('wpml_wf', $wpml_wf_options);
}

// CALLED VIA 'sidebar_admin_page' ACTION
// output extra HTML
// to update using http://codex.wordpress.org/Settings_API asap
function wpml_wf_options_control() {
    global $wp_registered_widget_controls, $wpml_wf_options, $wwf_load_points, $wp_registered_sidebars, $sitepress;
    
    $languages = $sitepress->get_ls_languages();
    ?><div class="wrap">

        <h2><?php _e('WPML Widget Filter (Sidebars)', 'wpml-wf'); ?></h2>
        <form method="POST" style="width:60%">
            <table class="widefat">
                <thead>
                    <tr><th><?php _e('Name','wpml-wf') ?></th><th><?php _e('Description','wpml-wf') ?></th><th><?php _e('Display','wpml-wf') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($wp_registered_sidebars as $sb_id => $sidebar): 
					 if ($sb_id == 'wp_inactive_widgets' || empty($wp_registered_sidebars)){
                            continue;
				     }
					?>
                        <tr>
                            <td><?php echo $sidebar['name'] ?></td>
                            <td><?php echo $sidebar['description'] ?></td>
                            <td>
                                <?php
                                $sb_options = !empty($wpml_wf_options['wpml_wf-side_bar-options']) ? $wpml_wf_options['wpml_wf-side_bar-options'] : '';
                                // output our language fields
                                $checked = '';
                                foreach ($languages as $lang_code => $l_details):

                                    if (is_array($sb_options) && !empty($sb_options)) {
                                        $value = $sb_id . '-' . $l_details['language_code'];
                                        $checked = (in_array($value, $sb_options)) ? 'checked="checked"' : '';
                                    } else {
                                        $checked = 'checked="checked"';
                                    }
                                    ?>
                                    <span style="float:left; padding: 4px"><label><input name="<?php echo 'wpml_sb[' . $sb_id . '-' . $l_details['language_code'] . ']' ?>" <?php echo $checked ?> type="checkbox"> <?php echo $l_details['native_name'] ?></label></span>
                                <?php endforeach; ?>
                                <div class="clear"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            

            <ul>
                <li><label for="wpml_wf-options-load_point" title="<?php _e('Delays WPML Widget Filter code being evaluated til various points in the WP loading process', 'wpml-wf'); ?>"><?php _e('Implement Filters', 'wpml-wf'); ?>
                        <select id="wpml_wf-options-load_point" name="wpml_wf-options-load_point" ><?php
                foreach ($wwf_load_points as $action => $action_desc) {
                    echo "<option value='" . $action . "'";
                    if (isset($wpml_wf_options['wpml_wf-options-load_point']) && $action == $wpml_wf_options['wpml_wf-options-load_point'])
                        echo " selected ";
                    echo ">" . $action_desc . "</option>"; // 
                }
                    ?>
                        </select>
                    </label>
                </li>
            </ul>
			<p> <?php _e("Follow us:", "wpml-wf"); ?> <a href="http://twitter.com/zantowp"><?php _e("Twitter", "wpml-wf"); ?></a> &nbsp; <?php _e("Get help:", "wpml-wf"); ?> <a href="http://zanto.org/support"><?php _e("Support", "wpml-wf"); ?></a>&nbsp; <?php _e("Get more:", "wpml-wf"); ?> <a href="http://shop.zanto.org"><?php _e("More Addons", "wpml-wf"); ?></a></p>

            <?php submit_button(__('Save WPML-WF options', 'wpml-wf'), 'button-primary', 'wpml_wf-options-submit', false); ?>

        </form>
    </div>

    <?php
}

// added to widget functionality in 'wpml_wf_expand_control' (above)
function wpml_wf_empty_control() {
    
}

// added to widget functionality in 'wpml_wf_expand_control' (above)
function wpml_wf_extra_control() {
    global $wp_registered_widget_controls, $wpml_wf_options, $sitepress;
    $languages = $sitepress->get_ls_languages();
    //print_r($languages);
    $params = func_get_args();
    $id = array_pop($params);

    // go to the original control function
    $callback = $wp_registered_widget_controls[$id]['callback_wpml_redirect'];
    if (is_callable($callback))
        call_user_func_array($callback, $params);

    $value = !empty($wpml_wf_options[$id]) ? $wpml_wf_options[$id] : '';

    // dealing with multiple widgets - get the number. if -1 this is the 'template' for the admin interface
    $id_disp = $id;
    if (!empty($params) && isset($params[0]['number'])) {
        $number = $params[0]['number'];
        if ($number == -1) {
            $number = "__i__";
            $value = "";
        }
        $id_disp = $wp_registered_widget_controls[$id]['id_base'] . '-' . $number;
    }
    // output our extra language fields
    $checked = '';
	echo '<div style="margin:1.5em 0 2em">';
    foreach ($languages as $lang_code => $l_details):

        if (is_array($value) && !empty($value)) {
            $checked = (in_array($lang_code, $value)) ? 'checked="checked"' : '';
        } else {
            $checked = 'checked="checked"';
        }
        ?>
        <span style="float:left; padding: 4px"><label><input name="<?php echo $id_disp . '-wpml_wf[' . $l_details['language_code'] . ']' ?>" <?php echo $checked ?> type="checkbox"> <?php echo $l_details['native_name'] ?></label></span>
        <?php
    endforeach;
    echo '<div class="clear"></div></div>';
}

// CALLED ON 'plugin_action_links' ACTION
function wwf_action_links($links, $file) {
    if ($file == plugin_basename(__FILE__))
        array_push($links, '<a href="http://zanto.org/support">' . __('support', 'wpml-wf') . '</a>');
    return $links;
}

// FRONT END FUNCTIONS...
// CALLED ON 'sidebars_widgets' FILTER
function wpml_wf_filter_sidebars_widgets($sidebars_widgets) {
    global $wpml_wf_options, $sitepress;
    $cur_lang = $sitepress->get_current_language();

    // loop through every widget in every sidebar (barring 'wp_inactive_widgets') checking WWF for each one
    foreach ($sidebars_widgets as $widget_area => $widget_list) {
        if ($widget_area == 'wp_inactive_widgets' || empty($widget_list))
            continue;

        $sb_options = !empty($wpml_wf_options['wpml_wf-side_bar-options']) ? $wpml_wf_options['wpml_wf-side_bar-options'] : '';
        if (is_array($sb_options) && !empty($sb_options)) {
            $value = $widget_area . '-' . $cur_lang;
            if (!in_array($value, $sb_options)) {
                unset($sidebars_widgets[$widget_area]);
                continue;
            }
        }

        foreach ($widget_list as $pos => $widget_id) {
            if (!isset($wpml_wf_options[$widget_id]) || empty($wpml_wf_options[$widget_id]))
                continue;

            $wwf_value = $wpml_wf_options[$widget_id];
            $wwf_value = apply_filters("wpml_wf_eval_override", $wwf_value);
            if (!in_array($cur_lang, $wwf_value)) {
                unset($sidebars_widgets[$widget_area][$pos]);
                continue;
            }
        }
    }
    return $sidebars_widgets;
}

// Register the settings page
function wpml_wf_settings_page() {
    add_options_page(__('WPML Widget Filter Settings', 'wpml-wf'), __('WPML Widget Filter', 'wpml-wf'), 'manage_options', 'wpml_wf', 'wpml_wf_page');
}

function wpml_wf_page() {
global $wpml_wf_options;
if (isset($wpml_wf_options['msg'])) {
        if (substr($wpml_wf_options['msg'], 0, 2) == "OK")
            echo '<div id="message" class="updated">';
        else
            echo '<div id="message" class="error">';
        echo '<p>WPML Widget Filter – ' . $wpml_wf_options['msg'] . '</p></div>';
        unset($wpml_wf_options['msg']);
        update_option('wpml_wf', $wpml_wf_options);
    }
    ?>
 
    <div class="wrap">

        <h2><?php _e('WPML Widget Filter Settings', 'wpml-wf'); ?></h2>
        <form method="POST" enctype="multipart/form-data" style="width:50%">
            <a class="submit button" href="?wwf-options-export" title="<?php _e('Save all WPML-WF options to a plain text config file', 'wpml-wf'); ?>"><?php _e('Export options', 'wpml-wf'); ?></a><p>
                <?php submit_button(__('Import options', 'wpml-wf'), 'button', 'wwf-options-import', false, array('title' => __('Load all WPML Widget Filter options from a plain text config file', 'wpml-wf'))); ?>
                <input type="file" name="wwf-options-import-file" id="wwf-options-import-file" title="<?php _e('Select file for importing', 'wpml-wf'); ?>" /></p>
        </form>
        <p> <a href="shop.zanto.org"><?php _e('Get more free and premium WordPress mulitilingual plugins like this one', 'wpml-wf'); ?></p>
    </div>
    <?php
}

function wpml_wf_import_export() {
    $wpml_wf_options = get_option('wpml_wf');
    // EXPORT ALL OPTIONS
    if (isset($_GET['wwf-options-export'])) {
        header("Content-Disposition: attachment; filename=wpml_wf_options.txt");
        header('Content-Type: text/plain; charset=utf-8');

        echo "[START=WPML WIDGET FILTER OPTIONS]\n";
        foreach ($wpml_wf_options as $id => $text)
            echo "$id\t" . json_encode($text) . "\n";
        echo "[STOP=WPML WIDGET FILTER OPTIONS]";
        exit;
    }
  

    // IMPORT ALL OPTIONS
    if (isset($_POST['wwf-options-import'])) {
        if ($_FILES['wwf-options-import-file']['tmp_name']) {
            $import = split("\n", file_get_contents($_FILES['wwf-options-import-file']['tmp_name'], false));
            if (array_shift($import) == "[START=WPML WIDGET FILTER OPTIONS]" && array_pop($import) == "[STOP=WPML WIDGET FILTER OPTIONS]") {
                foreach ($import as $import_option) {
                    list($key, $value) = split("\t", $import_option);
                    $wpml_wf_options[$key] = json_decode($value);
                }
                $wpml_wf_options['msg'] = __('Success! Options file imported', 'wpml-wf');
            } else {
                $wpml_wf_options['msg'] = __('Invalid options file', 'wpml-wf');
            }
        }
        else
            $wpml_wf_options['msg'] = __('No options file provided', 'wpml-wf');

        update_option('wpml_wf', $wpml_wf_options);
        wp_redirect(admin_url('options-general.php?page=wpml_wf'));
        exit;
    }
}
?>