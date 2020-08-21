# Integracja Allegro i WooCommerce

### Wtyczka do WordPressa synchronizująca dostępność produktów między WooCommerce a Allegro

## Instalacja wtyczki

Wtyczka jest dostępna na [wordpress.org](https://wordpress.org/plugins/integration-allegro-woocommerce) - możesz ją wyszukać w panelu Wordpressa w menu _Wtyczki_ lub pobrać plik `zip` z powyższej strony i zainstalować również w menu _Wtyczki_. Możesz też po prostu sklonować to repozytorium do folderu `wp-content/plugins/integration-allegro-woocommerce` i aktywować wtyczkę z panelu WordPressa.

## Łączenie z Allegro

Przejdź do [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) i utwórz nową aplikację. Wpisz jej nazwę, opcjonalnie opis i zaznacz, że _aplikacja będzie posiadać dostęp do przeglądarki_. Następnie, w _Adresu URI do przekierowania_ wpisz adres widoczny w panelu Integracji WooCommerce i Allegro (np. `http[s]://twoja-strona/wp-admin/tools.php?page=waint`) i naciśnij _Dodaj_. Później skopiuj _Client ID_ i _Client Secret_, wklej je w panelu i _Zapisz ustawienia_. Ostatni krok to kliknięcie _Połącz z Allegro_.

## Używanie wtyczki

Aby powązać produkty z WooCommerce i Allegro musisz posiadać ich ID. W panelu, niżej _Wiązania_, kliknij ikonę `+` i wpisz ID produktów do odpowiednich pól. Następnie _Zapisz ustawienia_. Jeśli chcech możesz zsynchronizować ilości produktów klikając _Synchronizuj WooCommerce -> Allegro_ lub _Synchronizuj Allegro -> WooCommerce_.

## Kontrybucja

Jeśli zauważyłeś jakieś bugi albo chcesz ulepszyć tą wtyczkę, śmiało otwórz nowy GitHub Issue lub PR. Dzięki wszystkim za pomoc! :)

## Licencja

GNU GPLv2 (zobacz [LICENSE](LICENSE))
