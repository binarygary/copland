<?php

namespace App\Services;

use App\Config\GlobalConfig;
use App\Config\RepoConfig;
use App\Support\HomeDirectory;
use Throwable;

/**
 * Builds the unified Task Manifest consumed by the Godot console.
 *
 * Source of truth is the GitHub backlog (open issues matching each repo's
 * required labels, run through the same prefilter the runner uses), enriched
 * with the local run-store at ~/.copland/tasks/<slug>/<issue>/ so tasks Copland
 * has already acted on carry their live state, run count, and timestamps.
 *
 * The GitHub fetch + prefilter is cached to ~/.copland/issues-cache.json with a
 * TTL because the Godot home view polls every 2s; without the cache that poll
 * would storm the GitHub API (prefilter alone makes a per-issue timeline call).
 * The run-store is read fresh on every build so live run status is never stale.
 */
class TaskListService
{
    /** @var callable():GitHubService */
    private $githubFactory;

    public function __construct(
        private GlobalConfig $config,
        ?callable $githubFactory = null,
        private ?string $homeOverride = null,
        private int $cacheTtlSeconds = 120,
        /** @var callable():int */
        private $clock = null,
    ) {
        $this->githubFactory = $githubFactory ?? (fn (): GitHubService => new GitHubService);
        $this->clock = $clock ?? (fn (): int => time());
    }

