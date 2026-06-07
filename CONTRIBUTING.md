# Współpraca

Dziękuję za zainteresowanie rozwojem Ligase! Poniżej znajdziesz wskazówki jak wnieść swój wkład.

## Zgłaszanie błędów

1. Sprawdź czy błąd nie został już zgłoszony w [Issues](../../issues)
2. Utwórz nowy issue z opisem:
   - Wersja WordPress i PHP
   - Kroki do odtworzenia błędu
   - Oczekiwane vs rzeczywiste zachowanie
   - Logi z `wp-content/uploads/ligase-logs/` (jeśli dostępne)

## Pull Requesty

1. Forkuj repozytorium
2. Utwórz branch z opisową nazwą: `feature/nowa-funkcja` lub `fix/opis-bledu`
3. Pisz kod zgodny z [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
4. Dodaj testy jednostkowe dla nowej funkcjonalności
5. Upewnij się że testy przechodzą: `./vendor/bin/phpunit`
6. Utwórz Pull Request z opisem zmian

## Standardy kodu

- PHP 8.0+ (typed properties, match expression, constructor promotion)
- WordPress Coding Standards (WPCS)
- Prefix `ligase_` lub `Ligase_` dla wszystkich funkcji, klas, hooków
- Sanityzacja: `sanitize_text_field()`, `esc_url_raw()`, `absint()`
- Escape: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- Nonce verification na wszystkich formularzach i AJAX
- Capability checks (`current_user_can()`)

## Struktura commitów

```
typ: krótki opis

Dłuższy opis jeśli potrzebny.
```

Typy: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`

## Uruchamianie testów

```bash
composer install
./vendor/bin/phpunit
```

## Licencja

Wnosząc zmiany, zgadzasz się na ich licencjonowanie na GPL v2 lub późniejszej.
