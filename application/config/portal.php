<?php

return [
    'company' => env('PORTAL_COMPANY', 'Сервисная компания'),
    'support_email' => env('PORTAL_SUPPORT_EMAIL'),
    'support_phone' => env('PORTAL_SUPPORT_PHONE'),
    'live_payments' => false,
    'finance' => [
        'approved' => env('PORTAL_FINANCE_APPROVED', false),
        'legal_header' => env('PORTAL_LEGAL_HEADER'),
        'tax_label' => env('PORTAL_TAX_LABEL'),
        'fiscal' => [
            'approved' => false,
            'approved_by' => null,
            'approved_on' => null,
            'arrangement' => null,
            'tax_system' => null,
            'prices_include_tax' => null,
            'scenarios' => [
                'advance' => null,
                'final_payment' => null,
                'bank_transfer' => null,
                'final_settlement' => null,
                'advance_offset' => null,
                'refund' => null,
                'correction' => null,
            ],
        ],
    ],
    'delivery' => ['email_enabled' => env('PORTAL_EMAIL_ENABLED', false)],
    'yookassa' => [
        'sandbox_enabled' => env('YOOKASSA_SANDBOX_ENABLED', false),
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
    ],
    'timezone' => 'Europe/Moscow',
    'demo' => env('PORTAL_DEMO', false),
];
