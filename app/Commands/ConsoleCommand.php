<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class ConsoleCommand extends Command
{
    protected $signature = 'console';

    protected $description = 'Launch the Copland Console (Godot 4.2+ GUI pointed at ~/.copland/tasks/)';

    public function __construct(
        private $runner = null,
        private $projectRootResolver = null,
        private $projectFileChecker = null,
    ) {
        parent::__construct();

        $this->projectRootResolver ??= static fn (): string => base_path();
        $this->projectFileChecker ??= static fn (string $path): bool => file_exists($path);
    }

    public function handle(): int
    {
        $projectRoot = ($this->projectRootResolver)();
        $godotProjectDir = $projectRoot.'/console-godot';
        $godotProjectFile = $godotProjectDir.'/project.godot';

        // Preflight #1: console-godot/project.godot must exist (D-07, D-08).
        if (! ($this->projectFileChecker)($godotProjectFile)) {
            $this->error('console-godot/ not found — run from the Copland project root or restore the prototype (see .planning/phases/19-prototype-recovery-console-launcher/).');

            return self::FAILURE;
        }

        // Preflight #2: Godot.app must be locatable via Launch Services (D-07).
        if (! $this->godotAppLocatable()) {
            $this->error('error: Godot.app not found — install Godot 4.2+ (brew install --cask godot, or https://godotengine.org/).');

            return self::FAILURE;
        }

        // Launch — fire-and-forget via macOS `open` (D-04, D-06: no `-W`).
        $result = $this->runShellCommand(['open', '-a', 'Godot', '--args', '--path', $godotProjectDir]);

        if ($result['exitCode'] !== 0) {
            $this->error('open -a Godot failed: '.trim($result['stderr']));

            return self::FAILURE;
        }

        $this->line('Launched Godot console (PID owned by Launch Services).');

        return self::SUCCESS;
    }

    private function godotAppLocatable(): bool
    {
        // Preferred probe: Spotlight metadata lookup by bundle identifier (D-07).
        // mdfind exits 0 even with no hits — empty stdout is the "not found" signal.
        $mdfind = $this->runShellCommand(['mdfind', "kMDItemCFBundleIdentifier == 'org.godotengine.godot'"]);
        if ($mdfind['exitCode'] === 0 && trim($mdfind['stdout']) !== '') {
            return true;
        }

        // Fallback probe: Launch Services bundle id resolution (D-07).
        $osascript = $this->runShellCommand(['osascript', '-e', 'id of app "Godot"']);

        return $osascript['exitCode'] === 0;
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
