<?php

declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    $routes->scope('/', function (RouteBuilder $builder): void {
        $builder->connect('/', 'Committees::index');
        $builder->connect('/login', 'Committees::login');
        $builder->connect('/callback', 'Committees::callback');
        $builder->connect('/committees', 'Committees::refresh');
        $builder->connect('/logout', 'Committees::logout');
        $builder->connect('/request-access', 'Committees::requestAccess');
        $builder->connect('/c/*', 'Committees::browse');
    });
};
