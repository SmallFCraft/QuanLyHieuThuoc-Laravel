<?php

return array_replace_recursive(
    require __DIR__ . '/navigation.php',
    require __DIR__ . '/actions.php',
    require __DIR__ . '/catalog.php',
    require __DIR__ . '/orders.php',
    require __DIR__ . '/cart_legacy.php',
    require __DIR__ . '/dashboard.php',
    require __DIR__ . '/users.php',
    require __DIR__ . '/feedback.php',
    require __DIR__ . '/contact.php',
    require __DIR__ . '/home.php',
    require __DIR__ . '/inventory.php',
    require __DIR__ . '/shared.php',
    require __DIR__ . '/layout.php',
    [
        'auth' => require __DIR__ . '/auth_ui.php',
        'cart' => require __DIR__ . '/cart.php',
        'checkout' => require __DIR__ . '/checkout.php',
        'profile' => require __DIR__ . '/profile.php',
        'chatbot' => require __DIR__ . '/chatbot.php',
        'admin' => require __DIR__ . '/admin.php',
    ],
);
