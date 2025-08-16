<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected function verify(Request $request): void
    {
        $expected = config('pkfare.webhook_token');
        $given    = $request->header('X-Pkfare-Token');
        abort_unless($expected && hash_equals($expected, (string)$given), Response::HTTP_FORBIDDEN, 'Invalid webhook token.');
    }

    // Called when tickets get issued (the body mirrors the example in docs)
    public function ticketIssuanceNotify(Request $request)
    {
        $this->verify($request);

        $payload = $request->all();

        // TODO: persist tickets, set your Order as "issued", notify customers via email/SMS etc.
        // $payload['pnrList'], $payload['passengers'][..]['ticketNum'], etc.

        return response()->json(['errorCode' => 0, 'errorMsg' => 'ok']);
    }

    // Called when refund order reaches final state
    public function refundResultNotify(Request $request)
    {
        $this->verify($request);

        // TODO: mark refund order as "Refund, to be reimbursed" / "Refund, reimbursed"
        // Keep reference of refund orderNum / passengerPriceList

        return response()->json(['errorCode' => 0, 'errorMsg' => 'ok']);
    }

    // Called with reimbursement status & (optionally) voucher fileId
    public function reimbursedResultNotify(Request $request)
    {
        $this->verify($request);

        // TODO: store reimbursement voucher, post-accounting

        return response()->json(['errorCode' => 0, 'errorMsg' => 'ok']);
    }

    // Airline schedule change push
    public function scheduleChangeNotify(Request $request)
    {
        $this->verify($request);

        // TODO: persist schedule-change messages, notify pax, await acceptance
        // You can later call acceptScheduleChange with passengers info

        return response()->json(['errorCode' => 0, 'errorMsg' => 'ok']);
    }
}
