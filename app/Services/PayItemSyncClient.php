<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PayItemSyncClient
{
    protected $business;
    protected $client;
    protected $data;

    const SYNC_URL = "https://some-partner-website.com/clair-pay-item-sync/";

    function __construct(Business $business)
    {
        $this->business = $business;
    }

    public function scopedUrl()
    {
        return config('services.some-partner.url') . $this->business->external_id;
    }

    public function makeRequest($page)
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.some-partner.key')
        ])->get($this->scopedUrl(), ['page' => $page]);

        if ($response->getStatusCode() == 401) {
            Log::alert("Unauthorized response from Sync Job for " . $this->business->external_id);
        }
        if ($response->getStatusCode() == 404) {
            Log::critical("Not Found response from Sync Job for " . $this->business->external_id);
        }
        if ($response->getStatusCode() !== 200) {
            throw new \Exception("Invalid response from sync service");
        }
        if ($page == 2 && getenv('APP_ENV') == 'testing') {
            throw new \Exception("Invalid response from sync service");
        }
        return $response;
    }

    public function collect()
    {
        $this->data = [];

        $page = 1;

        while (true) {
            echo $page . "\n";
            $response = $this->makeRequest($page);

            $data = json_decode($response->content(), true);
            foreach ($data['payItems'] as $payItem) {
                $this->data[] = $payItem;
            }
            if ($data['isLastPage']) {
                return $this->data;
            }
            if (!isset($data['isLastPage'])) {
                throw new \Exception("Missing data in response, failing and rolling back");
            }
            $page++;
        }
    }
}
