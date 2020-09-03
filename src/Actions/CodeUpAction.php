<?php

namespace fortrabbit\Copy\Actions;

use fortrabbit\Copy\Services\Git;
use GitWrapper\Exception\GitException;
use yii\console\ExitCode;

class CodeUpAction extends StageAwareBaseAction
{

    /**
     * Git push
     *
     * @param string|null $stage Name of the stage config
     *
     * @return int
     * @throws \Exception
     */
    public function run(string $stage = null)
    {
        $this->head(
            "Deploy recent code changes",
            "<comment>{$this->stage}</comment> {$this->stage->app}.frb.io"
        );

        $git = $this->plugin->git;
        $git->getWorkingCopy()->init();
        $git->assureDotGitignore();

        $localBranches = $git->getLocalBranches();
        $branch        = $git->getLocalHead();

        if (count($localBranches) > 1) {
            $question = 'Select a local branch (checkout):';
            $branch = str_replace('* ', '', $this->choice($question, $localBranches, $branch));
            $git->run('checkout', $branch);
        }

        // Ask for remote
        // or create one
        // or pick the only one
        if (!$upstream = $this->getUpstream($git)) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        //$git->getWorkingCopy()->fetch($upstream);

        try {
            if ($log = $git->getWorkingCopy()->log("--format=(%h) %cr: %s ", "$upstream/master..HEAD")) {
                $this->noteBlock("Recent changes:" . PHP_EOL . trim($log));
            }
        } catch (\Exception $e) {
        }


        if (!$git->getWorkingCopy()->hasChanges()) {
            if (!$this->confirm("About to push latest commits, proceed?", true)) {
                return ExitCode::OK;
            }
        }


        if ($status = $git->getWorkingCopy()->getStatus()) {
            // Changed files
            $this->noteBlock("Uncommitted changes:" . PHP_EOL . $status);
            $defaultMessage = ($this->interactive) ? null : 'init Craft';

            if (!$msg = $this->ask("Enter a commit message, or leave it empty to abort the commit", $defaultMessage)) {
                $this->errorBlock('Abort');

                return ExitCode::UNSPECIFIED_ERROR;
            }

            // Add and commit
            $git->getWorkingCopy()->add('.');
            $git->getWorkingCopy()->commit($msg);
        } else {
            $msg = 'empty commit';
        }

        // Run 'before' commands and stop on error
        if (!$this->runBeforeDeployCommands()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $this->section("git push ($msg)");
            $git->getWorkingCopy()->getWrapper()->streamOutput();
            $git->push($upstream, 'master');
        } catch (GitException $exception) {
            $lines = count(explode(PHP_EOL, $exception->getMessage()));
            $this->output->write(str_repeat("\x1B[1A\x1B[2K", $lines));
            $this->errorBlock('Ooops.');
            $this->output->write("<fg=red>{$exception->getMessage()}</>");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->output->write(PHP_EOL);
        $this->successBlock('Code deployed successfully.');

        return ExitCode::OK;
    }


    /**
     * Dialog helper to choose the Remote
     *
     * @param \fortrabbit\Copy\Services\Git $git
     *
     * @return string upstream
     */
    protected function getUpstream(Git $git): string
    {
        // Get configured remote & sshUrl
        $upstream = explode('/', $this->stage->gitRemote)[0];
        $sshUrl = $this->stage->sshUrl;

        // Nothing found
        if (!$remotes = $git->getRemotes()) {
            if ($this->confirm("No git remotes configured. Do you want to add '{$sshUrl}'?")) {
                return $git->addRemote($sshUrl);
            }
        }

        // Auto setup
        if (!array_key_exists($upstream, $remotes)) {
            $git->addRemote($sshUrl);
            $remotes = $git->getRemotes();
        }

        // Just one
        if (count($remotes) == 1) {
            return array_keys($remotes)[0];
        }

        // return the configured upstream
        return $upstream;
    }
}
