<?php

namespace App\Command;

use App\Service\RssFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-rss',
    description: 'Récupère tous les flux RSS configurés et affiche le contenu brut'
)]
class FetchRssCommand extends Command
{
    public function __construct(
        private RssFetcher $rssFetcher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔄 Récupération des flux RSS');

        // Récupération de tous les flux
        $io->section('Récupération des flux...');
        $results = $this->rssFetcher->fetchAllFeeds();

        $successCount = count($results['success']);
        $errorCount = count($results['errors']);
        $totalCount = $successCount + $errorCount;

        if ($totalCount === 0) {
            $io->warning('Aucun flux RSS configuré dans la base de données.');
            $io->note('Utilise l\'interface web pour ajouter des flux RSS : /flux');
            return Command::SUCCESS;
        }

        // Affichage du résumé
        $io->section('Résumé de la récupération');
        $io->definitionList(
            ['Total des flux' => $totalCount],
            ['✅ Succès' => $successCount],
            ['❌ Erreurs' => $errorCount]
        );

        // Affichage des erreurs s'il y en a
        if ($errorCount > 0) {
            $io->section('❌ Erreurs rencontrées');
            foreach ($results['errors'] as $error) {
                $flux = $error['flux'];
                $io->error(sprintf(
                    'Flux "%s" (%s): %s',
                    $flux->getName(),
                    $flux->getUrl(),
                    $error['error']
                ));
            }
        }

        // Affichage du contenu fusionné
        if ($successCount > 0) {
            $io->section('📄 Contenu des flux RSS (brut)');
            
            $mergedContent = $this->rssFetcher->mergeFeeds($results);
            
            if ($output->isVerbose()) {
                // Mode verbose : affichage complet
                $output->writeln($mergedContent);
            } else {
                // Mode normal : affichage avec pagination
                $lines = explode("\n", $mergedContent);
                $totalLines = count($lines);
                
                $io->note(sprintf('Contenu total : %d lignes', $totalLines));
                
                if ($totalLines > 50) {
                    $io->warning('Le contenu est volumineux. Utilise -v pour voir tout le contenu ou redirige vers un fichier.');
                    $io->text('Exemple : php bin/console app:fetch-rss > flux_rss.xml');
                    
                    // Affichage des 50 premières lignes
                    $io->text('Aperçu (50 premières lignes) :');
                    $output->writeln(implode("\n", array_slice($lines, 0, 50)));
                    $io->text('...');
                } else {
                    $output->writeln($mergedContent);
                }
            }
            
            $io->success(sprintf('✅ %d flux RSS récupérés avec succès !', $successCount));
        } else {
            $io->error('Aucun flux RSS n\'a pu être récupéré.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
