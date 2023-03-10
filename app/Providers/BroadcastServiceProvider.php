<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // \Illuminate\Support\Facades\Log::info("Authorizing boot");
        // Broadcast::routes();
        Broadcast::routes([
            'middleware' => [
                'jwt.verify'
            ]
        ]);
        // Broadcast::routes( [ 'middleware' => ['jwt.auth' ] ] );
        // Broadcast::routes();
        // Broadcast::routes(['prefix' => 'api', 'middleware' => ['jwt.autch']]);
        // Broadcast::routes(["prefix"=>"api","middleware"=>["api","jwt.auth"]]);
        require base_path('routes/channels.php');
    }
}
