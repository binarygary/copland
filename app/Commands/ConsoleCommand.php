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
        private $osFamilyResolver = null,
    ) {
        parent::__construct();

        $this->projectRootResolver ??= static fn (): string => base_path();
        $this->projectFileChecker ??= static fn (string $path): bool => file_exists($path);
        $this->osFamilyResolver ??= static fn (): string => PHP_OS_FAMILY;
    }

    public function handle(): int
    {
        // Preflight #0: macOS-only. `open`, `mdfind`, and `osascript` are Darwin
        // tools; failing here with a clear message beats a misleading
        // "Godot.app not found" on Linux/Windows. (Phase 19 D-04/D-05.)
        if (($this->osFamilyResolver)() !== 'Darwin') {
            $this->error('copland console is macOS-only — the launcher shells out to `open`, `mdfind`, and `osascript`. (Phase 19 D-04.)');

            return self::FAILURE;
        }

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
        //
        // --copland-bin is passed so the Godot side (Main.gd / Config.gd) can
        // resolve the binary via OS.get_cmdline_args() without falling back to
        // $COPLAND_BIN or `which copland`. v2.1 ships from the dev repo, so
        // base_path() . '/copland' always exists; production-install fallback
        // (`which copland` inside ConsoleCommand) is deferred (Phase 24 T8).
        $coplandBinPath = $projectRoot.'/copland';
        $result = $this->runShellCommand([
            'open', '-a', 'Godot', '--args',
            '--path', $godotProjectDir,
            '--copland-bin', $coplandBinPath,
        ]);

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
