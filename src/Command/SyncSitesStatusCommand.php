<?php

namespace App\Command;

use App\Repository\SiteRepository;
use App\Service\CoolifyApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-sites-status',
    description: 'Synchronise le statut des sites avec Coolify',
)]
class SyncSitesStatusCommand extends Command
{
    public function __construct(
        private SiteRepository $siteRepository,
        private CoolifyApiService $coolifyApi,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sites = $this->siteRepository->createQueryBuilder('s')
            ->where('s.coolifyUuid IS NOT NULL')
            ->getQuery()
            ->getResult();

        if (empty($sites)) {
            $io->info('Aucun site avec UUID Coolify trouvé.');
            return Command::SUCCESS;
        }

        $io->progressStart(count($sites));

        foreach ($sites as $site) {
            $newStatus = $this->coolifyApi->getResourceStatus($site->getCoolifyUuid());
            
            if ($newStatus !== 'unknown' && $newStatus !== $site->getStatus()) {
                $site->setStatus($newStatus);
            }
            
            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $io->progressFinish();

        $io->success('Synchronisation des statuts terminée.');

        return Command::SUCCESS;
    }
}
