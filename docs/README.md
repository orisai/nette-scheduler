# Nette Scheduler

[Orisai Scheduler](https://github.com/orisai/scheduler) integration for [Nette](https://nette.org)

## Content

- [Why do you need it?](#why-do-you-need-it)
- [Quick start](#quick-start)
- [Execution time](#execution-time)
- [Events](#events)
- [Handling errors](#handling-errors)
- [Locks and job overlapping](#locks-and-job-overlapping)
- [Parallelization and process isolation](#parallelization-and-process-isolation)
- [Job types](#job-types)
	- [Callback job](#callback-job)
	- [Custom job](#custom-job)
- [Job info and result](#job-info-and-result)
- [Run summary](#run-summary)
- [Run single job](#run-single-job)
- [CLI commands](#cli-commands)
	- [Run command - run jobs once](#run-command)
	- [Run job command - run single job](#run-job-command)
	- [List command - show all jobs](#list-command)
	- [Worker command - run jobs periodically](#worker-command)

## Why do you need it?

Let's say you already use cron jobs configured via crontab (or custom solution given by hosting). Each cron has to be
registered in crontab in every single environment (e.g. local, stage, production) and application itself is generally
not aware of these cron jobs.

With this library you can manage all cron jobs in application and setup them in crontab with single line.

> Why not any [alternative library](https://github.com/search?q=php+cron&type=repositories)? There is ton of them.

Well, you are right. But do they manage everything needed? By not using crontab directly you loose several features that
library has to replicate:

- [parallelism](#parallelization-and-process-isolation) - jobs should run in parallel and start in time even if one or
  more run for a long time
- [failure protection](#handling-errors) - if one job fails, the failure should be logged and the other jobs should
  still be executed
- [cron expressions](#execution-time) - library has to parse and properly evaluate cron expression to determine whether
  job should be run

Orisai Scheduler solves all of these problems.

On top of that you get:

- [locking](#locks-and-job-overlapping) - each job should run only once at a time, without overlapping
- [before/after job events](#events) for accessing job status
- [overview of all jobs](#list-command), including estimated time of next run
- running jobs either [once](#run-command) or [periodically](#worker-command) during development
- running just a [single](#run-single-job) job, either ignoring or respecting due times

## Quick start

Install with [Composer](https://getcomposer.org)

```sh
composer require orisai/nette-scheduler
```

Register scheduler extension

```neon
extensions:
	orisai.scheduler: OriNette\Scheduler\DI\SchedulerExtension
```

Create service which will be run as a job

```php
namespace Example;

class ExampleJobService
{

	public function run(): void
	{
		// Do something
	}

}
```

```neon
orisai.scheduler:
	jobs:
		-
			expression: * * * * *
			callback: [@example.job.service, 'run']

services:
	example.job.service: Example\ExampleJobService
```

Create script with scheduler setup (e.g. `bin/scheduler.php`)

```php
use Orisai\Scheduler\Scheduler;

require __DIR__ . '/../vendor/autoload.php';

$configurator = Bootstrap::boot();
$container = $configurator->createContainer();
$scheduler = $container->getByType(Scheduler::class);

$scheduler->run();
```

Configure crontab to run your script each minute

```
* * * * * cd path/to/project && php bin/scheduler.php >> /dev/null 2>&1
```

Got to go!

## Execution time

Cron execution time is expressed via `expression`, using crontab syntax

```php
orisai.scheduler:
	jobs:
		-
			expression: * * * * *
			callback: # ...
```

It's important to use caution with cron syntax, so please refer to the example below.
To validate your cron, you can also utilize [crontab.guru](https://crontab.guru).

```
*   *   *   *   *
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (0-6) (or SUN-SAT) (0=Sunday)
|   |   |   +--------- month (1-12) (or JAN-DEC)
|   |   +------------- day of month (1-31)
|   +----------------- hour (0-23)
+--------------------- minute (0-59)
```

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - `* * * * *` - At every minute.
- lists - e.g. `15,30 * * * *` - At minute 15 and 30.
- ranges - e.g. `1-9 * * * *` - At every minute from 1 through 9.
- steps - e.g. `*/5 * * * *` - At every 5th minute.

You can also use macro instead of an expression:

- `@yearly`, `@annually` - Run once a year, midnight, Jan. 1 - `0 0 1 1 *`
- `@monthly` - Run once a month, midnight, first of month - `0 0 1 * *`
- `@weekly` - Run once a week, midnight on Sun - `0 0 * * 0`
- `@daily`, `@midnight` - Run once a day, midnight - `0 0 * * *`
- `@hourly` - Run once an hour, first minute - `0 * * * *`

## Events

Run callbacks before and after job to collect statistics, etc.

```neon
orisai.scheduler:
	events:
		# list<callable>
		beforeJob:
			# same as jobs > [job] > callback, any valid callable
			- @handler
		# list<callable>
		afterJob:
			# same as jobs > [job] > callback, any valid callable
			- @handler
```

Check [job info and result](#job-info-and-result) for available status info

And check [callback job](#callback-job) `callback` syntax for more examples, events can use all shown variants too

## Handling errors

After all jobs finish, an exception `RunFailure` composing exceptions thrown by all jobs is thrown. This
exception will inform you about which exceptions were thrown, including their messages and source. But this still makes
exceptions hard to access by application error handler and causes [CLI commands](#cli-commands) to hard fail.

To overcome this limitation, add minimal error handler into scheduler. When an error handler is
set, `RunFailure` is *not thrown*.

If you use Tracy and simply want to log exception, use:

```neon
orisai.scheduler:
	errorHandler: tracy
```

Assuming you have a [PSR-3 logger](https://github.com/php-fig/log), e.g. [Monolog](https://github.com/Seldaek/monolog)
installed, extended logging would look like this:

```php
namespace Example;

class SchedulerLogger
{

	public function log(Throwable $throwable, JobInfo $info, JobResult $result): void
	{
		$this->logger->error("Job {$info->getName()} failed", [
			'exception' => $throwable,
			'name' => $info->getName(),
			'expression' => $info->getExpression(),
			'start' => $info->getStart()->format(DateTimeInterface::ATOM),
			'end' => $result->getEnd()->format(DateTimeInterface::ATOM),
		]);
	}

}
```

```neon
orisai.scheduler:
	errorHandler: [@schedulerLogger, 'log']

services:
	schedulerLogger: Example\SchedulerLogger
```

## Locks and job overlapping

Crontab jobs are time-based and simply run at specified intervals. If they take too long, they may overlap and run
simultaneously. This may cause issues if the jobs access the same resources, such as files or databases, leading to
conflicts or data corruption.

To avoid such issues, we provide locking mechanism which ensures that only one instance of a job is running at any given
time.

```neon
services:
	symfony.lock.factory: Symfony\Component\Lock\LockFactory(
		Symfony\Component\Lock\Store\FlockStore()
	)
```

To choose the right lock store for your use case, please refer
to [symfony/lock](https://symfony.com/doc/current/components/lock.html) documentation. There are several available
stores with various levels of reliability, affecting when lock is released.

Lock is automatically acquired and released by scheduler even if a (recoverable) error occurred during job or its
events. Yet you still have to handle lock expiring in case your jobs take more than 5 minutes, and you are using an
expiring store.

```php
namespace Example;

use Orisai\Scheduler\Job\JobLock;

class ExampleJobService
{

	public function run(JobLock $lock): void
	{
		// Lock methods are the same as symfony/lock provides
		$lock->isAcquiredByCurrentProcess(); // bool (same is symfony isAcquired(), but with more accurate name)
		$lock->getRemainingLifetime(); // float|null
		$lock->isExpired(); // bool
		$lock->refresh(); // void
	}

}
```

## Parallelization and process isolation

It is important for crontab scheduler tasks to be executed asynchronously and in separate processes because this
approach provides several benefits, including:

- Isolation: Each task runs in its own separate process, which ensures that it is isolated from other tasks and any
  errors or issues that occur in one task will not affect the execution of other tasks.
- Resource management: Asynchronous execution of tasks allows for better resource management as multiple tasks can be
  executed simultaneously without causing resource conflicts.
- Efficiency: Asynchronous execution also allows for greater efficiency as tasks can be executed concurrently, reducing
  the overall execution time.
- Scalability: Asynchronous execution enables the system to scale more easily as additional tasks can be added without
  increasing the load on any one process.
- Flexibility: Asynchronous execution also allows for greater flexibility in scheduling as tasks can be scheduled to run
  at different times and frequencies without interfering with each other.

Overall, asynchronous and separate process execution of crontab scheduler tasks provides better performance,
reliability, and flexibility than running tasks synchronously in a single process.

To set up scheduler for parallelization and process isolation, you need to
have [proc_*](https://www.php.net/manual/en/ref.exec.php) functions enabled. Also in the background is
used [run-job command](#run-job-command), so you need to have [console](#cli-commands) set up as well.

If `proc_*` functions are enabled, parallelism is auto-enabled. You can also explicitly require parallelism via
value `'process'` or disable it with `'basic'`.

```neon
orisai.scheduler:
	# auto|basic|process
	# Default: auto
	executor: auto
```

If your executable script is not `bin/console` or if you are using multiple scheduler setups, specify the executable:

```neon
orisai.scheduler:
	console:
		# string
		# Default: bin/console
		script: bin/console
		# string
		# Default: scheduler:run-job
		runJobCommand: scheduler:run-job
```

## Job types

### Callback job

Calls given callback, when job is run

```neon
orisai.scheduler:
	jobs:
		-
			expression: * * * * *
			# service 'example.job.service' with 'run' method
			callback: [@example.job.service, 'run']
		-
			expression: * * * * *
			# service 'example.job.service' with '__invoke' method
			callback: @example.job.service
		-
			expression: * * * * *
			# instance of class with 'run' method
			callback: [Example\ExampleJobService(), 'run']
		-
			expression: * * * * *
			# instance of class with '__invoke' method
			callback: Example\ExampleJobService()
```

### Custom job

Create own job implementation

```php
namespace Example;

use Orisai\Scheduler\Job\Job;
use Orisai\Scheduler\Job\JobLock;

final class CustomJob implements Job
{

	public function getName(): string
	{
		// Provide (preferably unique) name of the job. It will be used in jobs list
		return static::class;
	}

	public function run(JobLock $lock): void
	{
 		// Do whatever you need to
	}

}
```

```neon
orisai.scheduler:
	jobs:
		-
			expression: * * * * *
			# @service.reference and any syntax that works in services section is fine
			job: Example\CustomJob()
```

## Job info and result

Status information available via [events](#events) and [run summary](#run-summary)

Info:

```php
$id = $info->getId(); // string|int
$name = $info->getName(); // string
$expression = $info->getExpression(); // string, e.g. '* * * * *'
$start = $info->getStart(); // DateTimeImmutable
```

Result:

```php
$end = $result->getEnd(); // DateTimeImmutable
$state = $result->getState(); // JobResultState

// Next runs are computed from time when job was finished
$nextRun = $info->getNextRunDate(); // DateTimeImmutable
$threeNextRuns = $info->getNextRunDates(3); // list<DateTimeImmutable>
```

## Run summary

Scheduler run returns summary for inspection

```php
$summary = $scheduler->run(); // RunSummary

$summary->getStart(); // DateTimeImmutable
$summary->getEnd(); // DateTimeImmutable

foreach ($summary->getJobs() as $jobSummary) {
	$jobSummary->getInfo(); // JobInfo
	$jobSummary->getResult(); // JobResult
}
```

Check [job info and result](#job-info-and-result) for available jobs status info

## Run single job

For testing purposes it may be useful to run single job

To do so, assign an ID to job when adding it to scheduler. You may also use an auto-assigned ID visible
in [list command](#list-command) but that's not recommended because it depends just on order in which jobs were added.

```neon
orisai.scheduler:
	jobs:
		id:
			expression: # ...
			callback: # ...
```

```php
$scheduler->runJob('id'); // JobSummary
```

If you still want to respect job schedule and run it only if it is due, set 2nd parameter to false

```php
$scheduler->runJob('id', false); // JobSummary|null
```

[Handling errors](#handling-errors) is the same as for `run()` method, except instead of `RunFailure` is
thrown `JobFailure`.

## CLI commands

For symfony/console you may use our commands:

- [Run](#run-command)
- [Run job](#run-job-command)
- [List](#list-command)
- [Worker](#worker-command)

> Examples assume you run console via executable php script `bin/console`

To register commands, just use a console extension,
e.g. [orisai/nette-console](https://github.com/orisai/nette-console).

### Run command

Run scheduler once, executing jobs scheduled for the current minute

`bin/console scheduler:run`

- use `--json` to output json with job info and result

You can also change crontab settings to use command instead:

```
* * * * * cd path/to/project && php bin/console scheduler:run >> /dev/null 2>&1
```

### Run job command

Run single job, ignoring scheduled time

`bin/console scheduler:run-job <id>`

- use `--no-force` to respect due time and only run job if it is due
- use `--json` to output json with job info and result

### List command

List all scheduled jobs (in `expression [id] name... next-due` format)

`bin/console scheduler:list`

- use `--next` to sort jobs by their next execution time
- `--next=N` lists only *N* next jobs (e.g. `--next=3` prints maximally 3)
- use `-v` to display absolute times

### Worker command

Run scheduler repeatedly, once every minute

`bin/console scheduler:worker`

- requires [proc_*](https://www.php.net/manual/en/ref.exec.php) functions to be enabled
- if your executable script is not `bin/console` or if you are using multiple scheduler setups, specify the executable:
	- via `your/console scheduler:worker -s=your/console -c=scheduler:run`
	- or via neon
	```neon
	orisai.scheduler:
		console:
			# string
			# Default: bin/console
			script: bin/console
			# string
			# Default: scheduler:run
			runCommand: scheduler:run
	```
