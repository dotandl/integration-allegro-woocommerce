<?php
declare(strict_types = 1);

defined('ABSPATH') or die('Error: Plugin has been run outside of WordPress');

if (in_array('woocommerce/woocommerce.php',
  apply_filters('active_plugins', get_option('active_plugins')))) {
  /*
    * Trait syncing products. It belongs to the WAInt_Main class
    */
  trait WAInt_Sync {
    /**
     * Function getting Allegro token
     */
    private function getToken(): void {
      define('WAINT_DONT_SHOW_SETTINGS_ERROR', TRUE);
      $this->log(new DateTime(), __METHOD__, 'Started getting a token');
      $options = get_option('waint_options');

      if (empty($_GET['code'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Auth code does not exist or is empty',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        goto reload;
      }

      if (!get_option('waint_code_verifier')) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'There was no saved code verifier',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
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

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        goto reload;
      }

      if (!get_option('waint_state')) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'There was no saved state',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        goto reload;
      }

      $state = get_option('waint_state');
      delete_option('waint_state');

      if ($_GET['state'] !== $state) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'State returned by server is invalid',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        goto reload;
      }

      if (empty($options['waint_allegro_id_field']) ||
          empty($options['waint_allegro_secret_field'])) {
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

      $code = sanitize_text_field($_GET['code']);
      $redirectUri = $this->getCleanUrl();
      $clientId = sanitize_text_field($options['waint_allegro_id_field']);
      $clientSecret =
        sanitize_text_field($options['waint_allegro_secret_field']);
      $encodedCredentials = base64_encode("$clientId:$clientSecret");

      $codeVerifier = get_option('waint_code_verifier');
      delete_option('waint_code_verifier');

      $url = "$this->allegroUrl/auth/oauth/token" .
        "?grant_type=authorization_code&code=$code" .
        "&code_verifier=$codeVerifier&redirect_uri=$redirectUri";

      $headers = array('Authorization' => "Basic $encodedCredentials");

      $res = $this->sendRequest($url, 'POST', $headers);

      if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['http_code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not link to Allegro. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        goto reload;
      }

      $decodedRes = json_decode($res['response']);
      update_option('waint_token', $decodedRes->access_token);
      update_option('waint_refresh_token', $decodedRes->refresh_token);
      update_option('waint_token_expiry', array(
        'expires_in' => $decodedRes->expires_in,
        'current_datetime' => new DateTime()
      ));

      $this->log(
        new DateTime(),
        __METHOD__,
        'Token obtained successfully',
        'SUCCESS'
      );

      add_option('waint_delayed_settings_error', array(
        'setting' => 'waint',
        'code' => 'waint_error',
        'message' => esc_html__('Linked to Allegro successfully', 'waint'),
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
      $options = get_option('waint_options');

      if (empty(get_option('waint_refresh_token'))) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Refresh token does not exist or is empty',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not refresh the token. Try to ' .
            'remove the app from linked apps in Allegro settings and ' .
            'link it again.', 'waint'),
          'type' => 'error'
        ));

        return;
      }

      if (empty($options['waint_allegro_id_field']) ||
          empty($options['waint_allegro_secret_field'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Client ID and/or Secret does not exist or is empty',
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not refresh the token. Try to ' .
            'fill in Client ID and/or Secret field(s)', 'waint'),
          'type' => 'error'
        ));

        return;
      }

      $refreshToken = get_option('waint_refresh_token');
      $redirectUri = $this->getCleanUrl();
      $clientId = sanitize_text_field($options['waint_allegro_id_field']);
      $clientSecret =
        sanitize_text_field($options['waint_allegro_secret_field']);
      $encodedCredentials = base64_encode("$clientId:$clientSecret");

      $url = "$this->allegroUrl/auth/oauth/token" .
        "?grant_type=refresh_token&refresh_token=$refreshToken" .
        "&redirect_uri=$redirectUri";

      $headers = array('Authorization' => "Basic $encodedCredentials");

      $res = $this->sendRequest($url, 'POST', $headers);

      if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['http_code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        add_option('waint_delayed_settings_error', array(
          'setting' => 'waint',
          'code' => 'waint_error',
          'message' => esc_html__('Could not refresh the token. ' .
            'See the logs for more information', 'waint'),
          'type' => 'error'
        ));

        return;
      }

      $decodedRes = json_decode($res['response']);
      update_option('waint_token', $decodedRes->access_token);
      update_option('waint_refresh_token', $decodedRes->refresh_token);
      update_option('waint_token_expiry', array(
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
      $options = get_option('waint_options');

      if (empty($options['waint_allegro_id_field'])) {
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
      $clientId = sanitize_text_field($options['waint_allegro_id_field']);
      $codeVerifier = $this->generateStringForPkce();
      $codeChallenge = $this->encodeStringForPkce($codeVerifier);
      $state = bin2hex(random_bytes(128 / 8));

      add_option('waint_code_verifier', $codeVerifier);
      add_option('waint_state', $state);

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
    ): bool {
      $this->log(
        new DateTime(),
        __METHOD__,
        "Started changing the quantity of product with ID \"$id\" ".
          "to \"$quantity\" in Allegro"
      );

      if (empty(get_option('waint_token'))) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Token does not exist or is empty',
          'ERROR'
        );

        return FALSE;
      }

      $url = "$this->allegroApiUrl/sale/offers/$id";

      $headers = array(
        'Accept' => 'application/vnd.allegro.public.v1+json',
        'Authorization' => 'Bearer ' . get_option('waint_token')
      );

      $res = $this->sendRequest($url, 'GET', $headers);

      if ($res['http_code'] === 404) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Product with ID \"$id\" not found",
          'ERROR'
        );

        return FALSE;
      } else if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['http_code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        return FALSE;
      }

      $jsonRes = json_decode($res['response']);
      $jsonRes->stock->available = $quantity;

      $url = "$this->allegroApiUrl/sale/offers/$id";

      $headers = array(
        'Accept' => 'application/vnd.allegro.public.v1+json',
        'Content-Type' => 'application/vnd.allegro.public.v1+json',
        'Authorization' => 'Bearer ' . get_option('waint_token')
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

        return FALSE;
      } else if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['http_code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        return FALSE;
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'Quantity changed successfully',
        'SUCCESS'
      );

      return TRUE;
    }

    /**
     * Function changing the quantity of the product in WooCommerce
     *
     * This function gets the product from WooCommerce, changes its
     * quantity and updates this product
     *
     * @param string $id ID of the product to change the quantity for
     * @param int $quantity Target quantity of the product
     */
    private function changeQuantityWooCommerce(int $id, int $quantity): bool {
      $this->log(
        new DateTime(),
        __METHOD__,
        "Started changing the quantity of product with ID \"$id\" ".
          "to \"$quantity\" in WooCommerce"
      );

      $product = wc_get_product($id);

      if ($product === NULL) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Product with ID \"$id\" not found",
          'ERROR'
        );

        return FALSE;
      }

      $product->set_stock_quantity($quantity);
      $product->save();

      $this->log(
        new DateTime(),
        __METHOD__,
        "Quantity changed successfully",
        'SUCCESS'
      );

      return TRUE;
    }

    /**
     * Function syncing product's quantity from WooCommerce to Allegro
     *
     * @param array $binding Binding between products in WooCommerce
     *  and Allegro
     */
    private function syncWooCommerceAllegro(array $binding): bool {
      $this->log(new DateTime(), __METHOD__, 'Started syncing');

      // get_post_status - check if product exists
      if (!get_post_status($binding[0])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Product with ID \"{$binding[0]}\" not found in WooCommerce",
          'ERROR'
        );

        return FALSE;
      }

      $product = wc_get_product($binding[0]);
      $res = $this->changeQuantityAllegro(
        $binding[1],
        $product->get_stock_quantity()
      );

      if (!$res) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Could not change quantity of product with ID \"{$binding[1]}\" " .
            'in Allegro',
          'ERROR'
        );

        return FALSE;
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'Products synced successfully',
        'SUCCESS'
      );

      return TRUE;
    }

    /**
     * Function syncing product's quantity from Allegro to WooCommerce
     *
     * @param array $binding Binding between products in WooCommerce
     *  and Allegro
     */
    private function syncAllegroWooCommerce(array $binding): bool {
      $this->log(new DateTime(), __METHOD__, 'Started syncing');

      if (empty(get_option('waint_token'))) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Token does not exist or is empty',
          'ERROR'
        );

        return FALSE;
      }

      $options = get_option('waint_options');

      $url = "$this->allegroApiUrl/sale/offers/{$binding[1]}";
      $headers = array(
        'Authorization' => 'Bearer ' . get_option('waint_token'),
        'Accept' => 'application/vnd.allegro.public.v1+json'
      );

      $res = $this->sendRequest($url, 'GET', $headers);

      if ($res['http_code'] === 404) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Product with ID \"{$binding[1]}\" not found in Allegro",
          'ERROR'
        );

        return FALSE;
      } else if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        return FALSE;
      }

      $res = $this->changeQuantityWooCommerce(
        $binding[0], json_decode($res['response'])->stock->available);

      if (!$res) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Could not change quantity of product with ID \"{$binding[0]}\"" .
            'in WooCommerce',
          'ERROR'
        );

        return FALSE;
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'Products synced successfully',
        'SUCCESS'
      );

      return TRUE;
    }

    /**
     * Function syncing all products' quantity from WooCommerce to Allegro
     */
    private function syncAllWooCommerceAllegro(): void {
      define('WAINT_DONT_SHOW_SETTINGS_ERROR', TRUE);

      $this->log(new DateTime(), __METHOD__, 'Started syncing all products');
      $options = get_option('waint_options');

      if (!empty($options['waint_bindings_field'])) {
        foreach (
          json_decode(sanitize_text_field($options['waint_bindings_field']))
          as $binding) {
          $res = $this->syncWooCommerceAllegro($binding);

          if (!$res) {
            $this->log(
              new DateTime(),
              __METHOD__,
              "Could not sync products with IDs \"{$binding[0]}\" " .
                "(WooCommerce) and \"{$binding[1]}\" (Allegro)",
              'ERROR'
            );

            add_option('waint_delayed_settings_error', array(
              'setting' => 'waint',
              'code' => 'waint_error',
              'message' => esc_html__('Could not sync products. See the ' .
                'logs for more information', 'waint'),
              'type' => 'error'
            ));

            return;
          }
        }
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'All products synced successfully',
        'SUCCESS'
      );

      add_option('waint_delayed_settings_error', array(
        'setting' => 'waint',
        'code' => 'waint_error',
        'message' => esc_html__('Products synced successfully', 'waint'),
        'type' => 'success'
      ));
    }

    /**
     * Function syncing all products' quantity from Allegro to WooCommerce
     */
    private function syncAllAllegroWooCommerce(): void {
      define('WAINT_DONT_SHOW_SETTINGS_ERROR', TRUE);

      $this->log(new DateTime(), __METHOD__, 'Started syncing all products');
      $options = get_option('waint_options');

      if (!empty($options['waint_bindings_field'])) {
        foreach (
          json_decode(sanitize_text_field($options['waint_bindings_field']))
          as $binding) {
          $res = $this->syncAllegroWooCommerce($binding);

          if (!$res) {
            $this->log(
              new DateTime(),
              __METHOD__,
              "Could not sync products with IDs \"{$binding[0]}\" " .
                "(WooCommerce) and \"{$binding[1]}\" (Allegro)",
              'ERROR'
            );

            add_option('waint_delayed_settings_error', array(
              'setting' => 'waint',
              'code' => 'waint_error',
              'message' => esc_html__('Could not sync products. See the logs ' .
                'for more information', 'waint'),
              'type' => 'error'
            ));

            return;
          }
        }
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'All products synced successfully',
        'SUCCESS'
      );

      add_option('waint_delayed_settings_error', array(
        'setting' => 'waint',
        'code' => 'waint_error',
        'message' => esc_html__('Products synced successfully', 'waint'),
        'type' => 'success'
      ));
    }

    /**
     * Function processing new order in WooCommerce (syncing quantity from
     *  WooCommerce to Allegro for ordered products)
     *
     * @param int $id Order ID
     */
    public function hookNewOrderWooCommerce(int $id): void {
      $this->log(
        new DateTime(),
        __METHOD__,
        'Started processing new order in WooCommerce'
      );

      $order = wc_get_order($id);
      $options = get_option('waint_options');

      if (empty($order)) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Order with ID \"$id\" not found",
          'ERROR'
        );

        return;
      }
      // It isn't necessary to check if array with bindings is empty

      foreach (
        json_decode(sanitize_text_field($options['waint_bindings_field']))
        as $binding) {
        foreach ($order->get_items() as $item) {
          if ($item['product_id'] === $binding[0]) {
            $res = $this->syncWooCommerceAllegro($binding);

            if (!$res) {
              $this->log(
                new DateTime(),
                __METHOD__,
                "Could not sync products with IDs \"{$binding[0]}\" " .
                  "(WooCommerce) and \"{$binding[1]}\" (Allegro)",
                'ERROR'
              );

              return;
            }
          }
        }
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'Order processed successfully',
        'SUCCESS'
      );
    }

    private function processNewOrdersAllegro(): void {
      $this->log(
        new DateTime(),
        __METHOD__,
        'Started processing new orders in Allegro'
      );

      if (empty(get_option('waint_token'))) {
        $this->log(
          new DateTime(),
          __METHOD__,
          'Token does not exist or is empty',
          'ERROR'
        );

        return;
      }

      $url = "$this->allegroApiUrl/order/events";
      $headers = array(
        'Accept' => 'application/vnd.allegro.public.v1+json',
        'Authorization' => 'Bearer ' . get_option('waint_token')
      );

      $res = $this->sendRequest($url, 'GET', $headers);

      if ($res['http_code'] !== 200 || !empty($res['error'])) {
        $this->log(
          new DateTime(),
          __METHOD__,
          "Something went wrong: http_code=\"{$res['http_code']}\" " .
            "error=\"{$res['error']}\"",
          'ERROR'
        );

        return;
      }

      $options = get_option('waint_options');
      $lastProcessed = get_option('waint_last_allegro_orders_processed');
      $obj = json_decode($res['response']);

      update_option('waint_last_allegro_orders_processed', new DateTime());
      if (empty($lastProcessed))
        $forceSync = TRUE;

      foreach (
        json_decode(sanitize_text_field($options['waint_bindings_field']))
        as $binding) {
        foreach ($obj->events as $event) {
          if ($forceSync ??
              new DateTime($event->occurredAt) >= $lastProcessed) {
            foreach ($event->order->lineItems as $item) {
              if ($binding[1] === $item->offer->id) {
                $res = $this->syncAllegroWooCommerce($binding);

                if (!$res) {
                  $this->log(
                    new DateTime(),
                    __METHOD__,
                    "Could not sync products with IDs \"{$binding[0]}\" " .
                      "(WooCommerce) and \"{$binding[1]}\" (Allegro)",
                    'ERROR'
                  );

                  return;
                }
              }
            }
          }
        }
      }

      $this->log(
        new DateTime(),
        __METHOD__,
        'Orders processed successfully',
        'SUCCESS'
      );
    }
  }
}
