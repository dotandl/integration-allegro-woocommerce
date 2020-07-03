# WooCommerce & Allegro Integration
### Wtyczka do WordPressa synchronizująca dostępność produktów między WooCommerce a Allegro

## Instalacja wtyczki
Po prostu sklonuj to repozytorium do folderu `wp-content/plugins/woocommerce-allegro-integration` i aktywuj wtyczkę z panelu WordPressa.

## Łączenie z Allegro
Przejdź do [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) i utwórz nową aplikację. Wpisz jej nazwę, opcjonalnie opis i zaznacz, że *aplikacja będzie posiadać dostęp do przeglądarki*. Następnie, w *Adresu URI do przekierowania* wpisz adres widoczny w panelu WooCommerce & Allegro Integration (np. `http[s]://twoja-strona//wp-admin/tools.php?page=wai`) i naciśnij *Dodaj*. Później skopiuj *Client ID* i *Client Secret*, wklej je w panelu i *Zapisz ustawienia*. Ostatnim krok to kliknięcie *Połącz z Allegro*.

## Używanie wtyczki
Aby powązać produkty z WooCommerce i Allegro musisz posiadać ich ID. W panelu, niżej *Wiązania*, kliknij ikonę `+` i wpisz ID produktów do odpowiednich pól. Następnie *Zapisz ustawienia*. Jeśli chcech możesz zsynchronizować ilości produktów klikając *Synchronizuj WooCommerce -> Allegro* lub *Synchronizuj Allegro -> WooCommerce*.

## Znane problemy
Jeśli zauważyłeś jakieś bugi albo chcesz ulepszyć tą wtyczkę, śmiało otwórz nowy GitHub Isuue lub PR. Dzięki wszystkim za pomoc! :)

- Token Allegro API jest dwukrotnie odświeżany - drugie odświeżenie powoduje błąd `HTTP 400`

## Licencja
GNU GPL v2 (zobacz [LICENSE](LICENSE))
