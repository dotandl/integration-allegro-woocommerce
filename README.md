# Integration of Allegro and WooCommerce
### Wordpress plugin that syncs products' availability between WooCommerce and Allegro

[README in Polish](README.pl-PL.md)

## Installing the plugin
Just clone this Git repository into `wp-content/plugins/integration-allegro-woocommerce` directory and enable the plugin from the WordPress panel.

## Connecting to Allegro
Go to [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) and create new application. Type the name, optional description and select that *the app will have access to web browser*. Then, in *redirect URIs*type the address shown in the Integration of Allegro and WooCommerce's panel (like `http[s]://your-site//wp-admin/tools.php?page=waint`) and click *add*. Next copy the *Client ID* and *Client Secret*, paste them in the panel and *Save settings*. The last step is to click *Link to Allegro*.

## Using the plugin
To bind products from WooCommerce and Allegro you must have their IDs. In the panel, under *Bindings*, click `+` icon and type products' IDs into corresponding fields. Then *Save settings*. If you want, you can sync products' quantity by clicking *Sync WooCommerce -> Allegro* or *Sync Allegro -> WooCommerce*.

## Known issues
If you have seen some bugs or you'd like to improve this plugin, feel free to open new GitHub Issues or PRs. Thank you all for your help! :)

- Allegro API token refreshes twice - the second refresh makes `HTTP 400` error

## License
GNU GPL v2 (see [LICENSE](LICENSE))
