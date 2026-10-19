<?php

namespace Postingher\MonitorAgent\Console;

use Illuminate\Console\Command;
use Postingher\MonitorAgent\HttpTransport;
use Postingher\MonitorAgent\MonitorAgentServiceProvider;
use Postingher\MonitorAgent\Support\Timestamp;

class DeployCommand extends Command
{
    protected $signature = 'monitor:deploy {--ref= : The commit SHA or tag being deployed}';

    protected $description = 'Mark a deploy in the Monitor panel';

    /**
     * Send the deploy marker right away, without waiting for a batch.
     */
    public function handle(HttpTransport $transport): int
    {
        $ref = trim((string) $this->option('ref'));

        if ($ref === '') {
            $this->error('The --ref option is required, for example --ref=$GITHUB_SHA.');

            return self::FAILURE;
        }

        if (! $this->laravel->getProvider(MonitorAgentServiceProvider::class)?->isEnabled()) {
            $this->info('Monitor agent is disabled; no deploy sent.');

            return self::SUCCESS;
        }

        $transport->send([['type' => 'deploy', 'deployed_at' => Timestamp::now(), 'ref' => mb_strcut($ref, 0, 100)]]);
        $this->info("Deploy {$ref} sent to Monitor.");

        return self::SUCCESS;
    }
}
