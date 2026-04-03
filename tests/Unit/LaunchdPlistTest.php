<?php

use App\Support\LaunchdPlist;

it('builds a user launch agent plist with explicit env vars and nightly schedule', function () {
    $plist = new LaunchdPlist;

    $xml = $plist->build(
        home: '/Users/tester',
        projectRoot: '/Users/tester/projects/copland',
        phpBinary: '/opt/homebrew/bin/php',
        path: '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
        hour: 2,
        minute: 30,
    );

    expect($plist->plistPath('/Users/tester'))->toBe('/Users/tester/Library/LaunchAgents/com.binarygary.copland.plist');
    expect($xml)->toContain('<key>Label</key>');
    expect($xml)->toContain('<string>com.binarygary.copland</string>');
    expect($xml)->toContain('<key>StartCalendarInterval</key>');
    expect($xml)->toContain('<integer>2</integer>');
    expect($xml)->toContain('<integer>30</integer>');
    expect($xml)->toContain('<key>HOME</key>');
    expect($xml)->toContain('<string>/Users/tester</string>');
    expect($xml)->toContain('<key>PATH</key>');
    expect($xml)->toContain('<string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>');
    expect($xml)->toContain('<string>/opt/homebrew/bin/php</string>');
    expect($xml)->toContain('<string>/Users/tester/projects/copland/copland</string>');
    expect($xml)->toContain('<string>run</string>');
    expect($xml)->toContain('<string>/Users/tester/.copland/logs/launchd/stdout.log</string>');
    expect($xml)->toContain('<string>/Users/tester/.copland/logs/launchd/stderr.log</string>');
});
