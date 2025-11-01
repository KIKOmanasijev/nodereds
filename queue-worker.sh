#!/bin/bash
# Queue worker script with proper timeout
# Processes both default and delete queues
php artisan queue:work --queue=default,delete --timeout=600 --tries=3 --max-time=3600
