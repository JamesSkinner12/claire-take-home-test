<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use App\Services\PayItemSyncClient;
use Illuminate\Support\Facades\Http;
use App\Jobs\PayItemSyncRoutine;
use App\Models\Business;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Illuminate\Queue\ManuallyFailedException as JobException;
use App\Exceptions\PayItemSyncJobException;
use App\Models\PayItem;

class PayItemSyncJobTest extends TestCase
{
    use RefreshDatabase;
    public function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
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
            '192.168.0.171:8080/sync-test/*' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);
        Log::shouldReceive('alert')->with("Unauthorized response from Sync Job for " . $business->external_id);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);
        PayItemSyncRoutine::dispatch($business);

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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);
        Log::shouldReceive('alert')->with("Not Found response from Sync Job for  " . $business->external_id);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
        $this->assertEquals($response->getStatusCode(), 404);
        $this->assertTrue(true);
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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);
        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        $user = $business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
        $this->assertEquals($response->getStatusCode(), 200);
        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($user->payItems()->whereBusinessId($business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());
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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        $user = $business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);

        $payItem = $user->payItems()->create([
            'amount' => 10, //purposefully use wrong amount to make sure the value is corrected on update
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "anExternalIdForThisPayItem",
            'business_id' => $business->id,
            'user_id' => $user->id,
            'pay_date' => "2021-10-19"
        ]);
        
        $this->assertTrue(PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->exists());

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
        $this->assertEquals($response->getStatusCode(), 200);

        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($user->payItems()->whereBusinessId($business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

        $itemToCheck = PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        //$user = User::create([
        $user = $business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
        $this->assertEquals($response->getStatusCode(), 200);
        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($user->payItems()->whereBusinessId($business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());


        $itemToCheck = PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->first();

        // Manually calculate what the amount should be to guarantee calculation is correct
        $calculatedAmount = round((8.5 * 12.5 * ($business->deduction_percentage / 100)), 2);
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
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        $user = $business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);

        $payItem = $user->payItems()->create([
            'amount' => 10,
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "thisOneShouldBeDeleted",
            'business_id' => $business->id,
            'user_id' => $user->id,
            'pay_date' => "2021-10-19"
        ]);
        
        $this->assertTrue(PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "thisOneShouldBeDeleted"
        ])->exists());

        $this->mock(PayItemSyncClient::class)->shouldReceive('makeRequest')->andReturn($response);

        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
        $this->assertEquals($response->getStatusCode(), 200);

        // Assert that PayItems exist with the given externalId for the user/business
        $this->assertTrue($user->payItems()->whereBusinessId($business->id)->whereIn('external_id', ['anExternalIdForThisPayItem', 'aDifferentExternalIdForThisPayItem'])->exists());

        $itemToCheck = PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "anExternalIdForThisPayItem"
        ])->first();
        
        // Confirm that the record that was actually stored contains the 'date' of the second record in the fixture 
        $this->assertEquals($itemToCheck->pay_date, '2021-10-22');
        $this->assertFalse(PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "thisOneShouldBeDeleted"
        ])->exists());
    }

    /**
     * Tests that the database rolls back when the job fails
     */
    public function test_database_rolls_back_on_failure(): void
    {
        // Two part request, will require isLastPage = false on first and isLastPage = true on second
        $responseData = $this->buildMockResponse('TestDatabaseRollsBackOnFailure.json');
        $response = response($responseData);
        $page2Response = response('', 404);
        Http::fake([
            '192.168.0.171:8080/sync-test/abcd-efg-hijk' => $response,
        ]);
        
        $business = Business::create([
            'name' => "Testing No Token",
            'external_id' => "abcd-efg-hijk",
            'deduction_percentage' => 40
        ]);

        $user = $business->users()->create([
            'name' => "James",
            'email' => 'test@testing.com',
            'password' => 'password',
            'external_id' => "abcdedfg",
        ]);

        $payItem = $user->payItems()->create([
            'amount' => 10,
            'pay_rate' => 12.5,
            'hours' => 8.5,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack",
            'business_id' => $business->id,
            'user_id' => $user->id,
            'pay_date' => "2021-10-19"
        ]);
        
        $this->assertTrue(PayItem::where([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'external_id' => "thisOneShouldBeDeletedAndThenRolledBack"
        ])->exists());


        Http::shouldReceive("withHeaders")->andReturn(Mockery::self());
        Http::shouldReceive("get")->andReturn($response);
      
        $this->mock(PayItemSyncClient::class)
        ->shouldReceive('makeRequest')
       // ->with(2)
        ->andReturn($page2Response);
        //Http::shouldReceive("get")->andReturn($response);

        PayItemSyncRoutine::dispatch($business);
    }
}
