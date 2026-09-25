<?php

return [

    /*
     * Where PingPong lives. Only change this to point an app at a local or
     * staging PingPong.
     */
    'endpoint' => env('PINGPONG_ENDPOINT', 'https://pingpong.kobaltdigital.nl'),

    /*
     * The Agent key of this app's Monitor, shown once when it is created in
     * PingPong. Without a key the Agent sends nothing and schedules nothing.
     */
    'key' => env('PINGPONG_KEY'),

];
