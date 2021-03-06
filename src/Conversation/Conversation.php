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

namespace PHPMentors\PageflowerBundle\Conversation;

use PHPMentors\DomainKata\Entity\EntityInterface;
use Stagehand\FSM\State\StateInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

use PHPMentors\PageflowerBundle\Pageflow\Pageflow;

class Conversation implements EntityInterface
{
    /**
     * @var \Symfony\Component\HttpFoundation\ParameterBag
     */
    public $attributes;

    /**
     * @var string
     */
    private $conversationId;

    /**
     * @var \PHPMentors\PageflowerBundle\Pageflow\Pageflow
     */
    private $pageflow;

    /**
     * @param string                                         $conversationId
     * @param \PHPMentors\PageflowerBundle\Pageflow\Pageflow $pageflow
     */
    public function __construct($conversationId, Pageflow $pageflow)
    {
        $this->attributes = new ParameterBag();
        $this->conversationId = $conversationId;
        $this->pageflow = $pageflow;
    }

    /**
     * @return string
     */
    public function getConversationId()
    {
        return $this->conversationId;
    }

    /**
     * @return \PHPMentors\PageflowerBundle\Pageflow\Pageflow
     */
    public function getPageflow()
    {
        return $this->pageflow;
    }

    /**
     * @return \Stagehand\FSM\State\StateInterface
     */
    public function getCurrentState()
    {
        return $this->pageflow->getCurrentState();
    }

    /**
     * @return \Stagehand\FSM\State\StateInterface
     */
    public function getPreviousState()
    {
        return $this->pageflow->getPreviousState();
    }

    public function start()
    {
        $this->pageflow->start();
    }

    public function end()
    {
        $this->pageflow->triggerEvent(StateInterface::STATE_FINAL);
    }

    /**
     * @param string $stateId
     */
    public function transitionTo($stateId)
    {
        $this->pageflow->triggerEvent($stateId);
    }
}
