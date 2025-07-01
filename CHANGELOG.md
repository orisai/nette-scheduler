# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/nette-scheduler/compare/1.3.0...v1.x)

## [1.3.0](https://github.com/orisai/nette-scheduler/compare/1.2.3...1.3.0) - 2025-07-01

### Removed

- Compatibility with `orisai/scheduler` v1 (only v2 is now supported)
- Compatibility with `nette/di` <3.1.0

## [1.2.3](https://github.com/orisai/nette-scheduler/compare/1.2.2...1.2.3) - 2025-01-19

### Added

- Support for IntelliJ Neon Pro plugin

## [1.2.2](https://github.com/orisai/nette-scheduler/compare/1.2.1...1.2.2) - 2024-12-29

### Changed

- Composer
	- Allow PHP 8.4

## [1.2.1](https://github.com/orisai/nette-scheduler/compare/1.2.0...1.2.1) - 2024-06-21

### Changed

- Composer
	- Allow PHP 8.3

## [1.2.0](https://github.com/orisai/nette-scheduler/compare/1.1.0...1.2.0) - 2024-05-26

### Added

- compatibility with orisai/scheduler:^2.1.0
- `scheduler:explain` command is available
- `SchedulerExtension`
	- `jobs > <id> > enabled` option - allows disabling job via configuration

### Fixed

- support for expression aliases like `'@yearly'`

## [1.1.0](https://github.com/orisai/nette-scheduler/compare/1.0.2...1.1.0) - 2024-01-26

### Added

- compatibility with orisai/scheduler:^2.0.0
- planning jobs by seconds
- timezones support
- locked job, before run and after run events

### Fixed

- `SchedulerExtension` - error message for case when neither `callback` nor `job` or both options are defined

## [1.0.2](https://github.com/orisai/nette-scheduler/compare/1.0.1...1.0.2) - 2023-10-05

### Added

- `Scheduler` is always available via `Container->getByType()` (even with `di > export > types: false`)

### Fixed

- Allow nette/di ^3.0.5 (was erroneously locked to 3.1.2)

## [1.0.1](https://github.com/orisai/nette-scheduler/compare/1.0.0...1.0.1) - 2023-04-04

### Changed

- Allow nette/di ^3.0.5

## [1.0.0](https://github.com/orisai/nette-scheduler/releases/tag/1.0.0) - 2023-03-23

### Added

- `SchedulerExtension`
- `LazyJobManager`
- `SchedulerTracyLogger`
