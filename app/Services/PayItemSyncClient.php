<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;

class PayItemSyncClient
{
    protected $business;
    protected $data;

    function __construct(Business $business)
    {
        $this->business = $business;
    }

    public function scopedUrl()
    {
        return config('services.some-partner.url') . $this->business->external_id;
    }
    
    /**
     * Makes the actual request to the sync client with the provided page, including required headers and URL.
     *
     * @param  int $page
     * @return \Illuminate\Http\Client\Response
     */
    public function makeRequest(int $page): Response
    {
        return Http::withHeaders([
            'x-api-key' => config('services.some-partner.key')
        ])->get($this->scopedUrl(), ['page' => $page]);
    }
    
    /**
     * Makes the configured request with the provided page number and processes the response.
     *
     * @param  int $page
     * @return \Illuminate\Http\Client\Response
     */
    protected function process(int $page): Response
    {
        $response = $this->makeRequest($page);
        if ($response->getStatusCode() == 401) {
            Log::alert("Unauthorized response from Sync Job for " . $this->business->external_id);
        }
        if ($response->getStatusCode() == 404) {
            Log::critical("Not Found response from Sync Job for " . $this->business->external_id);
        }
        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Invalid response from sync service");
        }
        return $response;
    }
    
    /**
     * This method will collect all pay items from the partner service
     * and store them in the data property
     *
     * @return Array
     */
    public function collect(): Array
    {
        // Set data to an empty array and page to 1 before collecting all pay items
        $this->data = [];
        $page = 1;

        // This loop will run until the isLastPage is either true or is missing
        while (true) {
            $response = $this->process($page);
            $data = json_decode($response->body(), true);
            foreach ($data['payItems'] as $payItem) {
                $this->data[] = $payItem;
            }
            if ($data['isLastPage']) {
                return $this->data;
            }
            // In the case that a 200 response is returned but isLastPage is missing, throw an exception
            if (!isset($data['isLastPage'])) {
                throw new \Exception("Missing data in response, failing and rolling back");
            }

            // Increment page and run again
            $page++;
        }
    }
}
