<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;
use App\Models\Business;
use App\Models\User;
use App\Models\PayItem;
use App\Services\PayItemSyncClient;
use App\Jobs\PayItemSyncRoutine;
use App\Exceptions\PayItemSyncJobException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Exceptions;
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
        Exceptions::fake();
        $this->expectException(PayItemSyncJobException::class);
        $response = response('', 401);
        Http::fake([
            config('services.some-partner.url') . '*' => $response,
        ]);

        Log::shouldReceive('alert')->with("Unauthorized response from Sync Job for " . $this->business->external_id);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);
        PayItemSyncRoutine::dispatch($this->business);

        $this->assertEquals($response->getStatusCode(), 401);
        Exceptions::assertReported(function (PayItemSyncJobException $e) {
            return $e->getMessage() === 'Invalid response from sync service';
        });
    }

    /**
     * Tests that the job can handle receiving a 404 from sync service
     * Job should post 'critical' to log
     * Job should fail
     */
    public function test_can_handle_no_business(): void
    {
        Exceptions::fake();
        $this->expectException(PayItemSyncJobException::class);
        $response = response('', 404);
        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
        ]);

        Log::shouldReceive('alert')->with("Not Found response from Sync Job for  " . $this->business->external_id);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 404);
        Exceptions::assertReported(function (JobException $e) {
            return $e->getMessage() === 'Invalid response from sync service';
        });
    }

    /**
     * Tests that the job can handle a PayItem record with no corresponding user record
     * Job should disregard PayItem record and continue
     */
    public function test_can_handle_not_finding_user(): void
    {
        $responseData = $this->buildMockResponse('TestCanHandleNotFindingUser.json');
        $response = response($responseData);

        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
        ]);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 200);
        // Assert that no PayItem's exist with the given externalIds
        $this->assertFalse(PayItem::whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());
    }

    /**
     * Tests that the job runs as expected when no PayItem record exists
     * Job should create PayItem record
     * Job should continue
     */
    public function test_can_handle_no_existing_payitem_record(): void
    {
        $responseData = $this->buildMockResponse('TestCanHandleNoExistingPayitemRecord.json');
        $response = response($responseData);

        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
        ]);

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 200);
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
        $responseData = $this->buildMockResponse('TestCanHandleExistingPayitemRecord.json');
        $response = response($responseData);

        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
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

        $this->assertTrue(PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->exists());

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 200);

        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($this->user->payItems()->whereBusinessId($this->business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

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
        $responseData = $this->buildMockResponse('TestCanHandleNoExistingPayitemRecord.json');
        $response = response($responseData);

        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
        ]);

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 200);
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
        $responseData = $this->buildMockResponse('TestCanHandleExistingPayitemRecord.json');
        $response = response($responseData);

        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
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

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        $this->assertEquals($response->getStatusCode(), 200);

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
        Exceptions::fake();
        $this->expectException(PayItemSyncJobException::class);

        // Two part request, will require isLastPage = false on first and isLastPage = true on second
        $responseData = $this->buildMockResponse('TestDatabaseRollsBackOnFailure.json');
        $response = response($responseData);
        $page2Response = response('', 404);
        Http::fake([
            config('services.some-partner.url') . 'abcd-efg-hijk' => $response,
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

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        $this->mock(PayItemSyncClient::class)
            ->shouldReceive('makeRequest')
            ->andReturn($page2Response);
        //Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($this->business);
        PayItem::where([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack"
        ])->exists();
        Exceptions::assertReported(function (JobException $e) {
            return $e->getMessage() === 'Invalid response from sync service';
        });
    }
}
