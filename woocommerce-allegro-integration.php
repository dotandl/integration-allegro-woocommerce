<?php
/**
 * Plugin Name: WooCommerce & Allegro Integration
 * Description: Plugin that syncs products' availability between WooCommerce and Allegro
 * Version:     1.0.0
 */

declare(strict_types = 1);

define('LOGFILE', plugin_dir_path(__FILE__) . 'wai-debug.log');

// If you want to use Allegro Sandbox instead of Allegro,
// uncomment the line below
define('USE_ALLEGRO_SANDBOX', TRUE);

defined('ABSPATH') or die('Error: Plugin has been run outside of WordPress');

if (in_array('woocommerce/woocommerce.php',
  apply_filters('active_plugins', get_option('active_plugins')))) {

  if (!class_exists('WAI')) {
    /**
     * Main WAI's class
     */
    class WAI {
      private $activeTab;

      private $allegroUrl;
      private $allegroApiUrl;
      private $allegroAppsUrl;

      public function __construct() {
        // Register styles & scripts
        wp_register_style(
          'wai_style',
          plugins_url('wai-style.css', __FILE__)
        );

        wp_register_script(
          'wai_script',
          plugins_url('wai-script.js', __FILE__),
          array('jquery')
        );

        // Bind actions to methods
        add_action('admin_init', array($this, 'createSettings'));
        add_action('admin_menu', array($this, 'createMenu'));
        add_action('admin_enqueue_scripts', array($this, 'loadStylesScripts'));
        add_action('init', array($this, 'configureCronAndTokenRefreshing'));

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

        error_log(
          $message . PHP_EOL,
          3,
          LOGFILE
        );
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
       * This function converts strings to an ASCII string, generates
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
       * @return array Response and HTTP code
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
       * This function converts only hours, minutes and seconds into seconds
       * (not years, months etc.)
       *
       * @param DateTinterval $interval DateInterval object to convert
       * @return int The result of the conversion
       */
      private function dateIntervalToSeconds(DateInterval $interval): int {
        return $interval->h * 3600 + $interval->i * 60 + $interval->s;
      }

      /**
       * Function getting Allegro token
       */
      private function getToken(): void {
        define('DONT_SHOW_SETTINGS_ERROR', TRUE);
        $this->log(new DateTime(), __METHOD__, 'Started getting a token');
        $options = get_option('wai_options');

        if (empty($_GET['code'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'Auth code does not exist or is empty',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        if (!get_option('wai_code_verifier')) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'There was no saved code verifier',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        if (empty($_GET['state'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'Server has not returned the state',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        if (!get_option('wai_state')) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'There was no saved state',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        $state = get_option('wai_state');
        delete_option('wai_state');

        if ($_GET['state'] !== $state) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'State returned by server is invalid',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        if (empty($options['wai_allegro_id_field']) ||
            empty($options['wai_allegro_secret_field'])) {
          // It's not necessary to display an error badge: "Link to Allegro"
          // button is locked when ID or Secret is empty
          $this->log(
            new DateTime(),
            __METHOD__,
            'Client ID and/or Secret does not exist or is empty',
            'ERROR'
          );
          goto reload;
        }

        $code = $_GET['code'];
        $redirectUri = $this->getCleanUrl();
        $clientId = $options['wai_allegro_id_field'];
        $clientSecret = $options['wai_allegro_secret_field'];
        $encodedCredentials = base64_encode("$clientId:$clientSecret");

        $codeVerifier = get_option('wai_code_verifier');
        delete_option('wai_code_verifier');

        $url = "$this->allegroUrl/auth/oauth/token" .
          "?grant_type=authorization_code&code=$code" .
          "&code_verifier=$codeVerifier&redirect_uri=$redirectUri";

        $headers = array("Authorization: Basic $encodedCredentials");

        $res = $this->sendRequest($url, 'POST', $headers);

        if ($res['http_code'] !== 200 || !empty($res['error'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Something went wrong: http_code=\"{$res['http_code']}\" " .
              "error=\"{$res['error']}\"",
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not link to Allegro. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          goto reload;
        }

        $decodedRes = json_decode($res['response']);
        update_option('wai_token', $decodedRes->access_token);
        update_option('wai_refresh_token', $decodedRes->refresh_token);
        update_option('wai_token_expiry', array(
          'expires_in' => $decodedRes->expires_in,
          'current_datetime' => new DateTime()
        ));

        $this->log(
          new DateTime(),
          __METHOD__,
          'Token obtained successfully',
          'SUCCESS'
        );

        add_option('wai_delayed_settings_error', array(
          'setting' => 'wai',
          'code' => 'wai_error',
          'message' => 'Linked to Allegro successfully',
          'type' => 'success'
        ));

        reload:
        header("Location: {$this->getCleanUrl()}");
      }

      /**
       * Function refreshing the token
       */
      private function refreshToken(): void {
        $this->log(new DateTime(), __METHOD__, 'Started refreshing the token');
        $options = get_option('wai_options');

        if (empty(get_option('wai_refresh_token'))) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'Refresh token does not exist or is empty',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not refresh the token. Try to remove the app ' .
              'from linked apps in Allegro settings and link it again.',
            'type' => 'error'
          ));

          return;
        }

        if (empty($options['wai_allegro_id_field']) ||
            empty($options['wai_allegro_secret_field'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'Client ID and/or Secret does not exist or is empty',
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not refresh the token. Try to fill in ' .
              'Client ID and/or Secret field(s)',
            'type' => 'error'
          ));

          return;
        }

        $refreshToken = get_option('wai_refresh_token');
        $redirectUri = $this->getCleanUrl();
        $clientId = $options['wai_allegro_id_field'];
        $clientSecret = $options['wai_allegro_secret_field'];
        $encodedCredentials = base64_encode("$clientId:$clientSecret");

        $url = "$this->allegroUrl/auth/oauth/token" .
          "?grant_type=refresh_token&refresh_token=$refreshToken" .
          "&redirect_uri=$redirectUri";

        $headers = array("Authorization: Basic $encodedCredentials");

        $res = $this->sendRequest($url, 'POST', $headers);

        if ($res['http_code'] !== 200 || !empty($res['error'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Something went wrong: http_code=\"{$res['http_code']}\" " .
              "error=\"{$res['error']}\"",
            'ERROR'
          );

          add_option('wai_delayed_settings_error', array(
            'setting' => 'wai',
            'code' => 'wai_error',
            'message' => 'Could not refresh the token. ' .
              'See the logs for more information',
            'type' => 'error'
          ));

          return;
        }

        $decodedRes = json_decode($res['response']);
        update_option('wai_token', $decodedRes->access_token);
        update_option('wai_refresh_token', $decodedRes->refresh_token);
        update_option('wai_token_expiry', array(
          'expires_in' => $decodedRes->expires_in,
          'current_datetime' => new DateTime()
        ));

        $this->log(
          new DateTime(),
          __METHOD__,
          'Token refreshed successfully',
          'SUCCESS'
        );
      }

      /**
       * Function linking user's account to an Allegro application
       */
      private function linkToAllegro(): void {
        $this->log(new DateTime(), __METHOD__, 'Started linking to Allegro');
        $options = get_option('wai_options');

        if (empty($options['wai_allegro_id_field'])) {
          // It's not necessary to display an error badge: "Link to Allegro"
          // button is locked when ID or Secret is empty
          $this->log(
            new DateTime(),
            __METHOD__,
            'Client ID does not exist or is empty',
            'ERROR'
          );
          return;
        }

        $redirectUri = $this->getCleanUrl();
        $clientId = $options['wai_allegro_id_field'];
        $codeVerifier = $this->generateStringForPkce();
        $codeChallenge = $this->encodeStringForPkce($codeVerifier);
        $state = bin2hex(random_bytes(128 / 8));

        add_option('wai_code_verifier', $codeVerifier);
        add_option('wai_state', $state);

        $url = "$this->allegroUrl/auth/oauth/authorize" .
          "?response_type=code&client_id=$clientId&code_challenge_method=S256" .
          "&code_challenge=$codeChallenge&prompt=confirm&state=$state" .
          "&redirect_uri=$redirectUri";

        $this->log(
          new DateTime(),
          __METHOD__,
          'URL for linking to Allegro prepared successfully',
          'INFO'
        );

        header("Location: $url");
      }

      /**
       * Function changing the quantity of the product in Allegro
       *
       * This function gets the product from the Allegro API, changes its
       * quantity and updates this product
       *
       * @param string $id ID of the product to change the quantity for
       * @param int $quantity Target quantity of the product
       */
      private function changeQuantityAllegro(
        string $id,
        int $quantity
      ): void {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Started changing the quantity of product with ID \"$id\" ".
            "to \"$quantity\" in Allegro"
        );

        if (empty(get_option('wai_token'))) {
          $this->log(
            new DateTime(),
            __METHOD__,
            'Token does not exist or is empty',
            'ERROR'
          );

          return;
        }

        $url = "$this->allegroApiUrl/sale/offers/$id";

        $headers = array(
          'Accept: application/vnd.allegro.public.v1+json',
          'Authorization: Bearer ' . get_option('wai_token')
        );

        $res = $this->sendRequest($url, 'GET', $headers);

        if ($res['http_code'] === 404) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Product with ID \"$id\" not found",
            'ERROR'
          );

          return;
        } else if ($res['http_code'] !== 200 || !empty($res['error'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Something went wrong: http_code=\"{$res['http_code']}\" " .
              "error=\"{$res['error']}\"",
            'ERROR'
          );

          return;
        }

        $jsonRes = json_decode($res['response']);
        $jsonRes->stock->available = $quantity;

        $url = "$this->allegroApiUrl/sale/offers/$id";

        $headers = array(
          'Accept: application/vnd.allegro.public.v1+json',
          'Content-Type: application/vnd.allegro.public.v1+json',
          'Authorization: Bearer ' . get_option('wai_token')
        );

        $body = json_encode($jsonRes);

        $res = $this->sendRequest($url, 'PUT', $headers, $body);

        if ($res['http_code'] === 404) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Product with ID \"$id\" not found",
            'ERROR'
          );

          return;
        } else if ($res['http_code'] !== 200 || !empty($res['error'])) {
          $this->log(
            new DateTime(),
            __METHOD__,
            "Something went wrong: http_code=\"{$res['http_code']}\" " .
              "error=\"{$res['error']}\"",
            'ERROR'
          );

          return;
        }

        $this->log(
          new DateTime(),
          __METHOD__,
          'Quantity changed successfully',
          'SUCCESS'
        );
      }

      /**
       * Function configuring the cron, refreshing the token and doing many
       * other tasks
       */
      public function configureCronAndTokenRefreshing(): void {
        $option = get_option('wai_token_expiry');

        if (!empty($option)) {
          $difference = $option['current_datetime']->diff(new DateTime());
          $difference = $this->dateIntervalToSeconds($difference);

          if ($difference >= $option['expires_in']) {
            $this->refreshToken();
            delete_option('wai_token_expiry');
          }
        }

        if (!get_option('wai_token')) {
          add_option('wai_token');
        }
        if (!get_option('wai_refresh_token')) {
          add_option('wai_refresh_token');
        }
        if (!get_option('wai_token_expires_in')) {
          add_option('wai_token_expires_in');
        }
      }

      /**
       * Function creating plugin's settings
       */
      public function createSettings(): void {
        if (isset($_GET['code']))
          $this->getToken();

        if (isset($_GET['action'])) {
          $refresh = TRUE;

          switch ($_GET['action']) {
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
            !defined('DONT_SHOW_SETTINGS_ERROR') &&
            $_SERVER['REQUEST_URI'] === '/wp-admin/admin.php' &&
            $_GET['page'] === 'wai') {
          $option = get_option('wai_delayed_settings_error');

          add_settings_error(
            $option['setting'],
            $option['code'],
            $option['message'],
            $option['type']
          );

          delete_option('wai_delayed_settings_error');
        }

        register_setting('wai', 'wai_options');

        add_settings_section(
          'wai_allegro',
          'Allegro API settings',
          array($this, 'displayAllegroSection'),
          'wai'
        );

        add_settings_section(
          'wai_bindings',
          'Bindings',
          array($this, 'displayBindingsSection'),
          'wai'
        );

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
        <p>Go to <a href="<?php echo $this->allegroAppsUrl; ?>" target="_blank">apps.developer.allegro.pl</a> and create new web app. In "Redirect URI" type <code><?php echo $this->getCleanUrl(); ?></code>. Then copy Client ID & Secret and paste them here.</p>
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
        $value = $options['wai_allegro_id_field'];
        ?>
        <input type="text" class="wai-input" name="wai_options[wai_allegro_id_field]" value="<?php echo $value; ?>">
        <?php
      }

      /**
       * Function displaying secret field in Allegro section
       */
      public function displayAllegroSecretField(): void {
        $options = get_option('wai_options');
        $value = $options['wai_allegro_secret_field'];
        ?>
        <input id="wai-allegro-secret" type="password" class="wai-input" name="wai_options[wai_allegro_secret_field]" value="<?php echo $value; ?>">
        <label for="wai-allegro-secret-toggle-visibility">Toggle visbility</label>
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
              <th>WooCommerce Product ID</th>
              <th>Allegro Product ID</th>
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
            <a href="?page=wai&tab=settings" class="nav-tab <?php echo empty($this->activeTab) || $this->activeTab === 'settings' ? 'nav-tab-active' : '';?>">Settings</a>
            <a href="?page=wai&tab=logs" class="nav-tab <?php echo $this->activeTab === 'logs' ? 'nav-tab-active' : '';?>">Logs</a>
          </h2>
          <?php
          // Check which tab is active now
          switch ($this->activeTab) {
            default:
            case 'settings':
          ?>
          <form action="options.php" method="post" id="wai-form">
          <?php
          settings_fields('wai');
          do_settings_sections('wai');
          ?>
            <p>
              <button id="wai-submit" class="button button-primary">Save settings</button>
          <?php
          if (empty($options['wai_allegro_id_field']) ||
              empty($options['wai_allegro_secret_field'])) {
            $btnDisabled = TRUE;
          } else {
            $btnDisabled = FALSE;
          }
          ?>
              <button id="wai-link-allegro" class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>>Link to Allegro</button>
            </p>
            <p>
              <button class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>>Sync WooCommerce -> Allegro</button>
            </p>
            <p>
              <button class="button button-secondary" <?php echo $btnDisabled ? 'disabled' : '' ?>>Sync Allegro -> WooCommerce</button>
            </p>
          </form>
          <?php
              break;
            case 'logs':
          ?>
          <h2>Logs</h2>
          <p>Debug info</p>
          <textarea id="wai-logs" rows="10" readonly><?php echo @file_get_contents(LOGFILE); ?></textarea>
          <a href="<?php echo LOGFILE; ?>" class="button button-primary" download>Download log file</a>
          <button class="button button-secondary" id="wai-clean-log">Clean log file</button>
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
        wp_enqueue_style('wai_style');
        wp_enqueue_script('wai_script');
      }
    }

    new WAI();
  }
}
