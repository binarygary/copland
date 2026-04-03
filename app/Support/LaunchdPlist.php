<?php

namespace App\Support;

class LaunchdPlist
{
    public function label(): string
    {
        return 'com.binarygary.copland';
    }

    public function plistFilename(): string
    {
        return $this->label().'.plist';
    }

    public function plistPath(string $home): string
    {
        return rtrim($home, '/').'/Library/LaunchAgents/'.$this->plistFilename();
    }

    public function stdoutPath(string $home): string
    {
        return rtrim($home, '/').'/.copland/logs/launchd/stdout.log';
    }

    public function stderrPath(string $home): string
    {
        return rtrim($home, '/').'/.copland/logs/launchd/stderr.log';
    }

    public function build(
        string $home,
        string $projectRoot,
        string $phpBinary,
        string $path,
        int $hour,
        int $minute,
    ): string {
        $label = $this->label();
        $coplandBinary = rtrim($projectRoot, '/').'/copland';

        $entries = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">',
            '<plist version="1.0">',
            '<dict>',
            '    <key>Label</key>',
            '    <string>'.$this->escape($label).'</string>',
            '    <key>WorkingDirectory</key>',
            '    <string>'.$this->escape(rtrim($projectRoot, '/')).'</string>',
            '    <key>ProgramArguments</key>',
            '    <array>',
            '        <string>'.$this->escape($phpBinary).'</string>',
            '        <string>'.$this->escape($coplandBinary).'</string>',
            '        <string>run</string>',
            '    </array>',
            '    <key>StartCalendarInterval</key>',
            '    <dict>',
            '        <key>Hour</key>',
            '        <integer>'.$hour.'</integer>',
            '        <key>Minute</key>',
            '        <integer>'.$minute.'</integer>',
            '    </dict>',
            '    <key>EnvironmentVariables</key>',
            '    <dict>',
            '        <key>HOME</key>',
            '        <string>'.$this->escape(rtrim($home, '/')).'</string>',
            '        <key>PATH</key>',
            '        <string>'.$this->escape($path).'</string>',
            '    </dict>',
            '    <key>StandardOutPath</key>',
            '    <string>'.$this->escape($this->stdoutPath($home)).'</string>',
            '    <key>StandardErrorPath</key>',
            '    <string>'.$this->escape($this->stderrPath($home)).'</string>',
            '    <key>RunAtLoad</key>',
            '    <false/>',
            '</dict>',
            '</plist>',
            '',
        ];

        return implode(PHP_EOL, $entries);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
