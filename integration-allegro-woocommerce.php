<?php
/**
 * Plugin Name: Integration of Allegro and WooCommerce
 * Plugin URI:  https://github.com/dotandl/integration-allegro-woocommerce
 * Description: Plugin that syncs products' availability between WooCommerce and Allegro
 * Version:     1.0.0
 * Author:      andl
 * Author URI:  https://github.com/dotandl
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: waint
 * Domain Path: /i18n
 */

// WAInt prefix in the code means: <W>ooCommerce <A>llegro <Int>egration

declare(strict_types = 1);

defined('ABSPATH') or die('Error: Plugin has been run outside of WordPress');

if (in_array('woocommerce/woocommerce.php',
  apply_filters('active_plugins', get_option('active_plugins')))) {
  define('WAINT_LOGFILE', plugin_dir_path(__FILE__) . 'waint-debug.log');

  // If you want to use Allegro Sandbox instead of Allegro,
  // uncomment the line below
  //define('WAINT_USE_ALLEGRO_SANDBOX', TRUE);

  require_once 'Sync.php';

  /**
   * Main plugin's class
   */
  class WAInt_Main {
    use WAInt_Sync;

    /**
     * Field storing info about selected tab in the menu
     */
    private $activeTab;

    /**
     * Base URL to either Allegro or Allegro Sanbox
     */
    private $allegroUrl;

    /**
     * Base URL to either Allegro API or Allegro Sanbox API
     */
    private $allegroApiUrl;

    /**
     * Base URL to either Allegro Apps Management Menu or
     * Allegro Sanbox Apps Management Menu
     */
    private $allegroAppsUrl;

    /**
     * Default constructor
     */
    public function __construct() {
      // Bind actions to methods
      add_action('plugins_loaded', array($this, 'i18nLoad'));
      add_action('admin_init', array($this, 'createSettings'));
      add_action('admin_menu', array($this, 'createMenu'));
      add_action('admin_enqueue_scripts', array($this, 'loadStylesScripts'));
      add_action('init', array($this, 'configureCronAndTokenRefreshing'));
      add_action('woocommerce_thankyou',
        array($this, 'hookNewOrderWooCommerce'));
      add_action('waint_check_new_orders_allegro',
        array($this, 'processNewOrderAllegro'));

      // Use either Allegro or Allegro Sandbox
      if (defined('WAINT_USE_ALLEGRO_SANDBOX')) {
        $this->allegroUrl = 'https://allegro.pl.allegrosandbox.pl';
        $this->allegroApiUrl = 'https://api.allegro.pl.allegrosandbox.pl';
        $this->allegroAppsUrl =
          'https://apps.developer.allegro.pl.allegrosandbox.pl';
      } else {
        $this->allegroUrl = 'https://allegro.pl';
        $this->allegroApiUrl = 'https://api.allegro.pl';
        $this->allegroAppsUrl = 'https://apps.developer.allegro.pl';
      }
    }

    /**
     * Function logging a message to the log file
     *
     * @param DateTime $time Timestamp which will be written to log file
     * @param string $message The message
     * @param string $funcName Name of the function the message is from
     * @param string $messageType Type of the message ('INFO', 'SUCCESS', 'ERROR')
     */
    private function log(
      DateTime $time,
      string $funcName,
      string $message,
      string $messageType = 'INFO'
    ): void {
      $time = $time->format(DateTimeInterface::ISO8601);

      if ($messageType !== 'INFO' &&
          $messageType !== 'SUCCESS' &&
          $messageType !== 'ERROR')
        $messageType = 'INFO';

      $message = "[$time] ($messageType) $funcName '$message'";

      file_put_contents(WAINT_LOGFILE, $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Function getting current URL
     *
     * @return string Current URL
     */
    private function getCurrentUrl(): string {
      return (isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
        "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    }

    /**
     * Function removing the given param from URL
     *
     * This function gets query string from URL, matches param using regex
     * and removes it.
     *
     * @param string $url URL to remove a param from
     * @param string $param Parameter to remove
     * @return string URL without the parameter
     */
    private function removeParamFromUrl(string $url, string $param): string {
      $explode = explode('?', $url);
      if (!isset($explode[1]))
        return $explode[0];

      $explode[1] = preg_replace(
        "/&$param(=[^&]*)?|^$param(=[^&]*)?&?/",
        '',
        $explode[1]
      );

      return $explode[0] . '?' . $explode[1];
    }

    /**
     * Function getting base, clean URL to options menu
     *
     * This function gets current URL and removes from it as many parameters
     * as possible.
     *
     * @return string Clean URL
     */
    private function getCleanUrl(): string {
      $url = $this->getCurrentUrl();

      $url = $this->removeParamFromUrl($url, 'tab');
      $url = $this->removeParamFromUrl($url, 'code');
      $url = $this->removeParamFromUrl($url, 'state');
      $url = $this->removeParamFromUrl($url, 'settings-updated');
      $url = $this->removeParamFromUrl($url, 'action');

      return $url;
    }

    /**
     * Function generating a string for PKCE validation
     *
     * This function generates a random string in length between 43 and 128
     * characters.
     *
     * @return string Final string
     */
    private function generateStringForPkce(): string {
      $length = rand(43, 128);

      $characters =
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
      $charactersLength = strlen($characters);

      $randomString = '';
      for ($i = 0; $i < $length; $i++) {
          $randomString .= $characters[rand(0, $charactersLength - 1)];
      }

      return $randomString;
    }

    /**
     * Function encoding the string for PKCE validation
     *
     * This function converts string to an ASCII string, generates
     * an SHA256 hash from it and encodes it to base64 string.
     *
     * @param string $str String to encode
     * @return string Encoded string
     */
    private function encodeStringForPkce(string $str): string {
      $str = mb_convert_encoding($str, 'ASCII');
      $hash = hash('SHA256', $str, TRUE);
      $str = base64_encode($hash);

      $str = str_replace('+', '-', $str);
      $str = str_replace('/', '_', $str);
      $str = str_replace('=', '', $str);

      return $str;
    }

    /**
     * Function sending an HTTP request to the external server
     *
     * Note that the only HTTP methods supported by this function are GET,
     * POST and PUT. If there's a need to use another methods, modify the
     * method-checking if statement.
     *
     * @param string $url Server's URL
     * @param string $reqType Request type (e.g. GET, POST, PUT)
     * @param array $headers Additional HTTP request headers
     * @param string $body Additional request body
     * @return array Response, HTTP code and error
     */
    private function sendRequest(
      string $url,
      string $reqType,
      array $headers = NULL,
      string $body = NULL
    ): array {
      // Modify this is statement if you need to use other HTTP methods
      if ($reqType !== 'GET' &&
          $reqType !== 'POST' &&
          $reqType !== 'PUT')
        $reqType = 'GET';

      $args = array(
        'method' => $reqType,
        'headers' => $headers,
        'body' => $body
      );

      $res = wp_remote_request($url, $args);

      return is_wp_error($res) ? array(
        'error' => $res->get_error_message()
      ) : array(
        'response' => $res['body'],
        'http_code' => $res['response']['code']
      );
    }

    /**
     * Function converting a DateInterval object into seconds
     *
     * This function converts only days, hours, minutes and seconds
     * into seconds (not years, months etc.)
     *
     * @param DateTinterval $interval DateInterval object to convert
     * @return int The result of the conversion
     */
    private function dateIntervalToSeconds(DateInterval $interval): int {
      return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60
        + $interval->s;
    }

    public function i18nLoad(): void {
      $langsPath = basename(dirname(__FILE__)) . '/i18n';
      load_plugin_textdomain('waint', FALSE, $langsPath);
    }

    /**
     * Function configuring the cron, refreshing the token and doing many
     * other things
     */
    public function configureCronAndTokenRefreshing(): void {
      $option = get_option('waint_token_expiry');

      if (!empty($option)) {
        $difference = $option['current_datetime']->diff(new DateTime());
        $difference = $this->dateIntervalToSeconds($difference);

        if ($difference >= $option['expires_in'])
          $this->refreshToken();
      }

      if (!wp_next_scheduled('waint_check_new_orders_allegro'))
        wp_schedule_event(time(), 'hourly', 'waint_check_new_orders_allegro');

      if (!get_option('waint_token'))
        add_option('waint_token');

      if (!get_option('waint_refresh_token'))
        add_option('waint_refresh_token');

      if (!get_option('waint_token_expiry'))
        add_option('waint_token_expiry');

      if (!get_option('waint_last_allegro_orders_processed'))
        add_option('waint_last_allegro_orders_processed');
    }

    /**
     * Function creating plugin's settings and doing many other things
     */
    public function createSettings(): void {
      // Check if current page is the Integration panel
      // strtok - explode and get first element
      if (strtok($_SERVER["REQUEST_URI"], '?') === '/wp-admin/tools.php' &&
          $_GET['page'] === 'waint') {
        $this->activeTab = $_GET['tab'] ?? 'settings';

        if (isset($_GET['code']))
          $this->getToken();

        if (isset($_GET['action'])) {
          $refresh = TRUE;

          switch ($_GET['action']) {
            case 'sync-allegro-woocommerce':
              $this->syncAllAllegroWooCommerce();
              break;
            case 'sync-woocommerce-allegro':
              $this->syncAllWooCommerceAllegro();
              break;
            case 'clean-log-file':
              @unlink(WAINT_LOGFILE);
              break;
            case 'link-allegro':
              $this->linkToAllegro();
              $refresh = FALSE;
              break;
            default:
              $refresh = FALSE;
              break;
          }

          if ($refresh === TRUE)
            header("Location: {$this->getCleanUrl()}");
        }

        if (!empty(get_option('waint_delayed_settings_error')) &&
            !defined('WAINT_DONT_SHOW_SETTINGS_ERROR')) {
          $option = get_option('waint_delayed_settings_error');

          add_settings_error(
            $option['setting'],
            $option['code'],
            $option['message'],
            $option['type']
          );

          delete_option('waint_delayed_settings_error');
        }
      }

      register_setting('waint', 'waint_options');

      add_settings_section(
        'waint_allegro',
        esc_html__('Allegro API settings', 'waint'),
        array($this, 'displayAllegroSection'),
        'waint'
      );

      add_settings_section(
        'waint_bindings',
        esc_html__('Bindings', 'waint'),
        array($this, 'displayBindingsSection'),
        'waint'
      );

      add_settings_field(
        'waint_allegro_id_field',
        esc_html__('Allegro Client ID', 'waint'),
        array($this, 'displayAllegroIDField'),
        'waint',
        'waint_allegro'
      );

      add_settings_field(
        'waint_allegro_secret_field',
        esc_html__('Allegro Client Secret', 'waint'),
        array($this, 'displayAllegroSecretField'),
        'waint',
        'waint_allegro'
      );

      add_settings_field(
        'waint_bindings_field',
        esc_html__('WooCommerce <-> Allegro Bindings', 'waint'),
        array($this, 'displayBindingsField'),
        'waint',
        'waint_bindings'
      );
    }

    /**
     * Function displaying the Allegro section
     */
    public function displayAllegroSection(): void {
      ?>
      <p>
        <?php
        printf(
          wp_kses(
            // translators: Address the Allegro Apps page, Plugin options page's base URL
            __('Go to <a href="%1$s" target="_blank">apps.developer.allegro.pl</a> and create new web app. In "Redirect URI" type <code>%2$s</code>. Then copy Client ID & Client Secret and paste them here.', 'waint'),
            array('a' => array('href' => array(), 'target' => array()), 'code' => array())
          ), $this->allegroAppsUrl, $this->getCleanUrl()
        );
        ?>
      </p>
      <?php
    }

    /**
     * Function displaying bindings section
     */
    public function displayBindingsSection(): void {
      ?>
      <p><?php esc_html_e('Get ID of product from WooCommerce and Allegro and bind them here.', 'waint'); ?></p>
      <?php
    }

    /**
     * Function displaying ID field in Allegro section
     */
    public function displayAllegroIDField(): void {
      $options = get_option('waint_options');
      $value = $options['waint_allegro_id_field'] ?? '';
      ?>
      <input type="text" class="waint-input" name="waint_options[waint_allegro_id_field]" value="<?php echo $value; ?>">
      <?php
    }

    /**
     * Function displaying secret field in Allegro section
     */
    public function displayAllegroSecretField(): void {
      $options = get_option('waint_options');
      $value = $options['waint_allegro_secret_field'] ?? '';
      ?>
      <input id="waint-allegro-secret" type="password" class="waint-input" name="waint_options[waint_allegro_secret_field]" value="<?php echo $value; ?>">
      <label for="waint-allegro-secret-toggle-visibility"><?php esc_html_e('Toggle visbility', 'waint'); ?></label>
      <input type="checkbox" id="waint-allegro-secret-toggle-visibility">
      <?php
    }

    /**
     * Function displaying bindings field
     */
    public function displayBindingsField(): void {
      $options = get_option('waint_options');
      $value = !empty($options['waint_bindings_field']) ?
        $options['waint_bindings_field'] : '[]';
      ?>
      <table id="waint-bindings">
        <thead>
          <tr>
            <th><?php esc_html_e('WooCommerce Product ID', 'waint'); ?></th>
            <th><?php esc_html_e('Allegro Product ID', 'waint'); ?></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <button id="waint-bindings-add" class="button button-primary">+</button>
      <button id="waint-bindings-remove" class="button button-secondary">-</button>
      <input id="waint-bindings-json" type="hidden" name="waint_options[waint_bindings_field]" value="<?php echo esc_attr($value); ?>">
      <?php
    }

    /**
     * Function creating plugin's menu
     */
    public function createMenu(): void {
      add_management_page(
        __('Integration of Allegro and WooCommerce', 'waint'),
        esc_html__('Integration of Allegro and WooCommerce', 'waint'),
        'manage_options',
        'waint',
        array($this, 'displayMenu')
      );
    }

    /**
     * Function displaying the menu
     */
    public function displayMenu(): void {
      $options = get_option('waint_options');

      if (!current_user_can('manage_options'))
        return;

      if (isset($_GET['settings-updated']))
        add_settings_error(
          'waint',
          'waint_error',
          esc_html__('Settings saved', 'waint'),
          'success'
        );

      settings_errors('waint');

      ?>
      <div class="wrap">
        <h1><?php esc_html_e('Integration of Allegro and WooCommerce', 'waint'); ?></h1>
        <h2 class="nav-tab-wrapper">
          <a href="?page=waint&tab=settings" class="nav-tab <?php echo empty($this->activeTab) || $this->activeTab === 'settings' ? 'nav-tab-active' : '';?>"><?php esc_html_e('Settings', 'waint'); ?></a>
          <a href="?page=waint&tab=logs" class="nav-tab <?php echo $this->activeTab === 'logs' ? 'nav-tab-active' : '';?>"><?php esc_html_e('Logs', 'waint'); ?></a>
        </h2>
        <?php
        // Check which tab is active now
        switch ($this->activeTab) {
          case 'settings':
        ?>
        <form action="options.php" method="post" id="waint-form">
        <?php
        settings_fields('waint');
        do_settings_sections('waint');
        ?>
          <p>
            <button id="waint-submit" class="button button-primary"><?php esc_html_e('Save settings', 'waint'); ?></button>
        <?php
        if (empty($options['waint_allegro_id_field']) ||
            empty($options['waint_allegro_secret_field'])) {
          $btnDisabled = TRUE;
        } else {
          $btnDisabled = FALSE;
        }
        ?>
            <button id="waint-link-allegro" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Link to Allegro', 'waint'); ?></button>
          </p>
          <p>
            <button id="waint-sync-woocommerce-allegro" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Sync WooCommerce -> Allegro', 'waint'); ?></button>
          </p>
          <p>
            <button id="waint-sync-allegro-woocommerce" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Sync Allegro -> WooCommerce', 'waint'); ?></button>
          </p>
        </form>
        <?php
            break;
          case 'logs':
        ?>
        <h2><?php esc_html_e('Logs', 'waint'); ?></h2>
        <p><?php esc_html_e('Debug info', 'waint'); ?></p>
        <textarea id="waint-logs" rows="10" readonly><?php echo @file_get_contents(WAINT_LOGFILE); ?></textarea>
        <a href="<?php echo WAINT_LOGFILE; ?>" class="button button-primary" download><?php esc_html_e('Download log file', 'waint'); ?></a>
        <button class="button button-secondary" id="waint-clean-log"><?php esc_html_e('Clean log file', 'waint'); ?></button>
        <?php
            break;
        }
        ?>
      </div>
      <?php
    }

    /**
     * Function loading Integration's styles & scripts
     */
    public function loadStylesScripts(): void {
      wp_register_style(
        'waint_style',
        plugins_url('waint-style.css', __FILE__)
      );

      wp_register_script(
        'waint_script',
        plugins_url('waint-script.js', __FILE__),
        array('jquery')
      );

      wp_enqueue_style('waint_style');
      wp_enqueue_script('waint_script');
    }
  }

  new WAInt_Main();
}
