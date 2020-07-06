<?php
/**
 * Plugin Name: WooCommerce & Allegro Integration
 * Plugin URI:  https://github.com/dotandl/woocommerce-allegro-integration
 * Description: Plugin that syncs products' availability between WooCommerce and Allegro
 * Version:     1.0.0
 * Author:      andl
 * Author URI:  https://github.com/dotandl
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wai
 * Domain Path: /i18n
 */

declare(strict_types = 1);

defined('ABSPATH') or die('Error: Plugin has been run outside of WordPress');

if (in_array('woocommerce/woocommerce.php',
  apply_filters('active_plugins', get_option('active_plugins')))) {
  define('LOGFILE', plugin_dir_path(__FILE__) . 'wai-debug.log');

  // If you want to use Allegro Sandbox instead of Allegro,
  // uncomment the line below
  //define('USE_ALLEGRO_SANDBOX', TRUE);

  require_once 'sync.php';

  if (!class_exists('WAI')) {
    /**
     * Main WAI's class
     */
    class WAI {
      use WAISync;

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
        add_action('wai_check_new_orders_allegro',
          array($this, 'processNewOrderAllegro'));

        // Use either Allegro or Allegro Sandbox
        if (defined('USE_ALLEGRO_SANDBOX')) {
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

        file_put_contents(LOGFILE, $message . PHP_EOL, FILE_APPEND);
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
       * @param string $url Server's URL
       * @param string $reqType Request type (e.g. GET, POST, PUT, DELETE)
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
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $url,
          CURLOPT_CUSTOMREQUEST => $reqType,
          CURLOPT_RETURNTRANSFER => TRUE
        ));

        if ($headers !== NULL)
          curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if ($body !== NULL)
          curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

        $res = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);

        $ret = array(
          'response' => $res,
          'http_code' => $code,
          'error' => $err
        );

        curl_close($curl);
        return $ret;
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
        load_plugin_textdomain('wai', FALSE, $langsPath);
      }

      /**
       * Function configuring the cron, refreshing the token and doing many
       * other things
       */
      public function configureCronAndTokenRefreshing(): void {
        $option = get_option('wai_token_expiry');

        if (!empty($option)) {
          $difference = $option['current_datetime']->diff(new DateTime());
          $difference = $this->dateIntervalToSeconds($difference);

          if ($difference >= $option['expires_in'])
            $this->refreshToken();
        }

        if (!wp_next_scheduled('wai_check_new_orders_allegro'))
          wp_schedule_event(time(), 'hourly', 'wai_check_new_orders_allegro');

        if (!get_option('wai_token'))
          add_option('wai_token');

        if (!get_option('wai_refresh_token'))
          add_option('wai_refresh_token');

        if (!get_option('wai_token_expiry'))
          add_option('wai_token_expiry');

        if (!get_option('wai_last_allegro_orders_processed'))
          add_option('wai_last_allegro_orders_processed');
      }

      /**
       * Function creating plugin's settings and doing many other things
       */
      public function createSettings(): void {
        // Check if current page is the WAI's one
        // strtok - explode and get first element
        if (strtok($_SERVER["REQUEST_URI"], '?') === '/wp-admin/tools.php' &&
            $_GET['page'] === 'wai') {
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
                @unlink(LOGFILE);
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

          if (!empty(get_option('wai_delayed_settings_error')) &&
              !defined('DONT_SHOW_SETTINGS_ERROR')) {
            $option = get_option('wai_delayed_settings_error');

            add_settings_error(
              $option['setting'],
              $option['code'],
              $option['message'],
              $option['type']
            );

            delete_option('wai_delayed_settings_error');
          }
        }

        register_setting('wai', 'wai_options');

        add_settings_section(
          'wai_allegro',
          esc_html__('Allegro API settings', 'wai'),
          array($this, 'displayAllegroSection'),
          'wai'
        );

        add_settings_section(
          'wai_bindings',
          esc_html__('Bindings', 'wai'),
          array($this, 'displayBindingsSection'),
          'wai'
        );

        add_settings_field(
          'wai_allegro_id_field',
          esc_html__('Allegro Client ID', 'wai'),
          array($this, 'displayAllegroIDField'),
          'wai',
          'wai_allegro'
        );

        add_settings_field(
          'wai_allegro_secret_field',
          esc_html__('Allegro Client Secret', 'wai'),
          array($this, 'displayAllegroSecretField'),
          'wai',
          'wai_allegro'
        );

        add_settings_field(
          'wai_bindings_field',
          esc_html__('WooCommerce <-> Allegro Bindings', 'wai'),
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
        <p>
          <?php
          printf(
            wp_kses(
              // translators: Address the Allegro Apps page, Plugin options page's base URL
              __('Go to <a href="%1$s" target="_blank">apps.developer.allegro.pl</a> and create new web app. In "Redirect URI" type <code>%2$s</code>. Then copy Client ID & Client Secret and paste them here.', 'wai'),
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
        <p><?php esc_html_e('Get ID of product from WooCommerce and Allegro and bind them here.', 'wai'); ?></p>
        <?php
      }

      /**
       * Function displaying ID field in Allegro section
       */
      public function displayAllegroIDField(): void {
        $options = get_option('wai_options');
        $value = $options['wai_allegro_id_field'] ?? '';
        ?>
        <input type="text" class="wai-input" name="wai_options[wai_allegro_id_field]" value="<?php echo $value; ?>">
        <?php
      }

      /**
       * Function displaying secret field in Allegro section
       */
      public function displayAllegroSecretField(): void {
        $options = get_option('wai_options');
        $value = $options['wai_allegro_secret_field'] ?? '';
        ?>
        <input id="wai-allegro-secret" type="password" class="wai-input" name="wai_options[wai_allegro_secret_field]" value="<?php echo $value; ?>">
        <label for="wai-allegro-secret-toggle-visibility"><?php esc_html_e('Toggle visbility', 'wai'); ?></label>
        <input type="checkbox" id="wai-allegro-secret-toggle-visibility">
        <?php
      }

      /**
       * Function displaying bindings field
       */
      public function displayBindingsField(): void {
        $options = get_option('wai_options');
        $value = !empty($options['wai_bindings_field']) ?
          $options['wai_bindings_field'] : '[]';
        ?>
        <table id="wai-bindings">
          <thead>
            <tr>
              <th><?php esc_html_e('WooCommerce Product ID', 'wai'); ?></th>
              <th><?php esc_html_e('Allegro Product ID', 'wai'); ?></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
        <button id="wai-bindings-add" class="button button-primary">+</button>
        <button id="wai-bindings-remove" class="button button-secondary">-</button>
        <input id="wai-bindings-json" type="hidden" name="wai_options[wai_bindings_field]" value="<?php echo esc_attr($value); ?>">
        <?php
      }

      /**
       * Function creating plugin's menu
       */
      public function createMenu(): void {
        add_management_page(
          __('WooCommerce & Allegro Integration', 'wai'),
          esc_html__('WooCommerce & Allegro Integration', 'wai'),
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
            esc_html__('Settings saved', 'wai'),
            'success'
          );

        settings_errors('wai');

        ?>
        <div class="wrap">
          <h1><?php esc_html_e('WooCommerce & Allegro Integration', 'wai'); ?></h1>
          <h2 class="nav-tab-wrapper">
            <a href="?page=wai&tab=settings" class="nav-tab <?php echo empty($this->activeTab) || $this->activeTab === 'settings' ? 'nav-tab-active' : '';?>"><?php esc_html_e('Settings', 'wai'); ?></a>
            <a href="?page=wai&tab=logs" class="nav-tab <?php echo $this->activeTab === 'logs' ? 'nav-tab-active' : '';?>"><?php esc_html_e('Logs', 'wai'); ?></a>
          </h2>
          <?php
          // Check which tab is active now
          switch ($this->activeTab) {
            case 'settings':
          ?>
          <form action="options.php" method="post" id="wai-form">
          <?php
          settings_fields('wai');
          do_settings_sections('wai');
          ?>
            <p>
              <button id="wai-submit" class="button button-primary"><?php esc_html_e('Save settings', 'wai'); ?></button>
          <?php
          if (empty($options['wai_allegro_id_field']) ||
              empty($options['wai_allegro_secret_field'])) {
            $btnDisabled = TRUE;
          } else {
            $btnDisabled = FALSE;
          }
          ?>
              <button id="wai-link-allegro" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Link to Allegro', 'wai'); ?></button>
            </p>
            <p>
              <button id="wai-sync-woocommerce-allegro" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Sync WooCommerce -> Allegro', 'wai'); ?></button>
            </p>
            <p>
              <button id="wai-sync-allegro-woocommerce" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>><?php esc_html_e('Sync Allegro -> WooCommerce', 'wai'); ?></button>
            </p>
          </form>
          <?php
              break;
            case 'logs':
          ?>
          <h2><?php esc_html_e('Logs', 'wai'); ?></h2>
          <p><?php esc_html_e('Debug info', 'wai'); ?></p>
          <textarea id="wai-logs" rows="10" readonly><?php echo @file_get_contents(LOGFILE); ?></textarea>
          <a href="<?php echo LOGFILE; ?>" class="button button-primary" download><?php esc_html_e('Download log file', 'wai'); ?></a>
          <button class="button button-secondary" id="wai-clean-log"><?php esc_html_e('Clean log file', 'wai'); ?></button>
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
        wp_register_style(
          'wai_style',
          plugins_url('wai-style.css', __FILE__)
        );

        wp_register_script(
          'wai_script',
          plugins_url('wai-script.js', __FILE__),
          array('jquery')
        );

        wp_enqueue_style('wai_style');
        wp_enqueue_script('wai_script');
      }
    }

    new WAI();
  }
}
