<?php

declare(strict_types = 1);

namespace Illuminate\Tests\Integration\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\Attributes\WithMigration;

#[WithMigration]
#[WithMigration('cache')]
#[WithMigration('queue')]
class DebouncedJobTest extends QueueTestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'database');
        $app['config']->set('queue.default', 'database');
    }

    public function testItOnlyExecuteswithDelay()
    {
        $this->freezeTime();
        DebouncableTestJob::dispatch(1, 'test');
        $this->runQueueWorkerCommand(['--stop-when-empty' => true]);
        $this->assertFalse(DebouncableTestJob::$handled);
        $this->assertTrue(DebouncableTestJob::$handled);
    }
}

class DebouncableTestJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, Dispatchable;

    public static $handled = false;

    public function handle()
    {
        dd(1);
        static::$handled = true;
    }
}
