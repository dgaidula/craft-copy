<?php

namespace fortrabbit\Copy\Services;

use fortrabbit\Copy\Plugin;
use GitWrapper\Exception\GitException;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;

/**
 * Git Service
 */
final class Git
{

    /**
     * @var \GitWrapper\GitWorkingCopy
     */
    protected $gitWorkingCopy;

    private function __construct(GitWorkingCopy $gitWorkingCopy)
    {
        $this->gitWorkingCopy = $gitWorkingCopy;
    }


    /**
     * Directory Factory
     *
     * @param string $directory Path to the directory containing the working copy.
     *
     * @return \fortrabbit\Copy\Services\Git
     */
    public static function fromDirectory(string $directory)
    {
        $wrapper = new GitWrapper();
        $wrapper->setTimeout(300);

        return new Git($wrapper->workingCopy($directory));
    }

    /**
     * Clone Factory
     *
     * @param string $repository The Git URL of the repository being cloned.
     * @param string $directory The directory that the repository will be cloned into.
     * @param mixed[] $options An associative array of command line options.
     *
     * @return \fortrabbit\Copy\Services\Git
     */
    public static function fromClone(string $repository, ?string $directory = null, array $options = [])
    {
        $wrapper = new GitWrapper();
        $wrapper->setTimeout(300);

        return new Git($wrapper->cloneRepository($repository, $directory, $options));
    }

    /**
     * @param string $upstream
     * @param string $branch
     *
     * @return string
     */
    public function push(string $upstream, string $branch = 'master'): string
    {
        return $this->gitWorkingCopy->push($upstream, $branch);
    }

    /**
     * @param string $upstream
     * @param string $branch
     *
     * @return string
     */
    public function pull(string $upstream, string $branch = 'master'): string
    {
        return $this->gitWorkingCopy->pull($upstream, $branch);
    }

    /**
     * @return null|string
     */
    public function getLocalHead(): ?string
    {
        foreach ($this->getLocalBranches() as $key => $name) {
            if (stristr($name, '*')) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function getLocalBranches(): array
    {
        $localBranches = [];
        foreach (explode(PHP_EOL, trim($this->gitWorkingCopy->run('branch'))) as $branch) {
            $localBranches[trim(ltrim($branch, '*'))] = $branch;
        };

        return $localBranches;
    }

    /**
     * @param null|string $for 'push' or 'pull'
     *
     * @return array
     */
    public function getRemotes(?string $for = 'push'): array
    {
        if (!in_array($for, ['push', 'pull'])) {
            throw new \LogicException(
                sprintf(
                    'Argument 1 passed to %s must be "pull" or "push", %s given.',
                    'fortrabbit\Copy\Services\Git::getRemotes()',
                    $for
                )
            );
        }

        try {
            $remotes = $this->gitWorkingCopy->getRemotes();
        } catch (GitException $e) {
            return [];
        }

        foreach ($remotes as $name => $upstreams) {
            $remotes[$name] = $upstreams[$for];
        }

        return $remotes;
    }

    /**
     * Returns remote tracking upstream/branch for HEAD.
     *
     * @param bool $includeBranch
     *
     * @return null|string
     */
    public function getTracking($includeBranch = false): ?string
    {
        try {
            $result = $this->run('rev-parse', '@{u}', ['abbrev-ref' => true, 'symbolic-full-name' => true]);
        } catch (GitException $gitException) {
            return null;
        }

        if ($includeBranch) {
            return $result;
        }

        // Split upstream/branch and return upstream only
        return explode('/', $result)[0];
    }

    /**
     * @param string $command
     * @param array ...$argsAndOptions
     *
     * @return string
     */
    public function run(string $command, ...$argsAndOptions): string
    {
        return $this->gitWorkingCopy->run($command, $argsAndOptions);
    }

    /**
     * @param string $sshRemote
     *
     * @return string $app Name of the remote
     */
    public function addRemote(string $sshRemote)
    {
        if (!stristr($sshRemote, 'frbit.com')) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Wrong $sshRemote must follow this pattern {app}@deploy.{region}.frbit.com, %s given.',
                    $sshRemote
                )
            );
        }

        $app = explode('@', $sshRemote)[0];
        $this->getWorkingCopy()->addRemote($app, "{$sshRemote}:{$app}.git");

        return $app;
    }

    /**
     * @return \GitWrapper\GitWorkingCopy
     */
    public function getWorkingCopy(): GitWorkingCopy
    {
        return $this->gitWorkingCopy;
    }

    /**
     * Create .gitignore or adjust the existing
     *
     * @return bool
     * @throws \Exception
     */
    public function assureDotGitignore()
    {
        $path = $this->getWorkingCopy()->getDirectory();
        $gitignoreFile = "$path/.gitignore";
        $gitignoreExampleFile = Plugin::PLUGIN_ROOT_PATH . "/.gitignore.example";

        if (!file_exists($gitignoreExampleFile)) {
            throw new \Exception("Unable to read .gitignore.example.");
        }

        if (!file_exists($gitignoreFile)) {
            return copy($gitignoreExampleFile, $gitignoreFile);
        }

        return true;
    }
}
