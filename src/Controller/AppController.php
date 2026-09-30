<?php

declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Event\EventInterface;

/**
 * @property \App\Controller\Component\EntraAuthComponent $EntraAuth
 */
class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');
        $this->loadComponent('EntraAuth');
    }

    public function beforeRender(EventInterface $event): void
    {
        $this->set('authUser', $this->EntraAuth->user());
    }
}
