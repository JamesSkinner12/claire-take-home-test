<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use App\Jobs\PayItemSyncRoutine;
use App\Models\Business;

class PayItemSyncCommand extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pay-items:sync {business}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs the PayItemSyncRoutine for the provided business External ID';

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array<string, string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'business' => 'What is the external ID for the business to sync?',
        ];
    }
    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Confirm that business exists in our system before launching job
        $business = Business::whereExternalId($this->argument('business'));
        if (!$business->exists()) {
            $this->fail("The business External ID that you provided does not exist");
        }
        $business = $business->first();
        // Run job and fail/display error if an exception is thrown
        try {
            PayItemSyncRoutine::dispatch($business);
        } catch (\Throwable $e) {
            $this->fail("An unexpected error occurred: " . $e->getMessage());
        }
        $this->success("Sync run successfully for " . $business->external_id);
    }
}
