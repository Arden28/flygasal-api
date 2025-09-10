<?php

namespace App\Http\Controllers;

use App\Actions\Pkfare\HandleTicketIssuance;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected function verify(Request $request): void
    {
        $expected = config('pkfare.webhook_token');
        $given    = $request->header('X-Pkfare-Token');
        
        // Debug
        Log::info('The webhook was initiated!');

        abort_unless($expected && hash_equals($expected, (string)$given), Response::HTTP_FORBIDDEN, 'Invalid webhook token.');
    }


    /**
     * Ticket issuance notification handler.
     *
     * When PKFARE issues tickets for an order (or reissues/changes them), it
     * will send a POST request to your webhook URL. The request body includes
     * the PKFARE order number (`orderNum`), ticketing status (`status`), the
     * airline PNR (`airPnr`), the GDS PNR (`pnr`), a list of passengers with
     * their ticket numbers and personal details, as well as a list of PNRs
     * broken down by flight segment. A typical payload looks like this:
     *
     * ```json
     * {
     *   "orderNum": "91671691154376001",
     *   "status": "ISSUED",
     *   "informType": "Ticket_Issued",
     *   "airPnr": "15CUCZ|25CUCZ|35CUCZ|45CUCZ",
     *   "pnr": "18TJUHNJUIJ875789|287JUHNJUIJUIU8789|...",
     *   "passengers": [
     *     {
     *       "passengerIndex": 1,
     *       "firstName": "Wei",
     *       "lastName": "Chen",
     *       "psgType": "ADT",
     *       "ticketNum": "1T9L02",
     *       ...
     *     },
     *     {
     *       "passengerIndex": 2,
     *       "firstName": "Xiaoting",
     *       "lastName": "Li",
     *       "psgType": "ADT",
     *       "ticketNum": "2T9L02",
     *       ...
     *     }
     *   ],
     *   "pnrList": [
     *     {
     *       "airPnr": "15CUCZ",
     *       "departure": "HKG",
     *       "arrival": "NRT",
     *       "flightNum": "812",
     *       "segmentNo": 1,
     *       "ticketNums": [
     *         { "passengerIndex": 1, "ticketNum": "1T9L02" },
     *         { "passengerIndex": 2, "ticketNum": "2T9L02" }
     *       ]
     *     },
     *     ...
     *   ],
     *   "paymentGate": "PREPAY",
     *   "permitVoid": 0,
     *   "serialNum": "PREPAY_20221222145440_91671691154376001_4677",
     *   "merchantOrder": "PREPAY_20221222145440_91671691154376001_4677"
     * }
     * ```
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Actions\Pkfare\HandleTicketIssuance  $handle
     * @return \Illuminate\Http\JsonResponse
     */
    public function ticketIssuanceNotify(Request $request, HandleTicketIssuance $handle)
    {
        $this->verify($request); // your shared-secret header check

        // Validate minimally (optional – PKFARE already validates upstream)
        $data = $request->all();
        if (!isset($data['orderNum'])) {
            return response()->json(['errorCode' => 400, 'errorMsg' => 'orderNum missing'], 400);
        }

        $booking = $handle($data);
        Log::debug("Ticket issuance notification processed: $booking");

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
