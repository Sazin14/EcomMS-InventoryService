-- Atomically: check the remaining flash-sale stock and the customer's limit, then take the quantity.
-- KEYS[1] stock counter          KEYS[2] how many this customer already holds
-- ARGV[1] quantity wanted        ARGV[2] per-customer limit (0 = no limit)
-- Returns: remaining stock (>= 0) on success | -1 sold out | -2 counter not primed | -3 customer limit reached
local stock = tonumber(redis.call('GET', KEYS[1]))
if stock == nil then return -2 end

local qty = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])

if limit > 0 then
  local bought = tonumber(redis.call('GET', KEYS[2]) or '0')
  if bought + qty > limit then return -3 end
end

if stock < qty then return -1 end

redis.call('DECRBY', KEYS[1], qty)
if limit > 0 then
  redis.call('INCRBY', KEYS[2], qty)
  redis.call('EXPIRE', KEYS[2], 604800)   -- the per-customer count is forgotten after 7 days
end

return stock - qty
