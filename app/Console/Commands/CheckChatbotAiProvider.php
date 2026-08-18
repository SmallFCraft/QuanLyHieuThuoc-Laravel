<?php

namespace App\Console\Commands;

use App\Services\AiProviderService;
use Illuminate\Console\Command;
use RuntimeException;

class CheckChatbotAiProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:ai-check {--vision : Also send an image request to each provider}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check every configured chatbot AI provider (primary and fallback) with a live request';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $service = app(AiProviderService::class);
        } catch (RuntimeException $exception) {
            $this->error('AI provider is not configured: ' . $exception->getMessage());
            $this->line('Set AI_BASE_URL, AI_API_KEY and AI_MODEL in .env, then run: php artisan config:clear');

            return Command::FAILURE;
        }

        $withVision = (bool) $this->option('vision');
        $providers = $service->describeProviders();
        $rows = [];
        $anyHealthy = false;

        $this->line(sprintf(
            'Sending %s live request(s) per provider - this consumes real credits.',
            $withVision ? 'a chat and a vision' : 'one chat'
        ));

        foreach ($providers as $provider) {
            $label = $provider['index'] === 0 ? 'primary' : 'fallback #' . $provider['index'];
            $probe = $service->probeProvider($provider['index'], false);
            $anyHealthy = $anyHealthy || $probe['ok'];

            $rows[] = [
                $label,
                $provider['mode'],
                $provider['base_url'],
                $provider['model'],
                $provider['has_key'] ? 'yes' : 'MISSING',
                $probe['ok'] ? 'OK' : 'FAILED',
                $probe['duration_ms'] . ' ms',
                $probe['error'] ?? '-',
            ];

            if ($withVision) {
                $visionProbe = $service->probeProvider($provider['index'], true);

                $rows[] = [
                    $label . ' (vision)',
                    $provider['mode'],
                    $provider['base_url'],
                    $provider['model'],
                    $provider['has_key'] ? 'yes' : 'MISSING',
                    $visionProbe['ok'] ? 'OK' : 'FAILED',
                    $visionProbe['duration_ms'] . ' ms',
                    $visionProbe['error'] ?? '-',
                ];
            }
        }

        $this->table(
            ['Provider', 'Mode', 'Base URL', 'Model', 'API key', 'Chat', 'Latency', 'Error'],
            $rows
        );

        if (count($providers) === 1) {
            $this->warn('No fallback provider configured. Set AI_FALLBACK_BASE_URL, AI_FALLBACK_API_KEY and AI_FALLBACK_MODEL to keep the chatbot working when the primary provider is down.');
        }

        if (! $anyHealthy) {
            $this->error('No AI provider answered. The chatbot will reply with its offline fallback text until one recovers.');

            return Command::FAILURE;
        }

        $this->info('At least one AI provider is reachable.');

        return Command::SUCCESS;
    }
}
