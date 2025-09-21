<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Event settings mechanism...\n";

// Check if there's a settings attribute in the Event model
$reflection = new ReflectionClass(\HiEvents\Models\Event::class);
$methods = $reflection->getMethods();

echo "Event model methods:\n";
foreach ($methods as $method) {
    if (strpos($method->getName(), 'settings') !== false || 
        strpos($method->getName(), 'Settings') !== false) {
        echo "- " . $method->getName() . "\n";
    }
}

echo "\nEvent model properties:\n";
$properties = $reflection->getProperties();
foreach ($properties as $property) {
    if (strpos($property->getName(), 'settings') !== false || 
        strpos($property->getName(), 'Settings') !== false) {
        echo "- " . $property->getName() . "\n";
    }
}

// Check parent classes
$parent = $reflection->getParentClass();
while ($parent) {
    echo "\nParent class: " . $parent->getName() . "\n";
    $parentMethods = $parent->getMethods();
    foreach ($parentMethods as $method) {
        if (strpos($method->getName(), 'settings') !== false || 
            strpos($method->getName(), 'Settings') !== false ||
            strpos($method->getName(), 'getAttribute') !== false ||
            strpos($method->getName(), 'setAttribute') !== false) {
            echo "- " . $method->getName() . "\n";
        }
    }
    $parent = $parent->getParentClass();
}