    /**
     * @return array<int, array<string, mixed>> task rows in the shape the
     *                                          Godot TaskLoader expects
     */
    public function build(?string $onlyRepo = null, bool $forceRefresh = false): array
    {
        $cache = $this->readCache();
        $now = ($this->clock)();
        $dirty = false;
        $rows = [];

        foreach ($this->targetRepos($onlyRepo) as [$slug, $repoPath]) {
            $entry = $cache[$slug] ?? null;
            $fresh = is_array($entry)
                && ($now - (int) ($entry['generated_at'] ?? 0)) < $this->cacheTtlSeconds;

            if (! $forceRefresh && $fresh) {
                $classified = $entry;
            } else {
                try {
                    $classified = $this->classify($slug, $repoPath);
                    $classified['generated_at'] = $now;
                    $cache[$slug] = $classified;
                    $dirty = true;
                } catch (Throwable $e) {
                    // Network/auth failure: serve stale cache if we have it so
                    // the GUI keeps rendering the last known backlog.
                    $classified = $entry ?? ['accepted' => [], 'rejected' => []];
                }
            }

            foreach ($this->rowsForRepo($classified, $slug, $repoPath) as $row) {
                $rows[] = $row;
            }
        }

        if ($dirty) {
            $this->writeCache($cache);
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }

    /**
     * Resolve which repos to build for. With $onlyRepo, just that slug (path
     * looked up from config); otherwise every configured repo with a path.
     *
     * @return array<int, array{0:string,1:string}> [slug, repoPath] pairs
     */
    private function targetRepos(?string $onlyRepo): array
    {
        $out = [];
        foreach ($this->config->repos() as $repo) {
            if (is_array($repo)) {
                $slug = isset($repo['slug']) ? trim((string) $repo['slug']) : '';
                $path = isset($repo['path']) ? (string) $repo['path'] : '';
            } else {
                $slug = trim((string) $repo);
                $path = '';
            }

            if ($slug === '' || $path === '') {
                continue; // need a local path to load repo policy + run-store
            }

            if ($onlyRepo !== null && $slug !== $onlyRepo) {
                continue;
            }

            $out[] = [$slug, $path];
        }

        return $out;
    }

    /**
     * Fetch open issues for a repo and split them via the runner's prefilter.
     *
     * @return array{accepted: array<int, array<string, mixed>>, rejected: array<int, array{issue: array<string, mixed>, reason: string}>}
     */
    private function classify(string $slug, string $repoPath): array
    {
        $github = ($this->githubFactory)();
        $repoConfig = new RepoConfig($repoPath);

        $issues = $github->getIssues($slug, $repoConfig->requiredLabels());
        $result = (new IssuePrefilterService($repoConfig, $github, $slug))->filter($issues);

        return ['accepted' => $result->accepted, 'rejected' => $result->rejected];
    }

    /**
     * @param  array{accepted?: array, rejected?: array}  $classified
     * @return array<int, array<string, mixed>>
     */
    private function rowsForRepo(array $classified, string $slug, string $repoPath): array
    {
        $rows = [];

        foreach (($classified['accepted'] ?? []) as $issue) {
            $rows[] = $this->mergeStore($this->rowFromIssue($issue, $slug, $repoPath, 'new', null), $slug);
        }

        foreach (($classified['rejected'] ?? []) as $rejected) {
            $issue = $rejected['issue'] ?? [];
            $reason = (string) ($rejected['reason'] ?? '');
            $rows[] = $this->mergeStore($this->rowFromIssue($issue, $slug, $repoPath, 'blocked', $reason), $slug);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function rowFromIssue(array $issue, string $slug, string $repoPath, string $baseState, ?string $reason): array
    {
        $number = (int) ($issue['number'] ?? 0);
        $body = trim((string) ($issue['body'] ?? ''));
        $created = $this->formatTimestamp((string) ($issue['created_at'] ?? ''));

        return [
            'id' => $slug.'#'.$number,
            'title' => (string) ($issue['title'] ?? ''),
            'repo' => $slug,
            'repo_path' => $repoPath,
            'state' => $baseState,
            'issue' => '#'.$number,
            'branch' => '',
            'files_to_change' => [],
            // Rejected issues lead with the prefilter reason; accepted issues
            // show the issue body (the closest thing to a plan summary we have
            // before a run produces one).
            'summary' => $reason !== null && $reason !== '' ? $reason : $body,
            'created' => $created,
            'updated' => $created,
            'runs_count' => 0,
            'task_dir' => '',
            'is_real' => true,
            'url' => (string) ($issue['html_url'] ?? ''),
        ];
    }

    /**
     * Overlay run-store data (live state, timestamps, run count) for issues
     * Copland has already worked. Keyed by issue number, matching the dir the
     * TaskDirectoryWriterService writes: tasks/<slug-with-/-as-__>/<number>/.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mergeStore(array $row, string $slug): array
    {
        $number = ltrim((string) $row['issue'], '#');
        $dir = $this->taskStoreDir($slug, $number);
        $statusFm = $this->readFrontmatter($dir.'/status.md');

        if ($statusFm === []) {
            return $row; // not yet acted on — leave the GitHub-derived row
        }

        $taskFm = $this->readFrontmatter($dir.'/task.md');

        $row['state'] = $statusFm['state'] ?? $row['state'];
        $row['updated'] = $this->formatTimestamp((string) ($statusFm['updated_at'] ?? $row['updated']));
        $row['runs_count'] = $this->countRuns($dir.'/runs');
        $row['task_dir'] = $dir;
        // Surface the latest run's failure reason so the GUI can explain a
        // blocked/failed task instead of just showing the state.
        $row['failure_reason'] = $this->latestRunFailureReason($dir.'/runs');
        if (! empty($taskFm['title'])) {
            $row['title'] = (string) $taskFm['title'];
        }

        return $row;
    }

    /**
     * Read the failure_reason from the most recent run's outcome.md, or '' when
     * there is none. Run IDs are ISO-8601 timestamps, so a lexicographic sort is
     * chronological and the last entry is the latest run.
     */
    private function latestRunFailureReason(string $runsDir): string
    {
        if (! is_dir($runsDir)) {
            return '';
        }

        $runs = [];
        foreach (scandir($runsDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($runsDir.'/'.$entry)) {
                $runs[] = $entry;
            }
        }

        if ($runs === []) {
            return '';
        }

        sort($runs);
        $latest = (string) end($runs);
        $outcome = $this->readFrontmatter($runsDir.'/'.$latest.'/outcome.md');

        return (string) ($outcome['failure_reason'] ?? '');
    }

    private function taskStoreDir(string $slug, string $taskId): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();
        $repoDir = str_replace('/', '__', $slug);

        return "{$home}/.copland/tasks/{$repoDir}/{$taskId}";
    }

    private function countRuns(string $runsDir): int
    {
        if (! is_dir($runsDir)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($runsDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($runsDir.'/'.$entry)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Minimal top-level scalar frontmatter reader for status.md / task.md.
     *
     * @return array<string, string>
     */
    private function readFrontmatter(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        $out = [];
        $inFrontmatter = false;
        $seenOpen = false;

        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($file)) as $line) {
            $stripped = trim($line);

            if ($stripped === '---') {
                if (! $seenOpen) {
                    $seenOpen = true;
                    $inFrontmatter = true;

                    continue;
                }
                break;
            }

            if (! $inFrontmatter) {
                // status.md has no leading "---"; treat leading scalar lines as
                // frontmatter until a blank line.
                if ($stripped === '') {
                    if ($seenOpen) {
                        break;
                    }

                    continue;
                }
                $seenOpen = true;
                $inFrontmatter = true;
            }

            if ($stripped === '') {
                break;
            }

            $colon = strpos($stripped, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }

            $key = trim(substr($stripped, 0, $colon));
            $val = trim(substr($stripped, $colon + 1));
            $val = trim($val, "'\"");
            $out[$key] = $val;
        }

        return $out;
    }

    private function formatTimestamp(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Normalize ISO-8601 ("2026-05-15T06:04:12Z") to "2026-05-15 06:04".
        return substr(str_replace('T', ' ', $value), 0, 16);
    }

    private function cacheFile(): string
    {
        $home = $this->homeOverride ?? HomeDirectory::resolve();

        return "{$home}/.copland/issues-cache.json";
    }

    /**
     * @return array<string, mixed>
     */
    private function readCache(): array
    {
        $file = $this->cacheFile();
        if (! is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $cache
     */
    private function writeCache(array $cache): void
    {
        $file = $this->cacheFile();
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $tmp = $file.'.tmp';
        if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $file);
        }
    }
}
