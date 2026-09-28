<?php

namespace XLaravel\Payline\BinLookup\Hoppa;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class HoppaBinLookup implements BinLookupProvider
{
    private const string TEST_BASE_URL = 'https://posservicetest.esnekpos.com';

    private const string LIVE_BASE_URL = 'https://posservice.esnekpos.com';

    private const int CACHE_TTL = 2592000;

    private const string CACHE_PREFIX = 'payline:bin-lookup:hoppa:';

    private readonly string $baseUrl;

    private readonly int $cacheTtl;

    private readonly ?string $cacheStore;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url']
            ?? (($config['test_mode'] ?? false) ? self::TEST_BASE_URL : self::LIVE_BASE_URL);

        $this->cacheTtl = (int) ($config['cache_ttl'] ?? self::CACHE_TTL);
        $this->cacheStore = $config['cache_store'] ?? null;
    }

    public function lookup(string $bin): ?CardProfile
    {
        $bin = substr($bin, 0, 8);

        if ($this->cacheTtl <= 0) {
            return $this->fetch($bin);
        }

        $cache = Cache::store($this->cacheStore);
        $key = self::CACHE_PREFIX . $bin;
        $cached = $cache->get($key);

        if ($cached instanceof CardProfile) {
            return $cached;
        }

        $profile = $this->fetch($bin);

        if ($profile !== null) {
            $cache->put($key, $profile, $this->cacheTtl);
        }

        return $profile;
    }

    private function fetch(string $bin): ?CardProfile
    {
        $response = Http::post($this->baseUrl . '/api/services/EYVBinService', [
            'CardNumber' => $bin,
        ])->json();

        if (empty($response)) {
            return null;
        }

        $family = mb_strtolower(trim((string) ($response['Card_Family'] ?? '')), 'UTF-8');
        $type = CardType::parse($response['Card_Type'] ?? null);

        if ($family === '' && $type === null) {
            return null;
        }

        return new CardProfile(
            bin: $bin,
            scheme: CardScheme::parse($response['Bank_Brand'] ?? null),
            type: $type,
            category: $this->category($response['Card_Kind'] ?? null),
            family: $family === '' ? null : $family,
            issuer: $this->text($response['Bank_Name'] ?? null),
            issuerCode: $this->text($response['Bank_Code'] ?? null),
            source: 'hoppa',
            raw: $response,
        );
    }

    private function category(?string $kind): ?CardCategory
    {
        if ($kind === null) {
            return null;
        }

        return match (true) {
            str_contains($kind, 'TİCAR'), str_contains($kind, 'TICAR') => CardCategory::Commercial,
            str_contains($kind, 'BİREYSEL'), str_contains($kind, 'BIREYSEL') => CardCategory::Consumer,
            default => null,
        };
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
