<?php

return [
    'name' => 'KitchenPrinter',
    
    'enabled' => env('KITCHEN_PRINTER_ENABLED', false),

    'cloudprnt' => [
        'url' => env('CLOUDPRNT_URL', ''),
        'mac_address' => env('CLOUDPRNT_MAC', ''),
        'timeout' => 10, // seconds
    ],

    'template' => env('KITCHEN_PRINT_TEMPLATE', 'mC-Print3'),

    // Which custom fields to use for event info
    'custom_fields' => [
        'event_time' => 'custom_value1',  // Invoice custom field for event time
        'event_date' => 'custom_value2',  // Invoice custom field for event date
    ],

    // What to include in kitchen receipt
    'include' => [
        'invoice_number' => true,
        'due_date' => true,
        'event_time' => true,
        'client_name' => true,
        'client_phone' => true,
        'client_email' => false,
        'item_descriptions' => true,
        'item_prices' => false,  // Kitchen doesn't need prices
        'totals' => false,       // Kitchen doesn't need totals
        'public_notes' => true,
        'private_notes' => false,
    ],
];
