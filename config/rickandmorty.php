<?php

/*
 *
 | Operational settings for the adapter that talks to the external catalog.
 | Timeouts are deliberately short: a full synchronisation is roughly 45
 | requests, so a generous timeout multiplies the worst case beyond what is
 | acceptable for a console command.
 *
*/

return [

    'api_url' => env('RICKANDMORTY_API_URL', 'https://rickandmortyapi.com/api'),
    'connect_timeout' => (int) env('RICKANDMORTY_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('RICKANDMORTY_TIMEOUT', 15),
    'retry_times' => (int) env('RICKANDMORTY_RETRY_TIMES', 3),
    'retry_delay' => (int) env('RICKANDMORTY_RETRY_DELAY', 200),

];
