# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/nette-scheduler/compare/1.0.2...HEAD)

### Added

- compatibility with orisai/scheduler:^2.0.0
- planning jobs by seconds
- timezones support
- locked job, before run and after run events
- job results are shown in console immediately
- stderr handling in subprocesses - causes an exception
- stdout handling in subprocesses - causes a notice, instead of an exception

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
