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

    private const int TIMEOUT = 5;

    private readonly string $baseUrl;

    private readonly int $cacheTtl;

    private readonly ?string $cacheStore;

    private readonly int $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url']
            ?? (($config['test_mode'] ?? false) ? self::TEST_BASE_URL : self::LIVE_BASE_URL);

        $this->cacheTtl = (int) ($config['cache_ttl'] ?? self::CACHE_TTL);
        $this->cacheStore = $config['cache_store'] ?? null;
        $this->timeout = (int) ($config['timeout'] ?? self::TIMEOUT);
    }

    public function lookup(string $bin): ?CardProfile
    {
        $bin = substr($bin, 0, 8);

        if ($this->cacheTtl <= 0) {
            return $this->map($bin, $this->fetch($bin));
        }

        $cache = Cache::store($this->cacheStore);
        $key = self::CACHE_PREFIX . $bin;
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return $this->map($bin, $cached);
        }

        $body = $this->fetch($bin);
        $profile = $this->map($bin, $body);

        if ($profile !== null) {
            $cache->put($key, $body, $this->cacheTtl);
        }

        return $profile;
    }

    private function fetch(string $bin): ?array
    {
        $body = Http::timeout($this->timeout)
            ->post($this->baseUrl . '/api/services/EYVBinService', ['CardNumber' => $bin])
            ->json();

        return is_array($body) ? $body : null;
    }

    private function map(string $bin, ?array $body): ?CardProfile
    {
        if (empty($body)) {
            return null;
        }

        $family = mb_strtolower(trim((string) ($body['Card_Family'] ?? '')), 'UTF-8');
        $type = CardType::parse($body['Card_Type'] ?? null);

        if ($family === '' && $type === null) {
            return null;
        }

        return new CardProfile(
            bin: $bin,
            scheme: CardScheme::parse($body['Bank_Brand'] ?? null),
            type: $type,
            category: $this->category($body['Card_Kind'] ?? null),
            family: $family === '' ? null : $family,
            issuer: $this->text($body['Bank_Name'] ?? null),
            issuerCode: $this->text($body['Bank_Code'] ?? null),
            source: 'hoppa',
            raw: $body,
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
