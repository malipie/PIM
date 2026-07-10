<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Agent\Domain\Entity\BrandVoiceProfile;
use App\Agent\Infrastructure\ApiPlatform\Resource\BrandVoiceProfileInput;
use App\Agent\Infrastructure\ApiPlatform\Resource\BrandVoiceProfilePatchInput;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P1-03 (#2329) — write path of /api/brand-voice-profiles.
 * Setting a profile default clears the previous default inside the
 * same flush (single transaction); the partial unique index from
 * AgentMigrations\Version20260710090000 is the DB backstop, never the
 * mechanism.
 *
 * @implements ProcessorInterface<BrandVoiceProfileInput|BrandVoiceProfilePatchInput|BrandVoiceProfile, BrandVoiceProfile|null>
 */
final readonly class BrandVoiceProfileProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BrandVoiceProfile
    {
        if ($operation instanceof DeleteOperationInterface) {
            $voice = $this->find($uriVariables);
            $this->em->remove($voice);
            $this->em->flush();

            return null;
        }

        if ($operation instanceof Post) {
            return $this->create($data);
        }

        if ($operation instanceof Patch) {
            return $this->update($data, $uriVariables);
        }

        throw new LogicException(\sprintf('BrandVoiceProfileProcessor cannot handle operation "%s".', $operation::class));
    }

    private function create(mixed $data): BrandVoiceProfile
    {
        if (!$data instanceof BrandVoiceProfileInput) {
            throw new LogicException('BrandVoiceProfileProcessor expects BrandVoiceProfileInput on Post.');
        }

        try {
            $voice = new BrandVoiceProfile(
                name: $data->name,
                tone: $data->tone,
                glossary: $data->glossary,
                bannedWords: $data->bannedWords,
                examples: $data->examples,
            );
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // The partial unique index is checked per statement and Doctrine
        // emits INSERTs before UPDATEs — clearing the old default must
        // flush BEFORE the new default row lands, inside one transaction.
        $this->em->wrapInTransaction(function () use ($voice, $data): void {
            if ($data->isDefault) {
                $this->clearCurrentDefault();
                $this->em->flush();
                $voice->markDefault(true);
            }
            $this->em->persist($voice);
            $this->em->flush();
        });

        return $voice;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function update(mixed $data, array $uriVariables): BrandVoiceProfile
    {
        if (!$data instanceof BrandVoiceProfilePatchInput) {
            throw new LogicException('BrandVoiceProfileProcessor expects BrandVoiceProfilePatchInput on Patch.');
        }

        $voice = $this->find($uriVariables);

        try {
            if (null !== $data->name) {
                $voice->rename($data->name);
            }
            if (null !== $data->tone) {
                $voice->updateTone($data->tone);
            }
            if (null !== $data->glossary) {
                $voice->updateGlossary($data->glossary);
            }
            if (null !== $data->bannedWords) {
                $voice->updateBannedWords($data->bannedWords);
            }
            if (null !== $data->examples) {
                $voice->updateExamples($data->examples);
            }
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // Same per-statement caveat as create(): the old default's UPDATE
        // must reach the DB before this row flips to true.
        $this->em->wrapInTransaction(function () use ($voice, $data): void {
            if (true === $data->isDefault && !$voice->isDefault()) {
                $this->clearCurrentDefault();
                $this->em->flush();
                $voice->markDefault(true);
            } elseif (false === $data->isDefault && $voice->isDefault()) {
                $voice->markDefault(false);
            }
            $this->em->flush();
        });

        return $voice;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function find(array $uriVariables): BrandVoiceProfile
    {
        $id = $uriVariables['id'] ?? null;
        $voice = \is_string($id) || $id instanceof Uuid
            ? $this->em->find(BrandVoiceProfile::class, $id)
            : null;
        if (!$voice instanceof BrandVoiceProfile) {
            throw new NotFoundHttpException('Brand voice profile not found.');
        }

        return $voice;
    }

    /**
     * TenantFilter scopes the lookup, so "the" default is always the
     * current tenant's; flush happens together with the new default —
     * one transaction, no window with two defaults.
     */
    private function clearCurrentDefault(): void
    {
        $current = $this->em->getRepository(BrandVoiceProfile::class)->findOneBy(['isDefault' => true]);
        if ($current instanceof BrandVoiceProfile) {
            $current->markDefault(false);
        }
    }
}
