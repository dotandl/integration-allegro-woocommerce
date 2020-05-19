<?php
/**
 * Plugin Name: WooCommerce & Allegro Integration
 * Description: Plugin that syncs products' availability between WooCommerce and Allegro
 * Version:     1.0.0
 */

declare(strict_types = 1);

defined('ABSPATH') or die('Error: Plugin has been run outside of WordPress');

if (in_array('woocommerce/woocommerce.php',
  apply_filters('active_plugins', get_option('active_plugins')))) {

  if (!class_exists('WAI')) {
    /**
     * Main WAI's class
     */
    class WAI {
      private $activeTab;

      public function __construct() {
        // Register styles & scripts
        wp_register_style(
          'wai_styles',
          plugins_url('wai-styles.css', __FILE__)
        );

        // Bind actions to methods
        add_action('admin_init', array($this, 'createSettings'));
        add_action('admin_menu', array($this, 'createMenu'));
        add_action('admin_enqueue_scripts', array($this, 'loadStylesScripts'));
      }

      /**
       * Function logging a message to the log file
       *
       * @param DateTime $time Timestamp which will be written to log file
       * @param string $message The message
       * @param string $messageType Type of the message ('INFO', 'SUCCESS', 'ERROR')
       */
      private function log(DateTime $time, string $message, string $messageType = 'INFO'): void {
        $time = $time->format(DateTimeInterface::ISO8601);

        if ($messageType !== 'INFO' ||
            $messageType !== 'SUCCESS' ||
            $messageType !== 'ERROR')
          $messageType = 'INFO';

        $message = "[$time] $messageType $message";

        error_log(
          $message . PHP_EOL,
          3,
          plugin_dir_path(__FILE__) . 'wai-debug.log'
        );
      }

      /**
       * Function creating plugin's settings
       */
      public function createSettings(): void {
        register_setting('wai', 'wai_options');

        add_settings_section('wai_allegro', 'Allegro API settings', array($this, 'displayAllegroSection'), 'wai');
        add_settings_section('wai_bindings', 'Bindings', array($this, 'displayBindingsSection'), 'wai');

        add_settings_field(
          'wai_allegro_id_field',
          'Allegro Client ID',
          array($this, 'displayAllegroIDField'),
          'wai',
          'wai_allegro'
        );

        add_settings_field(
          'wai_allegro_secret_field',
          'Allegro Client Secret',
          array($this, 'displayAllegroSecretField'),
          'wai',
          'wai_allegro'
        );

        add_settings_field(
          'wai_bindings_field',
          'WooCommerce <-> Allegro Bindings',
          array($this, 'displayBindingsField'),
          'wai',
          'wai_bindings'
        );
      }

      /**
       * Function displaying the Allegro section
       */
      public function displayAllegroSection(): void {
        ?>
        <p>Go to <a href="https://apps.developer.allegro.pl/" target="_blank">apps.developer.allegro.pl</a> and create new web app. Then copy Client ID & Secret and paste them here.</p>
        <?php
      }

      /**
       * Function displaying bindings section
       */
      public function displayBindingsSection(): void {
        ?>
        <p>Get ID of product from WooCommerce and Allegro and bind them here.</p>
        <?php
      }

      /**
       * Function displaying ID field in Allegro section
       */
      public function displayAllegroIDField(): void {
        $options = get_option('wai_options');
        $value = isset($options['wai_allegro_id_field']) ?
          $options['wai_allegro_id_field'] : '';
        ?>
        <input type="text" name="wai_options[wai_allegro_id_field]" value="<?php echo $value; ?>">
        <?php
      }

      /**
       * Function displaying secret field in Allegro section
       */
      public function displayAllegroSecretField(): void {
        $options = get_option('wai_options');
        $value = isset($options['wai_allegro_secret_field']) ?
          $options['wai_allegro_secret_field'] : '';
        ?>
        <input type="password" name="wai_options[wai_allegro_secret_field]" value="<?php echo $value; ?>">
        <?php
      }

      /**
       * Function displaying bindings field
       */
      public function displayBindingsField(): void {
        $options = get_option('wai_options');
        $value = isset($options['wai_bindings_field']) ?
          $options['wai_bindings_field'] : '';
        ?>
        <table>
          <thead>
            <tr>
              <th>WooCommerce Product ID</th>
              <th>Allegro Product ID</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <button class="button button-primary">+</button>
        <button class="button button-secondary">-</button>
        <input type="hidden" name="wai_options[wai_bindings_field]" value="<?php echo esc_attr($value); ?>">
        <?php
      }

      /**
       * Function creating plugin's menu
       */
      public function createMenu(): void {
        $this->activeTab = $_GET['tab'];

        add_menu_page(
          'WooCommerce & Allegro Integration',
          'WooCommerce & Allegro Integration',
          'manage_options',
          'wai',
          array($this, 'displayMenu')
        );
      }

      /**
       * Function displaying the menu
       */
      public function displayMenu(): void {
        $options = get_option('wai_options');

        if (!current_user_can('manage_options'))
          return;

        if (isset($_GET['settings-updated']))
          add_settings_error(
            'wai',
            'wai_error',
            'Settings saved',
            'success'
          );

        settings_errors('wai');

        ?>
        <div class="wrap">
          <h1>WooCommerce & Allegro Integration</h1>
          <h2 class="nav-tab-wrapper">
            <a href="?page=wai&tab=settings" class="nav-tab <?php echo $this->activeTab === 'settings' ? 'nav-tab-active' : '';?>">Settings</a>
            <a href="?page=wai&tab=logs" class="nav-tab <?php echo $this->activeTab === 'logs' ? 'nav-tab-active' : '';?>">Logs</a>
          </h2>
          <?php
          // Check which tab is active now
          switch ($this->activeTab) {
            default:
            case 'settings':
          ?>
          <form action="options.php" method="post">
          <?php
          settings_fields('wai');
          do_settings_sections('wai');
          ?>
            <p>
              <button type="submit" class="button button-primary">Save settings</button>
          <?php
          if (!isset($options['wai_allegro_id_field']) ||
              empty($options['wai_allegro_id_field']) ||
              !isset($options['wai_allegro_secret_field']) ||
              empty($options['wai_allegro_secret_field'])):
          ?>
              <button class="button button-secondary" disabled>Link to Allegro</button>
            </p>
            <p>
              <button class="button button-secondary" disabled>Sync WooCommerce -> Allegro</button>
            </p>
            <p>
              <button class="button button-secondary" disabled>Sync Allegro -> WooCommerce</button>
            </p>
            <?php else: ?>
              <button class="button button-secondary">Link to Allegro</button>
            </p>
            <p>
              <button class="button button-secondary">Sync WooCommerce -> Allegro</button>
            </p>
            <p>
              <button class="button button-secondary">Sync Allegro -> WooCommerce</button>
            </p>
            <?php endif; ?>
          </form>
          <?php
              break;
            case 'logs':
          ?>
          <h2>Logs</h2>
          <p>Debug info</p>
          <textarea id="logs-textarea" rows="10" readonly><?php echo @file_get_contents(plugin_dir_path(__FILE__) . 'wai-debug.log'); ?></textarea>
          <a href="<?php echo plugins_url('wai-debug.log', __FILE__); ?>" class="button button-primary" download>Download log file</a>
          <?php
              break;
          }
          ?>
        </div>
        <?php
      }

      /**
       * Function loading WAI's styles & scripts
       */
      public function loadStylesScripts(): void {
        wp_enqueue_style('wai_styles');
      }
    }

    new WAI();
  }
}
