# Integration of Allegro and WooCommerce

### Wordpress plugin that syncs products' availability between WooCommerce and Allegro

[README in Polish](README.pl-PL.md)

## Installing the plugin

The plugin is available on [wordpress.org](https://wordpress.org/plugins/integration-allegro-woocommerce) - you can find it in the WordPress panel in the _Plugins_ menu or download the `zip` file from the aforementioned site and install it in the _Plugins_ menu too. You can also just clone this Git repository into `wp-content/plugins/integration-allegro-woocommerce` directory and enable the plugin from the WordPress panel.

## Connecting to Allegro

Go to [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) and create new application. Type the name, optional description and select that _the app will have access to web browser_. Then, in *redirect URIs*type the address shown in the Integration of Allegro and WooCommerce's panel (like `http[s]://your-site/wp-admin/tools.php?page=waint`) and click _add_. Next copy the _Client ID_ and _Client Secret_, paste them in the panel and _Save settings_. The last step is to click _Link to Allegro_.

## Using the plugin

To bind products from WooCommerce and Allegro you must have their IDs. In the panel, under _Bindings_, click `+` icon and type products' IDs into corresponding fields. Then _Save settings_. If you want, you can sync products' quantity by clicking _Sync WooCommerce -> Allegro_ or _Sync Allegro -> WooCommerce_.

## Contribution

If you have seen some bugs or you'd like to improve this plugin, feel free to open new GitHub Issues or PRs. Thank you all for your help! :)

## License

GNU GPLv2 (see [LICENSE](LICENSE))
