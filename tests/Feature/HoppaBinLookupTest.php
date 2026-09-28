<?php

namespace XLaravel\Payline\BinLookup\Hoppa\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\BinLookup\Hoppa\HoppaBinLookup;
use XLaravel\Payline\BinLookup\Hoppa\Tests\TestCase;
use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class HoppaBinLookupTest extends TestCase
{
    private HoppaBinLookup $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new HoppaBinLookup(['test_mode' => true]);
    }

    public function test_lookup_returns_card_profile_for_credit_card(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Name' => 'IS BANK',
                'Bank_Brand' => 'MASTERCARD',
                'Card_Type' => 'CREDIT',
                'Card_Family' => 'Maximum',
                'Card_Kind' => 'BİREYSEL KART',
            ]),
        ]);

        $profile = $this->provider->lookup('51015200');

        $this->assertNotNull($profile);
        $this->assertSame('maximum', $profile->family);
        $this->assertSame(CardType::Credit, $profile->type);
    }

    public function test_lookup_returns_debit_card_type_for_debit(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Name' => 'YAPI KREDİ',
                'Bank_Brand' => 'VISA',
                'Card_Type' => 'DEBIT',
                'Card_Family' => 'Paraf',
                'Card_Kind' => 'DEBİT KART',
            ]),
        ]);

        $profile = $this->provider->lookup('45218200');

        $this->assertNotNull($profile);
        $this->assertSame('paraf', $profile->family);
        $this->assertSame(CardType::Debit, $profile->type);
    }

    public function test_lookup_normalises_the_family_the_rate_service_also_reports(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'CREDIT',
                'Card_Family' => '  Bonus ',
            ]),
        ]);

        $this->assertSame('bonus', $this->provider->lookup('51015200')->family);
    }

    public function test_lookup_sends_first_8_digits(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'CREDIT',
                'Card_Family' => 'Axess',
            ]),
        ]);

        $this->provider->lookup('4521829912345678');

        Http::assertSent(fn ($r) => $r->data()['CardNumber'] === '45218299');
    }

    public function test_lookup_returns_null_when_hoppa_knows_nothing(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([]),
        ]);

        $this->assertNull($this->provider->lookup('51015200'));
    }

    public function test_a_bin_hoppa_does_not_know_answers_with_blank_fields_and_resolves_nothing(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Code' => '',
                'Bank_Name' => '',
                'Bank_Brand' => '',
                'Card_Type' => '',
                'Card_Family' => '',
                'Card_Kind' => '',
            ]),
        ]);

        $this->assertNull($this->provider->lookup('45717360'));
    }

    public function test_lookup_returns_null_on_empty_response(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response(null),
        ]);

        $this->assertNull($this->provider->lookup('51015200'));
    }

    public function test_test_mode_reaches_the_test_host(): void
    {
        $this->fakeBin();

        $this->provider->lookup('51015200');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://posservicetest.esnekpos.com/'));
    }

    public function test_the_live_host_is_used_without_test_mode(): void
    {
        $this->fakeBin();

        (new HoppaBinLookup())->lookup('51015200');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://posservice.esnekpos.com/'));
    }

    public function test_a_configured_base_url_wins_over_both_hosts(): void
    {
        $this->fakeBin();

        (new HoppaBinLookup(['test_mode' => true, 'base_url' => 'https://elsewhere.test']))->lookup('51015200');

        Http::assertSent(fn ($r) => $r->url() === 'https://elsewhere.test/api/services/EYVBinService');
    }

    public function test_the_manager_resolves_the_provider_by_name(): void
    {
        $this->assertInstanceOf(HoppaBinLookup::class, $this->app->make('payline.bin_lookup')->driver('hoppa'));
    }

    public function test_the_registered_provider_follows_the_payline_test_mode(): void
    {
        $this->fakeBin();

        $profile = $this->app->make('payline.bin_lookup')->lookup('5101520012345678');

        $this->assertSame('bonus', $profile->family);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://posservicetest.esnekpos.com/'));
    }

    public function test_lookup_fills_every_field_hoppa_reports(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Code' => '64',
                'Bank_Name' => 'IS BANK',
                'Bank_Brand' => 'MASTERCARD',
                'Card_Type' => 'CREDIT',
                'Card_Family' => 'Maximum',
                'Card_Kind' => 'TİCARİ KART',
            ]),
        ]);

        $profile = $this->provider->lookup('51015200');

        $this->assertSame('51015200', $profile->bin);
        $this->assertSame('64', $profile->issuerCode);
        $this->assertSame(CardScheme::Mastercard, $profile->scheme);
        $this->assertSame(CardType::Credit, $profile->type);
        $this->assertSame(CardCategory::Commercial, $profile->category);
        $this->assertSame('maximum', $profile->family);
        $this->assertSame('IS BANK', $profile->issuer);
        $this->assertNull($profile->issuerCountry);
        $this->assertSame('hoppa', $profile->source);
        $this->assertSame('MASTERCARD', $profile->raw['Bank_Brand']);
    }

    public function test_a_personal_card_is_reported_as_consumer(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'CREDIT',
                'Card_Family' => 'Bonus',
                'Card_Kind' => 'BİREYSEL KART',
            ]),
        ]);

        $this->assertSame(CardCategory::Consumer, $this->provider->lookup('51015200')->category);
    }

    public function test_a_card_kind_hoppa_does_not_classify_leaves_the_category_open(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'DEBIT',
                'Card_Family' => 'Paraf',
                'Card_Kind' => 'DEBİT KART',
            ]),
        ]);

        $this->assertNull($this->provider->lookup('45218200')->category);
    }

    public function test_a_family_hoppa_does_not_report_leaves_the_field_open(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Bank_Brand' => 'VISA',
                'Card_Type' => 'DEBIT',
            ]),
        ]);

        $profile = $this->provider->lookup('45218200');

        $this->assertNull($profile->family);
        $this->assertSame(CardType::Debit, $profile->type);
    }

    public function test_a_brand_hoppa_does_not_name_leaves_the_scheme_open(): void
    {
        $this->fakeBin();

        $this->assertNull($this->provider->lookup('51015200')->scheme);
    }

    public function test_the_cache_holds_the_payload_rather_than_the_profile(): void
    {
        $this->fakeBin();

        $this->provider->lookup('51015200');

        $this->assertSame(
            ['Card_Type' => 'CREDIT', 'Card_Family' => 'Bonus'],
            Cache::get('payline:bin-lookup:hoppa:51015200'),
        );
    }

    public function test_a_cached_payload_is_mapped_without_asking_again(): void
    {
        Cache::put('payline:bin-lookup:hoppa:51015200', [
            'Card_Type' => 'DEBIT',
            'Card_Family' => 'Paraf',
            'Bank_Name' => 'HALKBANK',
        ], 60);

        Http::fake();

        $profile = $this->provider->lookup('51015200');

        $this->assertSame('paraf', $profile->family);
        $this->assertSame(CardType::Debit, $profile->type);
        $this->assertSame('HALKBANK', $profile->issuer);
        Http::assertNothingSent();
    }

    public function test_a_resolved_profile_is_served_from_the_cache(): void
    {
        $this->fakeBin();

        $this->provider->lookup('51015200');
        $profile = $this->provider->lookup('51015200');

        $this->assertSame('bonus', $profile->family);
        $this->assertSame(CardType::Credit, $profile->type);
        Http::assertSentCount(1);
    }

    public function test_the_cache_is_keyed_by_the_bin(): void
    {
        $this->fakeBin();

        $this->provider->lookup('51015200');
        $this->provider->lookup('45218299');

        Http::assertSentCount(2);
    }

    public function test_a_full_card_number_reaches_the_same_cache_entry_as_its_bin(): void
    {
        $this->fakeBin();

        $this->provider->lookup('51015200');
        $this->provider->lookup('5101520012345678');

        Http::assertSentCount(1);
    }

    public function test_an_unresolved_bin_is_asked_again(): void
    {
        Http::fake(['*/api/services/EYVBinService' => Http::response([])]);

        $this->provider->lookup('51015200');
        $this->provider->lookup('51015200');

        Http::assertSentCount(2);
    }

    public function test_caching_is_off_with_a_ttl_of_zero(): void
    {
        $this->fakeBin();

        $provider = new HoppaBinLookup(['test_mode' => true, 'cache_ttl' => 0]);

        $provider->lookup('51015200');
        $provider->lookup('51015200');

        Http::assertSentCount(2);
    }

    private function fakeBin(): void
    {
        Http::fake([
            '*/api/services/EYVBinService' => Http::response([
                'Card_Type' => 'CREDIT',
                'Card_Family' => 'Bonus',
            ]),
        ]);
    }
}
