<?php

namespace App\Command;

use App\Service\TwitterClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-twitter-rate-limits',
    description: 'Vérifie les limites de taux de l\'API Twitter'
)]
class CheckTwitterRateLimitsCommand extends Command
{
    public function __construct(
        private TwitterClient $twitterClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🐦 Vérification des Rate Limits Twitter API');

        try {
            // Récupérer les rate limits Bearer Token (lecture)
            $rateLimits = $this->twitterClient->getRateLimits();
            
            if (empty($rateLimits)) {
                $io->warning('Aucune information de rate limit récupérée.');
                return Command::SUCCESS;
            }

            // Afficher les informations
            $this->displayRateLimits($io, $rateLimits);
            
            // Ajouter un avertissement sur les rate limits d'écriture
            $io->section('⚠️ Note importante');
            $io->text([
                'Les rate limits affichés ci-dessus concernent principalement la lecture (Bearer Token).',
                'Les limites de publication de tweets (OAuth 1.0a) sont séparées et ne peuvent pas être',
                'vérifiées facilement via l\'API.',
                '',
                'Limites typiques de publication :',
                '• Plan gratuit (Free) : ~16-17 tweets par 24h (500/mois)',
                '• Plan Basic : ~100 tweets par 24h',
                '• Plan payant : 300 tweets par 15 minutes',
                '',
                'Si vous obtenez une erreur HTTP 429 lors de la publication :',
                '• Plan gratuit/Basic : Attendez 24h',
                '• Plan payant : Attendez 15 minutes'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('❌ Erreur lors de la vérification des rate limits : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayRateLimits(SymfonyStyle $io, array $rateLimits, string $authType = ''): void
    {
        $io->section('📊 État actuel des Rate Limits');

        $tableRows = [];
        $hasWarnings = false;

        foreach ($rateLimits as $endpoint => $limits) {
            $limit = $limits['limit'] ?? 0;
            $remaining = $limits['remaining'] ?? 0;
            $reset = $limits['reset'] ?? 0;

            // Calculer le pourcentage d'utilisation
            $used = $limit - $remaining;
            $percentage = $limit > 0 ? round(($used / $limit) * 100) : 0;

            // Calculer le temps jusqu'au reset
            $resetTime = $this->formatResetTime($reset);

            // Déterminer le statut
            $status = $this->getStatus($percentage);
            if ($percentage >= 80) {
                $hasWarnings = true;
            }

            $tableRows[] = [
                $endpoint,
                "{$used}/{$limit}",
                "{$percentage}%",
                $remaining,
                $resetTime,
                $status
            ];
        }

        $io->table(
            ['Endpoint', 'Utilisé', '%', 'Restant', 'Reset dans', 'Statut'],
            $tableRows
        );

        if ($hasWarnings) {
            $io->warning('⚠️ Certains endpoints approchent de leurs limites !');
        } else {
            $io->success('✅ Tous les rate limits sont dans des niveaux acceptables.');
        }
    }

    private function formatResetTime(int $resetTimestamp): string
    {
        if ($resetTimestamp === 0) {
            return 'N/A';
        }

        $now = time();
        $diff = $resetTimestamp - $now;

        if ($diff <= 0) {
            return 'Maintenant';
        }

        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);
        $seconds = $diff % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    private function getStatus(int $percentage): string
    {
        if ($percentage >= 95) {
            return '🔴 CRITIQUE';
        } elseif ($percentage >= 80) {
            return '🟡 ATTENTION';
        } elseif ($percentage >= 50) {
            return '🟢 NORMAL';
        } else {
            return '🟢 EXCELLENT';
        }
    }
}
