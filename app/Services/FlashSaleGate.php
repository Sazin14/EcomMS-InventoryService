<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FlashSaleGate
{
    public const OK = 'ok';

    public const SOLD_OUT = 'sold_out';

    public const LIMIT = 'limit';

    public const UNKNOWN = 'unknown';

    private const TAKE = <<<'LUA'
local stock = tonumber(redis.call('HGET', KEYS[1], 'stock'))
if stock == nil then return -1 end
local qty = tonumber(ARGV[1])
if stock < qty then return 0 end
local limit = tonumber(ARGV[2])
local who = ARGV[3]
if limit > 0 and who ~= '' then
  local held = tonumber(redis.call('HGET', KEYS[1], 'c:' .. who)) or 0
  if held + qty > limit then return -2 end
  redis.call('HINCRBY', KEYS[1], 'c:' .. who, qty)
end
redis.call('HINCRBY', KEYS[1], 'stock', -qty)
return 1
LUA;

    private const GIVE_BACK = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
redis.call('HINCRBY', KEYS[1], 'stock', tonumber(ARGV[1]))
if ARGV[2] ~= '' then
  local held = tonumber(redis.call('HGET', KEYS[1], 'c:' .. ARGV[2])) or 0
  local back = math.min(held, tonumber(ARGV[1]))
  if back > 0 then redis.call('HINCRBY', KEYS[1], 'c:' .. ARGV[2], -back) end
end
return 1
LUA;

    private const PRIME = <<<'LUA'
redis.call('HSET', KEYS[1], 'stock', ARGV[1])
return 1
LUA;

    private const FORGET = <<<'LUA'
redis.call('DEL', KEYS[1])
return 1
LUA;

    public function prime(int $variantId, int $available): void
    {
        $this->run(self::PRIME, $variantId, [max(0, $available)]);
    }

    public function forget(int $variantId): void
    {
        $this->run(self::FORGET, $variantId, []);
    }

    /** @return string */
    public function take(int $variantId, int $qty, ?int $customerId, ?int $limitPerCustomer): string
    {
        $result = $this->run(self::TAKE, $variantId, [$qty, (int) ($limitPerCustomer ?? 0), (string) ($customerId ?? '')]);

        return match ($result) {
            1 => self::OK,
            0 => self::SOLD_OUT,
            -2 => self::LIMIT,
            default => self::UNKNOWN,
        };
    }

    public function giveBack(int $variantId, int $qty, ?int $customerId): void
    {
        $this->run(self::GIVE_BACK, $variantId, [$qty, (string) ($customerId ?? '')]);
    }

    private function key(int $variantId): string
    {
        return "flash:{$variantId}";
    }

    private function run(string $script, int $variantId, array $args): ?int
    {
        try {
            return (int) Redis::eval($script, 1, $this->key($variantId), ...$args);
        } catch (Throwable $e) {
            Log::warning('FlashSaleGate: Redis unavailable, using the database only.', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
