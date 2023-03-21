<h1 align="center">
	<img src="https://github.com/orisai/.github/blob/main/images/repo_title.png?raw=true" alt="Orisai"/>
	<br/>
	Nette Scheduler
</h1>

<p align="center">
	<a href="https://github.com/orisai/scheduler">Orisai Scheduler</a> integration for <a href="https://nette.org">Nette</a>
</p>

<p align="center">
	📄 Check out our <a href="docs/README.md">documentation</a>.
</p>

<p align="center">
	💸 If you like Orisai, please <a href="https://orisai.dev/sponsor">make a donation</a>. Thank you!
</p>

<p align="center">
	<a href="https://github.com/orisai/nette-scheduler/actions?query=workflow%3ACI">
		<img src="https://github.com/orisai/nette-scheduler/workflows/CI/badge.svg">
	</a>
	<a href="https://coveralls.io/r/orisai/nette-scheduler">
		<img src="https://badgen.net/coveralls/c/github/orisai/nette-scheduler/v1.x?cache=300">
	</a>
	<a href="https://dashboard.stryker-mutator.io/reports/github.com/orisai/nette-scheduler/v1.x">
		<img src="https://badge.stryker-mutator.io/github.com/orisai/nette-scheduler/v1.x">
	</a>
	<a href="https://packagist.org/packages/orisai/nette-scheduler">
		<img src="https://badgen.net/packagist/dt/orisai/nette-scheduler?cache=3600">
	</a>
	<a href="https://packagist.org/packages/orisai/nette-scheduler">
		<img src="https://badgen.net/packagist/v/orisai/nette-scheduler?cache=3600">
	</a>
	<a href="https://choosealicense.com/licenses/mpl-2.0/">
		<img src="https://badgen.net/badge/license/MPL-2.0/blue?cache=3600">
	</a>
<p>

##

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

Looking for more? Documentation is [here](docs/README.md).
