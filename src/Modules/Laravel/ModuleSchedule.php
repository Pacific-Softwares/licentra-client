<?php

namespace Pacific\Licentra\Modules\Laravel;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduler a module sees in schedule(): call() wraps the task in the module's guard, so a
 * failing task is logged and charged to the module instead of erroring the whole schedule:run.
 * Anything else is passed through to Laravel's Schedule.
 */
final class ModuleSchedule
{
    public function __construct(private readonly Schedule $schedule, private readonly ModuleServiceProvider $module)
    {
    }

    public function call(callable $task, array $parameters = []): CallbackEvent
    {
        return $this->schedule
            ->call(fn () => $this->module->guardTask('scheduled task', fn () => app()->call($task, $parameters)))
            ->name($this->module->slug() . ':' . (is_string($task) ? $task : 'task'))
            ->withoutOverlapping();
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->schedule->{$method}(...$args);
    }
}
