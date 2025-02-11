<?php

declare(strict_types=1);

namespace Illuminate\Queue\Middleware;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

class Debounced
{
    /**
     * @param  InteractsWithQueue|mixed  $job
     * @return mixed|void
     */
    public function handle(mixed $job, $next)
    {
        $key = 'debounced.'.get_class($job);

        if ($job instanceof ShouldBeUnique && method_exists($job, 'uniqueId')) {
            // use the uniqueId to debounce by if defined
            $key .= '.uniqueBy.'.$job->uniqueId();
        }

        /** @var CacheRepository $cache */
        $cache = Container::getInstance()->get(CacheRepository::class);
        /** @var ConfigRepository $cache */
        $config = Container::getInstance()->get(ConfigRepository::class);

        // todo - this could be a performance issue? always reaching into the cache?
        $intendedExecutionTime = $cache->pull($key);

        $isDebounced = ! is_null($intendedExecutionTime);

        if (! $isDebounced) {
            $next($job);

            return;
        }

        $itInteractsWithQueue = in_array(InteractsWithQueue::class, class_uses_recursive($job), true);

        $connection = $job->connection ?? null;

        if (is_null($connection) && $itInteractsWithQueue) {
            $connection = $job->job?->getConnectionName();
        }

        if ($connection === 'sync') {
            if ($config->get('app.debug') || $config->get('app.env') === 'local') {
                throw new \LogicException('Debounced jobs cannot run on the sync queue.');
            }

            // todo - some sort of warning in production?
        }

        if (! $itInteractsWithQueue) {
            $traitName = class_basename(InteractsWithQueue::class);
            throw new \InvalidArgumentException("The Debounced jobs must use the $traitName trait.");
        }

        $count = (int) $cache->pull($key.'.count', 1);

        if ($count > 1) {
            // this isn't the last job of its kind, so delete it
            $job->delete();

            $cache->decrement($key.'.count', $count - 1);

            return;
        }

        if ($intendedExecutionTime) {
            $intendedExecutionTime = Carbon::parse($intendedExecutionTime);

            if ($intendedExecutionTime->gt(now())) {
                // ensure that the intended execution time from the last job is used when this job actually runs
                $job->release($intendedExecutionTime->diffInSeconds(now(), false));

                return;
            }
        }

        /* @see \Illuminate\Queue\Worker::process() */
        // todo - JobProcessing, JobProcessed events still fired by Worker::process do we want that even if it got debounced?
        $next($job);
    }
}
