<?php

namespace App\Commands;

use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Services\CurrentRepoGuardService;
use App\Services\GitHubService;
use App\Services\IssuePrefilterService;
use App\Support\ProgressReporter;
use LaravelZero\Framework\Commands\Command;

class IssuesCommand extends Command
{
    protected $signature = 'issues {repo? : GitHub repo in owner/repo format}';

    protected $description = 'Fetch and display candidate issues for a repo';

    public function handle(): void
    {
        $progress = new ProgressReporter(totalSteps: 4);

        $this->line($progress->step('Resolve repository'));
        $repo = (new CurrentRepoGuardService())->resolve($this->argument('repo'));
        $this->line($progress->detail("Using repo {$repo}"));

        $github = new GitHubService();

        $this->line($progress->step('Verify GitHub authentication'));
        try {
            $github->ping();
            $this->line($progress->detail('GitHub auth OK'));
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return;
        }

        $this->line($progress->step('Load repo policy and fetch issues'));
        $repoConfig = new RepoConfig(getcwd());
        $issues = $github->getIssues($repo, $repoConfig->requiredLabels());
        $this->line($progress->detail('Fetched ' . count($issues) . ' open issues matching required labels'));

        $this->line($progress->step('Prefilter candidate issues'));
        $prefilter = new IssuePrefilterService($repoConfig, $github, $repo);
        $result = $prefilter->filter($issues);
        $this->line($progress->detail(count($result->accepted) . ' accepted, ' . count($result->rejected) . ' rejected'));

        $this->line('');
        $this->line('Accepted issues:');
        $this->table(
            ['#', 'Title', 'Labels'],
            array_map(fn($i) => [
                $i['number'],
                $i['title'],
                implode(', ', array_map(fn($l) => $l['name'], $i['labels'] ?? [])),
            ], $result->accepted)
        );

        $this->line('');
        $this->line('Rejected issues:');
        $this->table(
            ['#', 'Title', 'Reason'],
            array_map(fn($r) => [
                $r['issue']['number'],
                $r['issue']['title'],
                $r['reason'],
            ], $result->rejected)
        );
    }
}
