<?php

use App\Data\PlanResult;
use App\Support\PlanArtifactStore;

it('writes the latest plan artifact under the global copland runs directory', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-plan-artifacts-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $store = new PlanArtifactStore;
    $path = $store->save('Lone-Rock-Point/lrpbot', [
        'number' => 193,
        'title' => 'Fix repo toggle',
        'html_url' => 'https://github.com/Lone-Rock-Point/lrpbot/issues/193',
    ], new PlanResult(
        decision: 'plan',
        branchName: 'agent/issue-193',
        filesToRead: ['resources/js/app.js'],
        filesToChange: ['resources/js/app.js'],
        steps: ['Update toggle state'],
        commandsToRun: ['./vendor/bin/pest'],
        testsToUpdate: [],
        successCriteria: ['Toggle state is independent'],
        guardrails: [],
        prTitle: 'Fix repo toggle',
        prBody: 'Body',
        maxFilesChanged: 3,
        maxLinesChanged: 250,
        declineReason: null,
    ), ['command not allowed']);

    expect($path)->toBe($home.'/.copland/runs/Lone-Rock-Point__lrpbot/last-plan.json');
    expect(file_exists($path))->toBeTrue();

    $json = json_decode((string) file_get_contents($path), true);

    expect($json['repo'])->toBe('Lone-Rock-Point/lrpbot');
    expect($json['issue']['number'])->toBe(193);
    expect($json['plan']['branch_name'])->toBe('agent/issue-193');
    expect($json['validation_errors'])->toBe(['command not allowed']);

    $_SERVER['HOME'] = $originalHome;
});

it('archives the previous last plan by issue number when a different issue is saved', function () {
    $originalHome = $_SERVER['HOME'] ?? null;
    $home = sys_get_temp_dir().'/copland-plan-artifacts-'.uniqid();
    mkdir($home, 0755, true);
    $_SERVER['HOME'] = $home;

    $store = new PlanArtifactStore;

    $plan = new PlanResult(
        decision: 'plan',
        branchName: 'agent/issue-193',
        filesToRead: [],
        filesToChange: ['resources/js/app.js'],
        steps: ['Update toggle state'],
        commandsToRun: ['./vendor/bin/pest'],
        testsToUpdate: [],
        successCriteria: ['Toggle state is independent'],
        guardrails: [],
        prTitle: 'Fix repo toggle',
        prBody: 'Body',
        maxFilesChanged: 3,
        maxLinesChanged: 250,
        declineReason: null,
    );

    $store->save('Lone-Rock-Point/lrpbot', ['number' => 193, 'title' => 'First'], $plan);
    $store->save('Lone-Rock-Point/lrpbot', ['number' => 194, 'title' => 'Second'], $plan);

    $directory = $home.'/.copland/runs/Lone-Rock-Point__lrpbot';
    $archived = $directory.'/issue-193.json';
    $last = $directory.'/last-plan.json';

    expect(file_exists($archived))->toBeTrue();
    expect(json_decode((string) file_get_contents($archived), true)['issue']['number'])->toBe(193);
    expect(json_decode((string) file_get_contents($last), true)['issue']['number'])->toBe(194);

    $_SERVER['HOME'] = $originalHome;
});
