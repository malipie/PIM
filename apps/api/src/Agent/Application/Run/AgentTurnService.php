<?php

declare(strict_types=1);

namespace App\Agent\Application\Run;

use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\Entity\AgentMessage;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\RunNotAwaitingInputException;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * AGENT-P4-01 (#1968) — the next conversational turn: append the user
 * message to the persisted transcript, flip the run back to planning
 * and re-dispatch the async loop, which resumes from the transcript
 * (AgentLoopRunner::loadTranscript). Only awaiting_input runs accept a
 * turn — a run that is planning is already busy, a terminal one is
 * over, and awaiting_approval wants a decision, not prose.
 */
final readonly class AgentTurnService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private AgentProgressPublisher $publisher,
    ) {
    }

    public function appendUserMessage(AgentRun $run, Tenant $tenant, string $message): void
    {
        if (AgentRunStatus::AwaitingInput !== $run->getStatus()) {
            throw RunNotAwaitingInputException::forStatus($run->getStatus()->value);
        }

        $this->entityManager->persist(new AgentMessage($run, AgentMessage::ROLE_USER, [
            ['type' => 'text', 'text' => $message],
        ]));
        $run->resumePlanning();
        $this->entityManager->flush();
        $this->publisher->status($run);

        $this->messageBus->dispatch(new AgentRunMessage($run->getId(), $tenant->getId()));
    }
}
