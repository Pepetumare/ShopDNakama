<?php

return [
    'design_assistance_price' => 500,
    'design_assistance_products' => ['mug', 'shirt_white', 'shirt_black', 'badges', 'stickers', 'patch'],
    'reference_max_kb' => (int) env('CUSTOM_ORDERS_REFERENCE_MAX_KB', 20480),
    'model_max_kb' => (int) env('CUSTOM_ORDERS_MODEL_MAX_KB', 20480),
    'max_files' => 5,
    'mug_variants' => ['white' => 'Taza blanca', 'magic' => 'Taza mágica'],
];
