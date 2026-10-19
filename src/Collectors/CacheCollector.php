<?php

namespace Postingher\MonitorAgent\Collectors;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Postingher\MonitorAgent\Agent;
use Postingher\MonitorAgent\Support\Timestamp;
use Throwable;

/**
 * Sums cache operations per normalized key in memory and hands one summary per
 * key to the agent right before each batch is sent, never one event per operation.
 */
class CacheCollector
{
    /**
     * Distinct keys summed at once; the rest are added to one "*other*" row.
     */
    private const MAX_KEYS = 500;

    private const IGNORED_PREFIXES = ['illuminate:', 'monitor-agent:'];

    /**
     * @var array<string, array{hits: int, misses: int, writes: int, forgets: int}>
     */
    private array $keys = [];

    public function __construct(private Agent $agent) {}

    /**
     * Replace ids, hashes, tokens and emails in a key with "*", so keys group and carry no personal data.
     */
    public static function normalize(string $key): string
    {
        $key = (string) preg_replace('/[^\s:\/]+@[^\s:\/]+/', '*', $key);

        return (string) preg_replace_callback(
            '#[^:/._\-]+#',
            fn (array $match): string => preg_match('/\d/', $match[0]) || strlen($match[0]) > 20 ? '*' : $match[0],
            $key,
        );
    }

    /**
     * Count a cache operation.
     */
    public function handle(CacheEvent $event): void
    {
        try {
            if ($this->agent->isFlushing() || str_starts_with($event->key, self::IGNORED_PREFIXES[0]) || str_starts_with($event->key, self::IGNORED_PREFIXES[1])) {
                return;
            }

            $key = self::normalize($event->key);

            if (! isset($this->keys[$key]) && count($this->keys) >= self::MAX_KEYS) {
                $key = '*other*';
            }

            $this->keys[$key] ??= ['hits' => 0, 'misses' => 0, 'writes' => 0, 'forgets' => 0];
            $this->keys[$key][match (true) {
                $event instanceof CacheHit => 'hits',
                $event instanceof CacheMissed => 'misses',
                $event instanceof KeyWritten => 'writes',
                $event instanceof KeyForgotten => 'forgets',
            }]++;
        } catch (Throwable) {
            // Never let monitoring break the application.
        }
    }

    /**
     * Hand the summed keys to the agent and start over.
     */
    public function drain(): void
    {
        $keys = $this->keys;
        $this->keys = [];
        $recordedAt = Timestamp::now();

        foreach ($keys as $key => $counts) {
            $this->agent->record(['type' => 'cache', 'key' => mb_strcut($key, 0, 255), ...$counts, 'recorded_at' => $recordedAt]);
        }
    }
}
