<?php

require_once __DIR__ . '/vendor/autoload.php';

use HiEvents\Models\Event;
use HiEvents\Models\Account;
use HiEvents\Models\User;

// This is just to understand the Event model structure
// We'll delete this file after understanding

echo "Event model structure test\n";

// Check if Event has settings in fillable
$event = new Event();
echo "Event fillable fields: " . print_r($event->getFillable(), true) . "\n";

// Check casts
echo "Event casts: " . print_r($event->getCasts(), true) . "\n";

// Check if there are any hidden attributes
echo "Event hidden: " . print_r($event->getHidden(), true) . "\n";

// Check attributes
echo "Event attributes: " . print_r($event->getAttributes(), true) . "\n";