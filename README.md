# payline-hoppa-bin-lookup

[![Tests](https://github.com/x-laravel/payline-hoppa-bin-lookup/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-hoppa-bin-lookup/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Hoppa BIN lookup provider for [x-laravel/payline](https://github.com/x-laravel/payline).

Hoppa answers the card family and card type behind the first eight digits of a card
number. Payline uses that answer to pick the cheapest gateway for the card in hand, so
the service is worth having whether or not you charge through Hoppa.

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline ^1.0

The Hoppa gateway package is not required. This package talks to the BIN service alone.

## Installation

```bash
composer require x-laravel/payline-hoppa-bin-lookup
```

## Configuration

Name `hoppa` as the BIN lookup driver in `config/payline.php`:

```php
'bin_lookup' => [
    'providers' => ['hoppa'],
    'drivers' => [],
],
```

The service takes no credentials.

Hoppa names the card family but no country of issuance. List a provider that reports one
after it when a routing policy needs to know where a card comes from, and Payline merges
the two answers:

```php
'providers' => ['hoppa', 'handyapi'],
```

## Test and Live

The provider ships both Hoppa addresses and picks between them with `payline.test_mode`:

| `test_mode` | Host |
|-------------|------|
| `true` | `https://posservicetest.esnekpos.com` |
| `false` | `https://posservice.esnekpos.com` |

A `base_url` under `bin_lookup.drivers.hoppa` wins over both:

```php
'bin_lookup' => [
    'providers' => ['hoppa'],
    'drivers' => [
        'hoppa' => [
            'base_url' => 'https://posservice.esnekpos.com',
        ],
    ],
],
```

## Caching

A BIN belongs to a card family and a card type for as long as the range exists, so a
resolved profile is cached for 30 days. An answer that resolves nothing is not cached,
which lets a newly issued range work the next time it is asked about.

What is stored is Hoppa's own payload, not the profile built from it. The profile is
rebuilt on every read, so a correction to this mapping or a new field on `CardProfile`
takes effect immediately instead of waiting a month for the cache to turn over.

| Key | Default | Meaning |
|-----|---------|---------|
| `cache_ttl` | `2592000` | Seconds a resolved profile is kept; `0` turns caching off |
| `cache_store` | `null` | Cache store name; the application default when absent |
| `timeout` | `5` | Seconds to wait for an answer |

```php
'bin_lookup' => [
    'providers' => ['hoppa'],
    'drivers' => [
        'hoppa' => [
            'cache_ttl' => 86400,
            'cache_store' => 'redis',
        ],
    ],
],
```

Entries are keyed `payline:bin-lookup:hoppa:<bin>`.

## Usage

Payline calls the provider on its own while routing a payment. To ask directly:

```php
use XLaravel\Payline\BinLookupManager;

$profile = app(BinLookupManager::class)->lookup('5101520012345678');

$profile?->family;   // 'bonus'
$profile?->type;     // CardType::Credit
$profile?->scheme;   // CardScheme::Mastercard
$profile?->issuer;   // 'IS BANK'
$profile?->category; // CardCategory::Consumer
```

`lookup()` takes a full card number or just its first eight digits, and sends only the
first eight. It returns `null` when Hoppa reports neither a family nor a card type for
the BIN, which leaves commission routing to fall back on the default gateway.

The family is lowercased and trimmed, which is the same shape the Hoppa commission rate
service reports, so `payline_commission_rates.card_family` matches either source.

## What Hoppa Fills

| `CardProfile` | Hoppa | Note |
|---------------|-------|------|
| `bin` | the queried digits | |
| `scheme` | `Bank_Brand` | |
| `type` | `Card_Type` | |
| `category` | `Card_Kind` | `TİCARİ` is commercial, `BİREYSEL` is consumer; often absent |
| `family` | `Card_Family` | |
| `issuer` | `Bank_Name` | |
| `issuerCode` | `Bank_Code` | the bank's EFT code, such as `64` |
| `source` | always `hoppa` | |
| `raw` | the whole payload | |

`issuerCountry`, `localSchemes`, `productId`, `productType`, `currency`, `prepaid` and
`numberLength` stay null. Hoppa reports no country of issuance, and naming one here
would be a guess: the service answers for some ranges that other BIN databases place
outside Turkey. A co-badged card comes back under one brand only.

A BIN Hoppa does not recognise is answered with every field present and blank rather
than with an empty body, which is why the provider decides on the card family and type
instead of on the presence of a key.

A `Card` resolves its own profile:

```php
use XLaravel\Payline\BinLookupManager;
use XLaravel\Payline\DTOs\Card;

$card = (new Card(
    holderName: 'John Doe',
    number: '4111111111111111',
    expiryMonth: '12',
    expiryYear: '2030',
    cvv: '123',
))->resolveProfile(app(BinLookupManager::class));
```

A `PaymentRequest` carrying a card whose profile is resolved lets `GatewayRouter` choose
the gateway with the cheapest commission for that family and installment count:

```php
$data = PaymentRequest::fromPayable(
    payable: $order,
    card: $card,
    installments: 3,
);

$order->pay()->charge($data);
```

## Testing

```bash
composer test
```

```bash
docker compose --profile php84 up --build
```

## License

MIT
