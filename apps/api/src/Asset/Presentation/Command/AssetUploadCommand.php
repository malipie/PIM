<?php

declare(strict_types=1);

namespace App\Asset\Presentation\Command;

use App\Asset\Application\AssetUploader;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Repository\DoctrineTenantRepository;
use App\Shared\Infrastructure\Tenant\TenantScopeBinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\File;

/**
 * `pim:asset:upload <file> <code> --tenant=<code>` — smoke-test entry
 * point for the Flysystem → MinIO upload path. Useful during local
 * development before #41 wires `POST /api/assets`.
 *
 * Exits 0 on success, prints the resulting Asset id + storage path so
 * the operator can verify the bucket layout in MinIO Console.
 */
#[AsCommand(
    name: 'pim:asset:upload',
    description: 'Upload a local file to the assets storage and create matching Asset rows (#37 smoke).',
)]
final class AssetUploadCommand extends Command
{
    public function __construct(
        private readonly AssetUploader $uploader,
        private readonly DoctrineTenantRepository $tenants,
        private readonly TenantScopeBinder $tenantScope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Local path to the file to upload')
            ->addArgument('code', InputArgument::REQUIRED, 'Asset code (unique per tenant)')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant code (defaults to demo)', 'demo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $path */
        $path = $input->getArgument('file');
        if (!is_file($path)) {
            $io->error(\sprintf('File "%s" does not exist or is not readable.', $path));

            return Command::INVALID;
        }

        /** @var string $code */
        $code = $input->getArgument('code');
        /** @var string $tenantCode */
        $tenantCode = $input->getOption('tenant');

        $tenant = $this->tenants->findOneBy(['code' => $tenantCode]);
        if (!$tenant instanceof Tenant) {
            $io->error(\sprintf('Tenant "%s" not found.', $tenantCode));

            return Command::FAILURE;
        }

        $this->tenantScope->bind($tenant);

        $asset = $this->uploader->upload(new File($path), $code);

        $io->success(\sprintf('Uploaded asset %s', $asset->getId()->toRfc4122()));
        $io->table(
            ['field', 'value'],
            [
                ['code', $asset->getCode()],
                ['original_filename', $asset->getOriginalFilename()],
                ['mime_type', $asset->getMimeType()],
                ['size_bytes', (string) $asset->getSize()],
                ['storage_path', $asset->getStoragePath()],
            ],
        );

        return Command::SUCCESS;
    }
}
