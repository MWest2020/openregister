<?php
// phpcs:ignoreFile
/**
 * OpenRegister AbstractNodeFolderEventListener
 *
 * This file contains the event class dispatched when a schema is updated
 * in the OpenRegister application.
 *
 * @category EventListener
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\EventListener;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;

// phpcs:disable
class AbstractNodeFolderEventListener implements IEventListener
{

    /**
     * Constructor for AbstractNodeFolderEventListener.
     *
     * @param ObjectService $objectService The object service for handling node events.
     * @param FileService   $fileService   The file service for file operations.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
    ) {

    }//end __construct()


    /**
     * Handle event dispatched by the event dispatcher.
     *
     * This method processes node events and dispatches them to appropriate handlers.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof AbstractNodeEvent === false) {
            return;
        }

        $node = $event->getNode();
        if ($node->getType() !== FileInfo::TYPE_FOLDER) {
            return;
        }

        match (true) {
            $event instanceof NodeCreatedEvent => $this->handleNodeCreated(event: $event),
            $event instanceof NodeDeletedEvent => $this->handleNodeDeleted(event: $event),
            $event instanceof NodeTouchedEvent => $this->handleNodeTouched(event: $event),
            $event instanceof NodeWrittenEvent => $this->handleNodeWritten(event: $event),
        default => throw new InvalidArgumentException(message: 'Unsupported event type: '.get_class($event)),
        };

    }//end handle()


    /**
     * Handle node created event
     *
     * @param NodeCreatedEvent $event The node created event
     *
     * @return void
     */
    private function handleNodeCreated(NodeCreatedEvent $event): void
    {
        // Call the object service to handle the node created event.
        $this->objectService->nodeCreatedEventFunction(event: $event);

    }//end handleNodeCreated()


    /**
     * Handle node deleted event
     *
     * @param NodeDeletedEvent $event The node deleted event
     *
     * @return void
     */
    private function handleNodeDeleted(NodeDeletedEvent $event): void
    {
        // Call the object service to handle the node deleted event.
        $this->objectService->nodeDeletedEventFunction(event: $event);

    }//end handleNodeDeleted()


    /**
     * Handle node touched event
     *
     * @param NodeTouchedEvent $event The node touched event
     *
     * @return void
     */
    private function handleNodeTouched(NodeTouchedEvent $event): void
    {
        // Call the object service to handle the node touched event.
        $this->objectService->nodeTouchedEventFunction(event: $event);

    }//end handleNodeTouched()


    /**
     * Handle node written event
     *
     * @param NodeWrittenEvent $event The node written event
     *
     * @return void
     */
    private function handleNodeWritten(NodeWrittenEvent $event): void
    {
        // Call the object service to handle the node written event.
        $this->objectService->nodeWrittenEventFunction(event: $event);

    }//end handleNodeWritten()


}//end class
