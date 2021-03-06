<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use App\Console\Commands\Helpers\CommandExecutor;
use Symfony\Component\Console\Output\OutputInterface;
use App\Console\Commands\Helpers\CommandExecutorInterface;

class SentryRelease extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sentry:release
                            {--release= : release version for sentry}
                            {--store-release : store release version in .sentry-release file}
                            {--commit= : commit associated with this release}
                            {--environment= : sentry environment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a release for sentry';

    /**
     * Installation path of sentry cli.
     *
     * @var string
     */
    private $install_dir;

    /**
     * sentry cli name.
     *
     * @var string
     */
    private const SENTRY_CLI = 'sentry-cli';

    /**
     * Sentry cli download url.
     *
     * @var string
     */
    private const SENTRY_URL = 'https://sentry.io/get-cli/';

    /**
     * The Command Executor.
     *
     * @var CommandExecutorInterface
     */
    public $commandExecutor;

    /**
     * Create a new command.
     *
     * @param CommandExecutorInterface
     */
    public function __construct()
    {
        $this->commandExecutor = new CommandExecutor($this);
        $this->install_dir = getenv('HOME').'/.local/bin';
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed() || ! config('monica.sentry_support') || ! $this->check()) {
            return;
        }

        $release = $this->option('release');
        $commit = $this->option('commit') ??
                  (is_dir(__DIR__.'/../../../.git') ? trim(exec('git log --pretty="%H" -n1 HEAD')) : $this->option('release'));

        // Sentry update
        $this->commandExecutor->exec('Update sentry', $this->getSentryCli().' update');

        // Create a release
        $this->execSentryCli('Create a release', 'releases new '.$release.' --finalize --project '.config('sentry.project'));

        // Associate commits with the release
        $this->execSentryCli('Associate commits with the release', 'releases set-commits '.$release.' --commit "'.config('sentry.repo').'@'.$commit.'"');

        // Create a deploy
        $this->execSentryCli('Create a deploy', 'releases deploys '.$release.' new --env '.$this->option('environment').' --name '.config('monica.app_version'));

        if ($this->option('store-release')) {
            // Set sentry release
            $this->line('Store release in .sentry-release file', OutputInterface::VERBOSITY_VERBOSE);
            file_put_contents(__DIR__.'/../../../.sentry-release', $this->option('release'));
        }
    }

    private function check() : bool
    {
        $check = true;
        if (empty(config('sentry.auth_token'))) {
            $this->error('You must provide an auth_token (SENTRY_AUTH_TOKEN)');
            $check = false;
        }
        if (empty(config('sentry.organisation'))) {
            $this->error('You must provide an organisation slug (SENTRY_ORG)');
            $check = false;
        }
        if (empty(config('sentry.project'))) {
            $this->error('You must set the project (SENTRY_PROJECT)');
            $check = false;
        }
        if (empty(config('sentry.repo'))) {
            $this->error('You must set the repository (SENTRY_REPO)');
            $check = false;
        }
        if (empty($this->option('release'))) {
            $this->error('No release given');
            $check = false;
        }
        if (empty($this->option('environment'))) {
            $this->error('No environment given');
            $check = false;
        }

        return $check;
    }

    private function getSentryCli()
    {
        if (! file_exists($this->install_dir.'/'.self::SENTRY_CLI)) {
            mkdir($this->install_dir, 0777, true);
            $this->commandExecutor->exec('Downloading sentry-cli', 'curl -sL '.self::SENTRY_URL.' | INSTALL_DIR='.$this->install_dir.' bash');
        }

        return $this->install_dir.'/'.self::SENTRY_CLI;
    }

    private function execSentryCli($message, $command)
    {
        $this->commandExecutor->exec($message, $this->getSentryCli().' '.$command);
    }
}
