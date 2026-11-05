<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketDataTest extends TestCase
{
    use RefreshDatabase;

    private function staff(bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['public_id' => (string) Str::uuid(), 'role' => 'admin', 'is_active' => $active])->save();

        return $user;
    }

    private function feed(array $quotePatch = [], bool $invalidCandle = false): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $time = now()->getTimestampMs();
        $quotes = [];
        foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT'] as $symbol) {
            $quotes[] = array_merge(['symbol' => $symbol, 'lastPrice' => '80000.25', 'priceChangePercent' => '2.5', 'closeTime' => $time], $quotePatch);
        }
        Http::fake([
            'https://data-api.binance.vision/api/v3/ticker/24hr*' => Http::response($quotes),
            'https://data-api.binance.vision/api/v3/klines*' => Http::response([[$time - 3600000, '79000', '81000', '78000', '80000'], [$time, '80000', $invalidCandle ? '1' : '81000', '79000', '80000.25']]),
            'https://data-api.binance.vision/api/v3/depth*' => Http::response(['asks' => [['80001', '.25']], 'bids' => [['80000', '.5']]]),
        ]);
    }

    public function test_market_endpoint_requires_staff_authentication(): void
    {
        Http::preventStrayRequests();
        $this->get('/market-data')->assertRedirect('/login');
        $this->actingAs($this->staff(false))->getJson('/market-data')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_supported_pairs_and_intervals_can_be_requested(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->staff())->getJson('/market-data?symbol=EVIL&interval=invalid')->assertUnprocessable()->assertJsonValidationErrors(['symbol', 'interval']);
        Http::assertNothingSent();
    }

    public function test_market_snapshot_preserves_exchange_prices_and_candles(): void
    {
        $this->freezeTime();
        $this->feed();
        $this->actingAs($this->staff())->getJson('/market-data?symbol=BTCUSDT&interval=1h')->assertOk()->assertJsonPath('quotes.0.price', 80000.25)->assertJsonPath('candles.1.close', 80000.25)->assertJsonPath('source', 'Binance spot')->assertJsonCount(3, 'quotes');
        Http::assertSentCount(3);
    }

    public function test_old_provider_timestamps_are_not_returned_as_current(): void
    {
        $this->freezeTime();
        $this->feed(['closeTime' => now()->subMinutes(5)->getTimestampMs()]);
        $this->actingAs($this->staff())->getJson('/market-data')->assertServiceUnavailable()->assertJsonMissingPath('quotes');
    }

    public function test_invalid_prices_and_candles_fail_closed(): void
    {
        $this->freezeTime();
        $this->feed(['lastPrice' => 'NaN']);
        $this->actingAs($this->staff())->getJson('/market-data')->assertServiceUnavailable();
        $this->feed(invalidCandle: true);
        $this->getJson('/market-data')->assertServiceUnavailable();
    }

    public function test_provider_failures_return_unavailable_instead_of_demo_prices(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['https://data-api.binance.vision/api/v3/ticker/24hr*' => Http::response(['message' => 'Rate limited'], 429)]);
        $this->actingAs($this->staff())->getJson('/market-data')->assertServiceUnavailable()->assertJsonMissingPath('quotes');
    }
}
