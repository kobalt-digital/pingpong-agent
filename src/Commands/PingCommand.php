<?php

namespace KobaltDigital\PingPong\Commands;

use Illuminate\Console\Command;
use KobaltDigital\PingPong\Actions\SendTick;
use KobaltDigital\PingPong\Transport;

class PingCommand extends Command
{
    protected $signature = 'pingpong:ping';

    protected $description = 'Tell PingPong that the scheduler of this app is alive';

    /**
     * Always succeeds: a PingPong outage should not show up as a failing task
     * in the host app. PingPong notices the missing tick by itself.
     */
    public function handle(Transport $transport, SendTick $sendTick): int
    {
        if (! $transport->isConfigured()) {
            $this->components->warn('No PINGPONG_KEY set, nothing sent.');

            return self::SUCCESS;
        }

        $this->components->info('Sending tick to PingPong...');

        $sendTick->execute()
            ? $this->components->info('Tick delivered.')
            : $this->components->warn('Tick not delivered, see the log.');

        return self::SUCCESS;
    }
}
