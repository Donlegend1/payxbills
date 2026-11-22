<?php

namespace App\Http\Controllers;

use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Africa's Talking delivery reports and updates the matching SmsLog.
 * Set the callback URL on the Africa's Talking dashboard to POST /webhooks/africastalking/sms-delivery.
 */
class SmsDeliveryReportController extends Controller
{
    public function __invoke(Request $request, SmsService $sms): Response
    {
        $sms->handleDeliveryReport($request->only(['id', 'status', 'phoneNumber', 'failureReason', 'networkCode', 'retryCount']));

        return response()->noContent(200);
    }
}
