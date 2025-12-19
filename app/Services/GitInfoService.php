<?php

namespace App\Services;

/**
 * Service to retrieve Git information from various sources.
 */
class GitInfoService
{
    /**
     * Get git information from various sources.
     */
    public function getGitInfo(): array
    {
        // Try to read from .git-info file first (for Docker containers)
        $gitInfoFile = base_path('.git-info');
        if (file_exists($gitInfoFile)) {
            return $this->parseGitInfoFile($gitInfoFile);
        }

        // Fallback to environment variables
        if (env('GIT_BRANCH') || env('GIT_COMMIT') || env('GIT_TAG')) {
            return [
                'source' => 'environment',
                'branch' => env('GIT_BRANCH'),
                'commit' => env('GIT_COMMIT'),
                'tag' => env('GIT_TAG'),
                'build_date' => null,
            ];
        }

        // Try to get from git commands (if .git directory exists)
        if (is_dir(base_path('.git'))) {
            return $this->getFromGitCommands();
        }

        return [
            'source' => 'none',
            'branch' => 'master', // default to master
            'commit' => null,
            'tag' => null,
            'build_date' => null,
        ];
    }

    /**
     * Get the current branch name.
     */
    public function getBranch(): ?string
    {
        return $this->getGitInfo()['branch'];
    }

    /**
     * Get the current commit hash.
     */
    public function getCommit(): ?string
    {
        return $this->getGitInfo()['commit'];
    }

    /**
     * Get the current tag (if any).
     */
    public function getTag(): ?string
    {
        return $this->getGitInfo()['tag'];
    }

    /**
     * Get the build date.
     */
    public function getBuildDate(): ?string
    {
        return $this->getGitInfo()['build_date'];
    }

    /**
     * Check if running in a specific branch.
     */
    public function isOnBranch(string $branch): bool
    {
        return $this->getBranch() === $branch;
    }

    /**
     * Check if running in production (master/main branch or tagged release).
     */
    public function isProduction(): bool
    {
        $branch = $this->getBranch();
        $tag = $this->getTag();
        
        return in_array($branch, ['master', 'main']) || !empty($tag);
    }

    /**
     * Parse the .git-info file created during Docker build.
     */
    private function parseGitInfoFile(string $filePath): array
    {
        $gitInfo = [
            'source' => 'docker',
            'branch' => null,
            'commit' => null,
            'tag' => null,
            'build_date' => null,
        ];

        $content = file_get_contents($filePath);
        $lines = explode("\n", trim($content));

        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = strtolower($key);
                
                switch ($key) {
                    case 'git_branch':
                        $gitInfo['branch'] = $value ?: null;
                        break;
                    case 'git_commit':
                        $gitInfo['commit'] = $value ?: null;
                        break;
                    case 'git_tag':
                        $gitInfo['tag'] = $value ?: null;
                        break;
                    case 'build_date':
                        $gitInfo['build_date'] = $value ?: null;
                        break;
                }
            }
        }

        return $gitInfo;
    }

    /**
     * Get git information from git commands.
     */
    private function getFromGitCommands(): array
    {
        $basePath = base_path();
        
        $branch = trim(shell_exec("cd {$basePath} && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');
        $commit = trim(shell_exec("cd {$basePath} && git rev-parse HEAD 2>/dev/null") ?: '');
        $tag = trim(shell_exec("cd {$basePath} && git describe --tags --exact-match 2>/dev/null") ?: '');

        return [
            'source' => 'git',
            'branch' => $branch ?: null,
            'commit' => $commit ?: null,
            'tag' => $tag ?: null,
            'build_date' => null,
        ];
    }
}
