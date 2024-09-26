## Project Description

For this assignment, the goal was to create a syncing service that could be run as an Artisan command. The job should be run for a specific business by providing the `external_id`. The job will check that we have a Business record in the database before actually launching, otherwise it will display an error.

    php artisan sync:pay-items {external_id}

## Job Description

The job (`App\Jobs\PayItemSyncRoutine`) utilizes a service (`App\Services\PayItemSyncClient`) to interact with a hypothetical endpoint and processes the results.

## Setup Notes
You must add the following block to the `config/services.php` file. 

    'some-partner' => [
        'url' => "https://some-partner-website.com/clair-pay-item-sync/",
        'key' => "CLAIR-ABC-123"
    ]

## Testing Notes
No seeding was configured for this, the unit tests refresh the database and create the necessary models for each specific test.

    ./vendor/bin/phpunit ./tests/Feature