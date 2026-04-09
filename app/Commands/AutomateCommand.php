<?php

namespace App\Commands;

use App\Support\HomeDirectory;
use App\Support\LaunchdPlist;
use App\Support\ProgressReporter;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

class AutomateCommand extends Command
{
    protected $signature = 'automate
        {--hour=2 : Hour for the nightly launchd run (0-23)}
        {--minute=0 : Minute for the nightly launchd run (0-59)}';

    protected $description = 'Install or refresh the macOS launchd job for nightly Copland runs';

    public function __construct(
        private ?LaunchdPlist $plistBuilder = null,
        private $runner = null,
        private $homeResolver = null,
        private $phpBinaryResolver = null,
        private $projectRootResolver = null,
        private $pathResolver = null,
    ) {
        parent::__construct();

        $this->plistBuilder ??= new LaunchdPlist;
        $this->homeResolver ??= static fn (): string => HomeDirectory::resolve();
        $this->phpBinaryResolver ??= static fn (): string => PHP_BINARY;
        $this->projectRootResolver ??= static fn (): string => base_path();
        $this->pathResolver ??= static function (): string {
            $current = getenv('PATH');
            $segments = array_filter([
                $current ?: null,
                '/opt/homebrew/bin',
                '/usr/local/bin',
                '/usr/bin',
                '/bin',
            ]);

            $unique = [];
            foreach ($segments as $segment) {
                foreach (explode(':', $segment) as $path) {
                    if ($path === '' || in_array($path, $unique, true)) {
                        continue;
                    }

                    $unique[] = $path;
                }
            }

            return implode(':', $unique);
        };
    }

    public function handle(): int
    {
        $progress = new ProgressReporter(totalSteps: 5);
        $hour = $this->validatedHour();
        $minute = $this->validatedMinute();
        $home = ($this->homeResolver)();
        $projectRoot = ($this->projectRootResolver)();
        $phpBinary = ($this->phpBinaryResolver)();
        $path = ($this->pathResolver)();
        $plistPath = $this->plistBuilder->plistPath($home);
        $plistDirectory = dirname($plistPath);
        $logDirectory = dirname($this->plistBuilder->stdoutPath($home));

        $this->line($progress->step('Resolve launchd installation paths'));
        $this->line($progress->detail("Plist: {$plistPath}"));
        $this->line($progress->detail('Label: '.$this->plistBuilder->label()));

        $this->line($progress->step('Ensure LaunchAgents and log directories exist'));
        $this->ensureDirectoryExists($plistDirectory);
        $this->ensureDirectoryExists($logDirectory);

        $this->line($progress->step('Generate LaunchAgent plist'));
        $plist = $this->plistBuilder->build($home, $projectRoot, $phpBinary, $path, $hour, $minute);
        $this->writeFile($plistPath, $plist);
        $this->line($progress->detail(sprintf('Scheduled nightly run at %02d:%02d', $hour, $minute)));

        $this->line($progress->step('Reload launchd job'));
        $this->reloadLaunchAgent($plistPath);
        $this->line($progress->detail('launchctl reload completed'));

        $this->line($progress->step('Show verification details'));
        $this->line('Installed plist: '.$plistPath);
        $this->line('Label: '.$this->plistBuilder->label());
        $this->line('Stdout log: '.$this->plistBuilder->stdoutPath($home));
        $this->line('Stderr log: '.$this->plistBuilder->stderrPath($home));
        $this->line('Manual verification: launchctl start '.$this->plistBuilder->label());

        return self::SUCCESS;
    }

    private function validatedHour(): int
    {
        $hour = (int) $this->option('hour');

        if ($hour < 0 || $hour > 23) {
            throw new RuntimeException('The --hour option must be between 0 and 23.');
        }

        return $hour;
    }

    private function validatedMinute(): int
    {
        $minute = (int) $this->option('minute');

        if ($minute < 0 || $minute > 59) {
            throw new RuntimeException('The --minute option must be between 0 and 59.');
        }

        return $minute;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory at {$directory}");
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Failed to write LaunchAgent plist to {$path}");
        }
    }

    private function reloadLaunchAgent(string $plistPath): void
    {
        $unload = $this->runShellCommand(['launchctl', 'unload', $plistPath]);
        if ($unload['exitCode'] !== 0) {
            $this->line($this->output->isVerbose()
                ? 'launchctl unload skipped: '.trim($unload['stderr'])
                : 'launchctl unload skipped (job was not loaded yet)');
        }

        $load = $this->runShellCommand(['launchctl', 'load', $plistPath]);
        if ($load['exitCode'] !== 0) {
            throw new RuntimeException('launchctl load failed: '.trim($load['stderr']));
        }
    }

    private function runShellCommand(array $command): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($command);
        }

        $process = new Process($command);
        $process->run();

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }
}
