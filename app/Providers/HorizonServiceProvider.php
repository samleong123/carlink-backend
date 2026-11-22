<?php
namespace App\Providers;

// Workers remain available while Horizon's HTML dashboard and unwrapped API are disabled.
class HorizonServiceProvider extends \Laravel\Horizon\HorizonServiceProvider
{
    protected function registerRoutes() {}
}
