<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kitchen Printer Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the kitchen printer integration with Star mC-Print3
    |
    */

    'enabled' => env('KITCHEN_PRINTER_ENABLED', false),

    'default_transport' => env('KITCHEN_PRINTER_DEFAULT_TRANSPORT', 'webprnt'),

    'encoding' => env('KITCHEN_PRINTER_ENCODING', 'big5'),

    'tcp' => [
        'host' => env('KITCHEN_PRINTER_IP', '192.168.50.39'),
        'port' => env('KITCHEN_PRINTER_PORT', 9100),
        'timeout' => env('KITCHEN_PRINTER_TIMEOUT', 5),
    ],

    'webprnt' => [
        'scheme' => env('KITCHEN_PRINTER_WEBPRNT_SCHEME', 'http'),
        'host' => env('KITCHEN_PRINTER_WEBPRNT_HOST', env('KITCHEN_PRINTER_WEBPRNT_IP', env('KITCHEN_PRINTER_IP', '192.168.50.39'))),
        'port' => env('KITCHEN_PRINTER_WEBPRNT_PORT'),
        'path' => env('KITCHEN_PRINTER_WEBPRNT_PATH', '/StarWebPRNT/SendMessage'),
        'verify_ssl' => filter_var(env('KITCHEN_PRINTER_WEBPRNT_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN),
        'timeout' => env('KITCHEN_PRINTER_WEBPRNT_TIMEOUT', 10),
        'username' => env('KITCHEN_PRINTER_WEBPRNT_USERNAME'),
        'password' => env('KITCHEN_PRINTER_WEBPRNT_PASSWORD'),
    ],

    'custom_fields' => [
        'event_time' => 'custom_value1',
        'event_date' => 'custom_value2',
    ],

    'include' => [
        'invoice_number' => true,
        'due_date' => true,
        'event_time' => true,
        'event_date' => false,
        'client_name' => true,
        'client_phone' => true,
    ],
];
