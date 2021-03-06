<?php
/*
 * Copyright (c) 2014 KUBO Atsuhiro <kubo@iteman.jp>,
 * All rights reserved.
 *
 * This file is part of PHPMentorsPageflowerBundle.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\PageflowerBundle\EventListener;

use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Util\SecureRandomInterface;

use PHPMentors\PageflowerBundle\Controller\ReflectionConversationalControllerRepository;
use PHPMentors\PageflowerBundle\Conversation\Conversation;
use PHPMentors\PageflowerBundle\Conversation\ConversationContext;
use PHPMentors\PageflowerBundle\Conversation\ConversationContextAwareInterface;
use PHPMentors\PageflowerBundle\Conversation\ConversationRepository;
use PHPMentors\PageflowerBundle\Pageflow\Pageflow;
use PHPMentors\PageflowerBundle\Pageflow\PageflowRepository;

class ConversationListener implements ConversationContextAwareInterface
{
    /**
     * @var \PHPMentors\PageflowerBundle\Conversation\ConversationContext
     */
    private $conversationContext;

    /**
     * @var \PHPMentors\PageflowerBundle\Conversation\ConversationRepository
     */
    private $conversationRepository;

    /**
     * @var \PHPMentors\PageflowerBundle\Pageflow\PageflowRepository
     */
    private $pageflowRepository;

    /**
     * @var \PHPMentors\PageflowerBundle\Controller\ReflectionConversationalControllerRepository
     */
    private $reflectionConversationalControllerRepository;

    /**
     * @var \Symfony\Component\Security\Core\Util\SecureRandomInterface
     */
    private $secureRandom;

    /**
     * @param \PHPMentors\PageflowerBundle\Conversation\ConversationRepository                     $conversationRepository
     * @param \PHPMentors\PageflowerBundle\Pageflow\PageflowRepository                             $pageflowRepository
     * @param \PHPMentors\PageflowerBundle\Controller\ReflectionConversationalControllerRepository $reflectionConversationalControllerRepository
     * @param \Symfony\Component\Security\Core\Util\SecureRandomInterface                          $secureRandom
     */
    public function __construct(ConversationRepository $conversationRepository, PageflowRepository $pageflowRepository, ReflectionConversationalControllerRepository $reflectionConversationalControllerRepository, SecureRandomInterface $secureRandom)
    {
        $this->conversationRepository = $conversationRepository;
        $this->pageflowRepository = $pageflowRepository;
        $this->reflectionConversationalControllerRepository = $reflectionConversationalControllerRepository;
        $this->secureRandom = $secureRandom;
    }

    /**
     * {@inheritDoc}
     */
    public function setConversationContext(ConversationContext $conversationContext)
    {
        $this->conversationContext = $conversationContext;
    }

    /**
     * @param  \Symfony\Component\HttpKernel\Event\FilterControllerEvent $event
     * @throws \UnexpectedValueException
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        if ($event->getRequestType() == HttpKernelInterface::MASTER_REQUEST) {
            $controllerName = $event->getRequest()->attributes->get('_controller');
            if (strpos($controllerName, '::') === false && substr_count($controllerName, ':') == 1) {
                $pageflowId = substr($controllerName, 0, strpos($controllerName, ':'));
                $pageflow = $this->pageflowRepository->findByPageflowId($pageflowId);
                if ($pageflow !== null) {
                    list($conversationalController, $action) = $event->getController();

                    $this->conversationRepository->setConversationCollection($event->getRequest()->getSession()->getConversationBag());

                    if ($event->getRequest()->request->has($this->conversationContext->getConversationParameterName())) {
                        $conversationId = $event->getRequest()->request->get($this->conversationContext->getConversationParameterName());
                    } elseif ($event->getRequest()->query->has($this->conversationContext->getConversationParameterName())) {
                        $conversationId = $event->getRequest()->query->get($this->conversationContext->getConversationParameterName());
                    }

                    if (isset($conversationId)) {
                        $conversation = $this->conversationRepository->findByConversationId($conversationId);
                    }

                    if (!isset($conversation)) {
                        $conversation = $this->createConversation($pageflow);
                        $conversation->start();

                        $reflectionConversationalController = $this->reflectionConversationalControllerRepository->findByClass(get_class($conversationalController));
                        if ($reflectionConversationalController === null) {
                            throw new \UnexpectedValueException(sprintf(
                                'ReflectionConversationalController object for controller "%s" is not found.',
                                get_class($conversationalController)
                            ));
                        }

                        foreach ($reflectionConversationalController->getInitMethods() as $initMethod) { /* @var $initMethod \ReflectionMethod */
                            if (is_callable(array($conversationalController, $initMethod->getName()))) {
                                $initMethod->invoke($conversationalController);
                            } else {
                                throw new \UnexpectedValueException(sprintf(
                                    'Init method "%s" for pageflow "%s" is not callable.',
                                    get_class($conversationalController) . '::' . $initMethod->getName(),
                                    $pageflow->getPageflowId()
                                ));
                            }
                        }

                        $this->conversationRepository->add($conversation);
                    }

                    if (!isset($reflectionConversationalController)) {
                        $reflectionConversationalController = $this->reflectionConversationalControllerRepository->findByClass(get_class($conversationalController));
                        if ($reflectionConversationalController === null) {
                            throw new \UnexpectedValueException(sprintf(
                                'ReflectionConversationalController object for controller "%s" is not found.',
                                get_class($conversationalController)
                            ));
                        }
                    }

                    if (!in_array($conversation->getCurrentState()->getStateId(), $reflectionConversationalController->getAcceptableStates($action))) {
                        throw new AccessDeniedHttpException(sprintf(
                            'Controller "%s" can be accessed when the current state is one of [ %s ], the actual state is "%s".',
                            get_class($conversationalController) . '::' . $action,
                            implode(', ', $reflectionConversationalController->getAcceptableStates($action)),
                            $conversation->getCurrentState()->getStateId()
                        ));
                    }

                    foreach ($reflectionConversationalController->getStatefulProperties() as $statefulProperty) { /* @var $statefulProperty \ReflectionProperty */
                        if ($conversation->attributes->has($statefulProperty->getName())) {
                            if (!$statefulProperty->isPublic()) {
                                $statefulProperty->setAccessible(true);
                            }

                            $statefulProperty->setValue($conversationalController, $conversation->attributes->get($statefulProperty->getName()));

                            if (!$statefulProperty->isPublic()) {
                                $statefulProperty->setAccessible(false);
                            }
                        }
                    }

                    $this->conversationContext->setConversation($conversation);
                    $this->conversationContext->setConversationalController($conversationalController);
                    $this->conversationContext->setReflectionConversationalController($reflectionConversationalController);
                }
            }
        }
    }

    /**
     * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        if ($event->getRequestType() == HttpKernelInterface::MASTER_REQUEST) {
            $conversation = $this->conversationContext->getConversation();
            if ($conversation !== null) {
                if ($conversation->getCurrentState()->isEndState()) {
                    $conversation->end();
                    $this->conversationRepository->remove($conversation);

                    $conversationalController = $this->conversationContext->getConversationalController();
                    $reflectionConversationalController = $this->conversationContext->getReflectionConversationalController();
                    if ($conversationalController !== null && $reflectionConversationalController !== null) {
                        foreach ($reflectionConversationalController->getStatefulProperties() as $statefulProperty) { /* @var $statefulProperty \ReflectionProperty */
                            if ($conversation->attributes->has($statefulProperty->getName())) {
                                $conversation->attributes->remove($statefulProperty->getName());
                            }
                        }
                    }
                } else {
                    $conversationalController = $this->conversationContext->getConversationalController();
                    $reflectionConversationalController = $this->conversationContext->getReflectionConversationalController();
                    if ($conversationalController !== null && $reflectionConversationalController !== null) {
                        foreach ($reflectionConversationalController->getStatefulProperties() as $statefulProperty) { /* @var $statefulProperty \ReflectionProperty */
                            if (!$statefulProperty->isPublic()) {
                                $statefulProperty->setAccessible(true);
                            }

                            $conversation->attributes->set($statefulProperty->getName(), $statefulProperty->getValue($conversationalController));

                            if (!$statefulProperty->isPublic()) {
                                $statefulProperty->setAccessible(false);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  \PHPMentors\PageflowerBundle\Pageflow\Pageflow         $pageflow
     * @return \PHPMentors\PageflowerBundle\Conversation\Conversation
     */
    private function createConversation(Pageflow $pageflow)
    {
        return new Conversation($this->generateConversationId(), $this->createPageflow($pageflow));
    }

    /**
     * @param  \PHPMentors\PageflowerBundle\Pageflow\Pageflow $pageflow
     * @return \PHPMentors\PageflowerBundle\Pageflow\Pageflow
     */
    private function createPageflow(Pageflow $pageflow)
    {
        return unserialize(serialize($pageflow));
    }

    /**
     * @return string
     */
    private function generateConversationId()
    {
        return sha1($this->secureRandom->nextBytes(24));
    }
}
