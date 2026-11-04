<?php

// A tiny hard-coded catalogue. Prices are in the smallest currency unit (cents).
return [
    'currency' => 'usd',
    'products' => [
        'coffee' => ['name' => 'Cup of Coffee', 'price' => 300, 'emoji' => '☕', 'description' => 'Buy the developer a coffee.'],
        'ebook' => ['name' => 'PHP Ebook', 'price' => 1500, 'emoji' => '📘', 'description' => 'A pretend digital download.'],
        'tshirt' => ['name' => 'Laravel T-Shirt', 'price' => 2500, 'emoji' => '👕', 'description' => 'Imaginary, but very comfy.'],
    ],
];
