<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Business;
use App\Services\PayItemSyncClient;
use App\Exceptions\PayItemSyncJobException;
use App\Models\PayItem;
use Illuminate\Support\Facades\DB;

class PayItemSyncRoutine implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Business $business)
    {
        $this->business = $business;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            DB::beginTransaction();

            $service = new PayItemSyncClient($this->business);
            $response = $service->collect();
            $wipedUserIds = [];

            foreach ($response as $item) {
                // If the user cannot be found, ignore record and continue
                $user = $this->business->users()->whereExternalId($item['employeeId']);
                if (!$user->exists()) {
                    continue;
                }
                $user = $user->first();
                if (!in_array($user->id, $wipedUserIds)) {
                    $user->payItems()->delete();
                    $wipedUserIds[] = $user->id;
                }

                // If a PayItem record already exists for the given user/business based on externalId, update
                $payItem = PayItem::updateOrCreate(
                    [
                        'external_id' => $item['id'],
                        'business_id' => $this->business->id,
                        'user_id' => $user->id
                    ],
                    [
                        'amount' => PayItem::calculateAmount($this->business, $item['payRate'], $item['hoursWorked']),
                        'pay_rate' => $item['payRate'],
                        'hours' => $item['hoursWorked'],
                        'pay_date' => $item['date']
                    ]
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }
    
    /**
     * Runs when job fails
     *
     * @param  \Throwable $e
     * @return void
     */
    public function failed(\Throwable $e)
    {
        DB::rollBack();
        throw new PayItemSyncJobException($e->getMessage());
    }
}
