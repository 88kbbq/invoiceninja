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

    'tcp' => [
        'ip' => env('KITCHEN_PRINTER_IP', '192.168.50.39'),
        'port' => env('KITCHEN_PRINTER_PORT', 9100),
    ],

    'custom_fields' => [
        'event_time' => 'custom_value1',
        'event_date' => 'custom_value2',
    ],

    'include' => [
        'invoice_number' => true,
        'due_date' => true,
        'event_time' => true,
        'client_name' => true,
        'client_phone' => true,
        'item_descriptions' => true,
        'item_prices' => false,  // Kitchen doesn't need prices
        'totals' => false,        // Kitchen doesn't need totals
    ],
];