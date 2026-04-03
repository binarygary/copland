<?php

use App\Commands\SetupCommand;
use App\Support\LaunchdPlist;
use Symfony\Component\Console\Tester\CommandTester;

it('writes the launch agent plist and reloads launchctl through the setup command', function () {
    $home = '/tmp/copland-setup-command-'.uniqid();
    $commands = [];

    mkdir($home, 0755, true);

    $command = new SetupCommand(
        plistBuilder: new LaunchdPlist,
        runner: function (array $command) use (&$commands): array {
            $commands[] = $command;

            if ($command[1] === 'unload') {
                return ['stdout' => '', 'stderr' => 'not loaded', 'exitCode' => 1];
            }

            return ['stdout' => 'ok', 'stderr' => '', 'exitCode' => 0];
        },
        homeResolver: fn (): string => $home,
        phpBinaryResolver: fn (): string => '/opt/homebrew/bin/php',
        projectRootResolver: fn (): string => '/Users/tester/projects/copland',
        pathResolver: fn (): string => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
    );
    $command->setLaravel($this->app);

    $tester = new CommandTester($command);
    $exitCode = $tester->execute(['--hour' => '3', '--minute' => '15']);
    $display = $tester->getDisplay();

    expect($exitCode)->toBe(0);
    expect($display)->toContain('Installed plist: '.$home.'/Library/LaunchAgents/com.binarygary.copland.plist');
    expect($display)->toContain('Label: com.binarygary.copland');
    expect($display)->toContain('Manual verification: launchctl start com.binarygary.copland');

    expect(file_exists($home.'/Library/LaunchAgents/com.binarygary.copland.plist'))->toBeTrue();
    expect(file_exists($home.'/.copland/logs/launchd'))->toBeTrue();
    expect($commands)->toBe([
        ['launchctl', 'unload', $home.'/Library/LaunchAgents/com.binarygary.copland.plist'],
        ['launchctl', 'load', $home.'/Library/LaunchAgents/com.binarygary.copland.plist'],
    ]);
});
