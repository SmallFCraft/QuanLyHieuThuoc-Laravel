<?php

return array_replace_recursive(
    require __DIR__ . '/navigation.php',
    require __DIR__ . '/catalog.php',
    require __DIR__ . '/contact.php',
    require __DIR__ . '/home.php',
    require __DIR__ . '/layout.php',
    [
        'auth' => require __DIR__ . '/auth_ui.php',
        'cart' => require __DIR__ . '/cart.php',
        'checkout' => require __DIR__ . '/checkout.php',
        'profile' => require __DIR__ . '/profile.php',
        'chatbot' => require __DIR__ . '/chatbot.php',
        'health' => require __DIR__ . '/health.php',
        'health_profile' => require __DIR__ . '/health_profile.php',
        'drug_safety' => require __DIR__ . '/drug_safety.php',
        'pharmacist' => require __DIR__ . '/pharmacist.php',
        'reminder' => require __DIR__ . '/reminder.php',
        'admin' => require __DIR__ . '/admin.php',
    ],
);
