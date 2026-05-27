<?php

use App\Commands\ConsoleCommand;
use Symfony\Component\Console\Tester\CommandTester;

it('launches Godot via open when preflights pass', function () {
    $commands = [];

    $command = new ConsoleCommand(
        runner: function (array $command) use (&$commands): array {
            $commands[] = $command;

            return match ($command) {
                ['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"] => [
                    'stdout' => '/Applications/Godot.app',
                    'stderr' => '',
                    'exitCode' => 0,
                ],
                ['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot'] => [
                    'stdout' => '',
                    'stderr' => '',
                    'exitCode' => 0,
                ],
                default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
            };
        },
        projectRootResolver: fn (): string => '/Users/tester/projects/copland',
        projectFileChecker: fn (string $path): bool => $path === '/Users/tester/projects/copland/console-godot/project.godot',
    );
    $command->setLaravel($this->app);

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0);
    expect($commands)->toContain(
        ['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot']
    );
    // D-07: mdfind is the preferred probe and must run first.
    expect($commands[0])->toBe(['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]);
});

it('refuses to launch and reports missing console-godot/ when project file is absent', function () {
    $commands = [];

    $command = new ConsoleCommand(
        // D-08: no shell-out is permitted when preflight #1 fails — any runner call is a bug.
        runner: function (array $command) use (&$commands): array {
            $commands[] = $command;
            throw new RuntimeException('runner should not be invoked when project file is missing');
        },
        projectRootResolver: fn (): string => '/Users/tester/projects/copland',
        projectFileChecker: fn (string $path): bool => false,
    );
    $command->setLaravel($this->app);

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(1);
    expect($tester->getDisplay())->toContain('console-godot/ not found');
    expect($commands)->toBe([]);
});

it('refuses to launch and reports missing Godot.app when neither mdfind nor osascript locates it', function () {
    $commands = [];

    $command = new ConsoleCommand(
        runner: function (array $command) use (&$commands): array {
            $commands[] = $command;

            return match ($command) {
                // mdfind exits 0 but stdout is empty — no hit, falls through to osascript.
                ['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"] => [
                    'stdout' => '',
                    'stderr' => '',
                    'exitCode' => 0,
                ],
                // osascript fails — Godot.app is not registered with Launch Services.
                ['osascript', '-e', 'id of app "Godot"'] => [
                    'stdout' => '',
                    'stderr' => 'execution error: ...',
                    'exitCode' => 1,
                ],
                default => throw new RuntimeException('Unexpected command: '.implode(' ', $command)),
            };
        },
        projectRootResolver: fn (): string => '/Users/tester/projects/copland',
        projectFileChecker: fn (string $path): bool => true,
    );
    $command->setLaravel($this->app);

    $tester = new CommandTester($command);
    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(1);
    expect($tester->getDisplay())->toContain('Godot.app not found');
    // D-08: no launch attempted on preflight failure.
    expect($commands)->not->toContain(
        ['open', '-a', 'Godot', '--args', '--path', '/Users/tester/projects/copland/console-godot']
    );
    // D-07: both probes are tried in order (mdfind first, osascript fallback).
    expect($commands)->toBe([
        ['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"],
        ['osascript', '-e', 'id of app "Godot"'],
    ]);
});
