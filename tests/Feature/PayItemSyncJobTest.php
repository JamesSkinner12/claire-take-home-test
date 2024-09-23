<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Business;
use App\Models\User;
use App\Models\PayItem;
use App\Jobs\PayItemSyncRoutine;
use App\Exceptions\PayItemSyncJobException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayItemSyncJobTest extends TestCase
{
    use RefreshDatabase;
    protected Business $business;
    protected User $user;

    public function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->business = Business::create([
            'name' => "Testing Business",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        $this->user = $this->business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);
    }

    protected function buildMockResponse($filename)
    {
        return file_get_contents(__DIR__ . "/../Fixtures/{$filename}");
    }

    /**
     * Tests that the job can handle receiving a 401 from sync service.
     * Job should post 'alert' to log
     * Job should fail
     */
    public function test_can_handle_no_token(): void
    {
        // Assert that an 'alert' has been created in the logs
        Log::expects('alert')->with("Unauthorized response from Sync Job for " . $this->business->external_id);

        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '*' => Http::response('', 401),
        ]);
    
        // The job failing stops all code after dispatch, wrapping in try/catch to let following code still run
        try {
            PayItemSyncRoutine::dispatch($this->business);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayItemSyncJobException::class, $e);
        }
    }

    /**
     * Tests that the job can handle receiving a 404 from sync service
     * Job should post 'critical' to log
     * Job should fail
     */
    public function test_can_handle_no_business(): void
    {
        // Asserts that a 'critical' item has been created in the logs
        Log::expects('critical')->with("Not Found response from Sync Job for " . $this->business->external_id);

        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '*' => Http::response('', 404),
        ]);
        // The job failing stops all code after dispatch, wrapping in try/catch to let following code still run
        try {
            PayItemSyncRoutine::dispatch($this->business);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayItemSyncJobException::class, $e);
        }
    }

    /**
     * Tests that the job can handle a PayItem record with no corresponding user record
     * Job should disregard PayItem record and continue
     */
    public function test_can_handle_not_finding_user(): void
    {
        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . "*" => Http::response($this->buildMockResponse('TestCanHandleNotFindingUser.json')),
        ]);

        PayItemSyncRoutine::dispatch($this->business);
        // Assert that no PayItem's exist with the given externalIds
        $this->assertFalse(PayItem::whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());
        // ToDo - Add additional test with valid records for another user to ensure other records are still stored
    }

    /**
     * Tests that the job runs as expected when no PayItem record exists
     * Job should create PayItem record
     * Job should continue
     */
    public function test_can_handle_no_existing_payitem_record(): void
    {
        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '*' => Http::response($this->buildMockResponse('TestCanHandleNoExistingPayitemRecord.json')),
        ]);

        PayItemSyncRoutine::dispatch($this->business);
        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($this->user->payItems()->whereBusinessId($this->business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());
    }

    /**
     * Tests that the job runs as expected when no PayItem record exists
     * Job should update existing PayItem record
     * Job should continue
     */
    public function test_can_handle_existing_payitem_record(): void
    {
        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '*' => Http::response($this->buildMockResponse('TestCanHandleExistingPayitemRecord.json')),
        ]);

        $payItem = $this->user->payItems()->create([
            'amount' => 10, //purposefully use wrong amount to make sure the value is corrected on update
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "anExternalIdForThisPayItem",
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'pay_date' => "2021-10-19"
        ]);

        // Ensure that PayItem record was created with exact values
        $this->assertTrue(PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->exists());

        PayItemSyncRoutine::dispatch($this->business);

        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($this->user->payItems()->whereBusinessId($this->business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

        // Reload the model after running the job to ensure expected behavior
        $itemToCheck = PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->first();

        // Confirm that the record that was actually stored contains the 'date' of the second record in the fixture 
        $this->assertEquals($itemToCheck->pay_date, '2021-10-22');
    }

    /**
     * Tests that when the job runs as expected, the calculated amounts are correct
     */
    public function test_amounts_are_accurate(): void
    {
        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '*' => Http::response($this->buildMockResponse('TestCanHandleNoExistingPayitemRecord.json')),
        ]);

        PayItemSyncRoutine::dispatch($this->business);
        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($this->user->payItems()->whereBusinessId($this->business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

        $itemToCheck = PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->first();

        // Manually calculate what the amount should be to guarantee calculation is correct
        $calculatedAmount = round((8.5 * 12.5 * ($this->business->deduction_percentage / 100)), 2);
        $this->assertEquals($itemToCheck->amount, $calculatedAmount);
    }

    /**
     * Tests that the job removes all existing PayItem records for a given user that aren't included in sync.
     */
    public function test_job_removes_existing_payitem_records(): void
    {
        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . "*" => Http::response($this->buildMockResponse('TestCanHandleExistingPayitemRecord.json')),
        ]);

        $payItem = $this->user->payItems()->create([
            'amount' => 10,
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "thisOneShouldBeDeleted",
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'pay_date' => "2021-10-19"
        ]);

        $this->assertTrue(PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "thisOneShouldBeDeleted"
        ])->exists());

        PayItemSyncRoutine::dispatch($this->business);

        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($this->user->payItems()->whereBusinessId($this->business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

        $itemToCheck = PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->first();

        // Confirm that the record that was actually stored contains the 'date' of the second record in the fixture 
        $this->assertEquals($itemToCheck->pay_date, '2021-10-22');
        $this->assertFalse(PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "thisOneShouldBeDeleted"
        ])->exists());
    }

    /**
     * Tests that the database rolls back when the job fails
     */
    public function test_database_rolls_back_on_failure(): void
    {
        // Assert that a 'critical' item was created in the log
        Log::expects('critical');

        Http::fake([
            config('services.some-partner.url') . $this->business->external_id . '?page=1' => Http::response($this->buildMockResponse('TestDatabaseRollsBackOnFailure.json'), 200),
            config('services.some-partner.url') . $this->business->external_id . '?page=2' => Http::response('', 404)
        ]);

        $payItem = $this->user->payItems()->create([
            'amount' => 10,
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack",
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'pay_date' => "2021-10-19"
        ]);

        $payItemTestHandle = PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack"
        ]);
        $this->assertTrue($payItemTestHandle->exists());

        // The job failing stops all code after dispatch, wrapping in try/catch to let following code still run
        try {
            PayItemSyncRoutine::dispatch($this->business);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PayItemSyncJobException::class, $e);
            // do nothing, just let the test keep running
        }

        $this->assertTrue(PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack"
        ])->exists());
    }
}
