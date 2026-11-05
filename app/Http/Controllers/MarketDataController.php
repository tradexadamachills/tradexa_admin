<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MarketDataController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['symbol' => 'sometimes|in:BTCUSDT,ETHUSDT,SOLUSDT', 'interval' => 'sometimes|in:15m,1h,4h,1d']);
        $symbol = $data['symbol'] ?? 'BTCUSDT';
        $interval = $data['interval'] ?? '1h';
        try {
            $result = Cache::remember('admin-markets-v1-'.$symbol.'-'.$interval, 15, function () use ($symbol, $interval): array {
                $client = Http::baseUrl('https://data-api.binance.vision/api/v3')->acceptJson()->connectTimeout(3)->timeout(6);
                $tickers = $client->get('/ticker/24hr', ['symbols' => json_encode(['BTCUSDT', 'ETHUSDT', 'SOLUSDT'])])->throw()->json();
                $candles = $client->get('/klines', ['symbol' => $symbol, 'interval' => $interval, 'limit' => 64])->throw()->json();
                $book = $client->get('/depth', ['symbol' => $symbol, 'limit' => 10])->throw()->json();
                if (! is_array($tickers) || ! is_array($candles) || ! is_array($book)) {
                    throw new \UnexpectedValueException('Invalid market response.');
                }
                $quotes = [];
                foreach ($tickers as $row) {
                    if (! is_array($row) || ! in_array($row['symbol'] ?? null, ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'], true)) {
                        continue;
                    }
                    $price = $this->number($row['lastPrice'] ?? null);
                    $change = $this->number($row['priceChangePercent'] ?? null, false);
                    $time = $row['closeTime'] ?? null;
                    if (! is_int($time) || abs(now()->getTimestampMs() - $time) > 60000) {
                        throw new \UnexpectedValueException('Stale market quote.');
                    }
                    $quotes[] = ['symbol' => $row['symbol'], 'price' => $price, 'change' => $change, 'time' => $time];
                }
                if (count($quotes) !== 3 || count($candles) < 2) {
                    throw new \UnexpectedValueException('Incomplete market response.');
                }
                $bars = [];
                foreach ($candles as $row) {
                    if (! is_array($row) || ! is_int($row[0] ?? null)) {
                        throw new \UnexpectedValueException('Invalid candle.');
                    }
                    $open = $this->number($row[1] ?? null);
                    $high = $this->number($row[2] ?? null);
                    $low = $this->number($row[3] ?? null);
                    $close = $this->number($row[4] ?? null);
                    if ($high < max($open, $close) || $low > min($open, $close) || $low > $high) {
                        throw new \UnexpectedValueException('Invalid OHLC values.');
                    }
                    $bars[] = ['time' => $row[0], 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close];
                }
                $duration = ['15m' => 900000, '1h' => 3600000, '4h' => 14400000, '1d' => 86400000][$interval];
                $lastTime = $bars[array_key_last($bars)]['time'];
                if (now()->getTimestampMs() - $lastTime > $duration * 2 || $lastTime > now()->getTimestampMs() + 30000) {
                    throw new \UnexpectedValueException('Stale candle response.');
                }
                $depth = [];
                foreach (['asks', 'bids'] as $side) {
                    if (! is_array($book[$side] ?? null) || ! count($book[$side])) {
                        throw new \UnexpectedValueException('Missing order book.');
                    }
                    $depth[$side] = [];
                    foreach (array_slice($book[$side], 0, 10) as $row) {
                        $depth[$side][] = [$this->number($row[0] ?? null), $this->number($row[1] ?? null)];
                    }
                }

                return ['source' => 'Binance spot', 'symbol' => $symbol, 'interval' => $interval, 'quotes' => $quotes, 'candles' => $bars, 'book' => $depth, 'received_at' => now()->getTimestampMs()];
            });

            foreach ($result['quotes'] as $quote) {
                if (now()->getTimestampMs() - $quote['time'] > 60000 || $quote['time'] - now()->getTimestampMs() > 30000) {
                    throw new \UnexpectedValueException('Expired market snapshot.');
                }
            }

            return response()->json($result);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Current market data is unavailable. Please retry shortly.'], 503);
        }
    }

    private function number(mixed $value, bool $positive = true): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || ($positive && (float) $value <= 0)) {
            throw new \UnexpectedValueException('Invalid market value.');
        }

        return (float) $value;
    }
}
