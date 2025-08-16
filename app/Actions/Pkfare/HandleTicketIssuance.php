<?php

namespace App\Actions\Pkfare;

use App\Models\Flights\Booking;
use App\Models\Flights\BookingPassenger;
use App\Models\Flights\BookingSegment;
use App\Models\Flights\BookingSegmentTicket;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class HandleTicketIssuance
{
    /**
     * Handle PKFARE TicketIssuanceNotify_V2 payload.
     * $payload = $request->all();
     */
    public function __invoke(array $payload): Booking
    {
        return DB::transaction(function () use ($payload) {

            // 1) Upsert booking
            $booking = Booking::query()->updateOrCreate(
                ['order_num' => $payload['orderNum']],
                [
                    'currency'          => $payload['currency'] ?? null,
                    'air_pnr'           => $payload['airPnr'] ?? null,
                    'pnr'               => $payload['pnr'] ?? null,
                    'merchant_order'    => $payload['merchantOrder'] ?? null,
                    'buyer_order'       => $payload['buyerOrder'] ?? null,
                    'serial_num'        => $payload['serialNum'] ?? ($payload['serialNumber'] ?? null),

                    'payment_gate'      => $payload['paymentGate'] ?? null,
                    'permit_void'       => (int)($payload['permitVoid'] ?? 0),
                    'last_void_time'    => $payload['lastVoidTime'] ?? null,
                    'void_service_fee'  => Arr::get($payload, 'voidServiceFee.amount'),
                    'void_currency'     => Arr::get($payload, 'voidServiceFee.currency'),

                    'issue_status'      => strtoupper($payload['status'] ?? 'ISSUED'),
                    'inform_type'       => $payload['informType'] ?? null,
                    'reject_reason'     => $payload['rejectReason'] ?? null,
                    'issue_remark'      => $payload['remark'] ?? null,

                    'ticket_issued_payload' => $payload,
                    // keep your existing totals/contacts untouched (set elsewhere)
                ]
            );

            // 2) Upsert passengers
            foreach (($payload['passengers'] ?? []) as $p) {
                $bp = BookingPassenger::query()->updateOrCreate(
                    ['booking_id' => $booking->id, 'passenger_index' => (int)$p['passengerIndex']],
                    [
                        'psg_type'           => $p['psgType'] ?? null,
                        'sex'                => $p['sex'] ?? null,
                        'birthday'           => $p['birthday'] ?? null,
                        'first_name'         => $p['firstName'] ?? null,
                        'last_name'          => $p['lastName'] ?? null,
                        'nationality'        => $p['nationality'] ?? null,
                        'card_type'          => $p['cardType'] ?? null,
                        'card_num'           => $p['cardNum'] ?? null,
                        'card_expired_date'  => $p['cardExpiredDate'] ?? null,
                        'associated_passenger_index' => $p['associatedPassengerIndex'] ?? null,
                        'ticket_num'         => $p['ticketNum'] ?? null,
                    ]
                );
            }

            // Build quick index: passengerIndex => booking_passengers.id
            $paxIndexMap = $booking->passengers()
                ->pluck('id', 'passenger_index')
                ->all();

            // 3) Upsert segments & per-segment ticket mapping
            foreach (($payload['pnrList'] ?? []) as $seg) {
                $segment = BookingSegment::query()->updateOrCreate(
                    ['booking_id' => $booking->id, 'segment_no' => (int)$seg['segmentNo']],
                    [
                        'departure'    => $seg['departure'] ?? null,
                        'arrival'      => $seg['arrival'] ?? null,
                        'flight_num'   => $seg['flightNum'] ?? null,
                        'air_pnr'      => $seg['airPnr'] ?? null,
                        'pnr'          => $seg['pnr'] ?? null,
                        'cabin_class'  => $seg['cabinClass'] ?? null,
                        'booking_code' => $seg['bookingCode'] ?? null,
                    ]
                );

                // Map ticketNums -> passengerIndex
                foreach (($seg['ticketNums'] ?? []) as $tn) {
                    $idx = (int)($tn['passengerIndex'] ?? 0);
                    if (!$idx || !isset($paxIndexMap[$idx])) {
                        continue;
                    }
                    BookingSegmentTicket::query()->updateOrCreate(
                        [
                            'booking_segment_id'  => $segment->id,
                            'booking_passenger_id'=> $paxIndexMap[$idx],
                        ],
                        ['ticket_num' => $tn['ticketNum'] ?? null]
                    );
                }
            }

            // 4) Mark your overall business status if you like
            // e.g., $booking->update(['status' => 'confirmed', 'payment_status' => 'paid']);

            // 5) Fire domain events/notifications (email/SMS) here as needed

            return $booking->fresh(['passengers','segments.tickets']);
        });
    }
}
