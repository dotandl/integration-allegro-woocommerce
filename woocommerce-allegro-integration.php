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

    class WAI {
      public function __construct() {
        add_action('admin_init', array($this, 'createSettings'));
        add_action('admin_menu', array($this, 'createMenu'));
      }

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

      public function displayAllegroSection(): void {
        ?>
        <p>Go to <a href="https://apps.developer.allegro.pl/" target="_blank">apps.developer.allegro.pl</a> and create new web app. Then copy Client ID & Secret and paste them here.</p>
        <?php
      }

      public function displayBindingsSection(): void {
        ?>
        <p>Get ID of product from WooCommerce and Allegro and bind them here.</p>
        <?php
      }

      public function displayAllegroIDField(): void {
        $options = get_option('wai_options');
        $value = isset($options['wai_allegro_id_field']) ?
          $options['wai_allegro_id_field'] : '';
        ?>
        <input type="text" name="wai_options[wai_allegro_id_field]" value="<?php echo esc_attr($value); ?>">
        <?php
      }

      public function displayAllegroSecretField(): void {
        $options = get_option('wai_options');
        $value = isset($options['wai_allegro_secret_field']) ?
          $options['wai_allegro_secret_field'] : '';
        ?>
        <input type="password" name="wai_options[wai_allegro_secret_field]" value="<?php echo esc_attr($value); ?>">
        <?php
      }

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

      public function createMenu(): void {
        add_menu_page(
          'WooCommerce & Allegro Integration',
          'WooCommerce & Allegro Integration',
          'manage_options',
          'wai',
          array($this, 'displayMenu')
        );
      }

      public function displayMenu(): void {
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
        <div>
          <h1>WooCommerce & Allegro Integration</h1>
          <form action="options.php" method="post">
          <?php
          settings_fields('wai');
          do_settings_sections('wai');
          ?>
            <p>
              <button type="submit" class="button button-primary">Save settings</button>
              <button class="button button-secondary">Link to Allegro</button>
            </p>
            <p>
              <button class="button button-secondary">Sync WooCommerce -> Allegro</button>
            </p>
            <p>
              <button class="button button-secondary">Sync Allegro -> WooCommerce</button>
            </p>
          </form>
        </div>
        <?php
      }
    }

    new WAI();
  }
}
