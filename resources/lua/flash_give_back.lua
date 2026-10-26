-- Undo a take (hold released, expired, cancelled, or the database refused the reservation).
-- KEYS[1] stock counter   KEYS[2] customer's held count
-- ARGV[1] quantity        ARGV[2] '1' when the customer count must be reduced too
-- Does nothing when the counter does not exist (variant not primed / flash sale switched off).
if redis.call('EXISTS', KEYS[1]) == 1 then
  redis.call('INCRBY', KEYS[1], ARGV[1])

  if ARGV[2] == '1' and redis.call('EXISTS', KEYS[2]) == 1 then
    local left = redis.call('DECRBY', KEYS[2], ARGV[1])
    if left < 0 then redis.call('INCRBY', KEYS[2], -left) end
  end
end

return 1
