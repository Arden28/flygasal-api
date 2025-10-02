<?php

namespace App\Support;

final class MapPrecisePricing
{
    /**
     * Normalize PKFare "precise pricing" `data` payload to a single UI-ready offer.
     *
     * Expected shape (per your example):
     * [
     *   'solution' => [...],
     *   'flights'  => [...],
     *   'segments' => [...],
     *   'ancillaryAvailability' => [...]
     * ]
     *
     * @param array $payload The PKFare precise pricing "data" array
     * @return array<string,mixed>  A single normalized offer
     */
    public static function normalize(array $payload): array
    {
        $solution = $payload['solution'] ?? [];
        $segmentsArr = $payload['segments'] ?? [];
        $flightsArr  = $payload['flights']  ?? [];

        // Index segments and flights by their IDs
        $segments = [];
        foreach ($segmentsArr as $s) {
            if (!empty($s['segmentId'])) {
                $segments[$s['segmentId']] = $s;
            }
        }

        $flights = [];
        foreach ($flightsArr as $f) {
            if (!empty($f['flightId'])) {
                $flights[$f['flightId']] = $f;
            }
        }

        // Collect journey flightIds from solution->journeys
        $journeys = $solution['journeys'] ?? [];
        $journeyKeys = array_keys($journeys);
        $flightIds = [];
        foreach ($journeyKeys as $k) {
            $flightIds = array_merge($flightIds, $journeys[$k] ?? []);
        }
        $flightIds = array_values(array_unique($flightIds));

        // Map segment order (segment indices are 1-based in baggage/rules blocks)
        $segIdsOrdered = [];
        $segToFlight   = [];

        foreach ($flightIds as $fid) {
            $segIds = $flights[$fid]['segmentIds']
                ?? $flights[$fid]['segmengtIds'] // tolerate typo seen in search payloads
                ?? [];

            foreach ($segIds as $sid) {
                if (!isset($segments[$sid])) {
                    continue;
                }
                $segIdsOrdered[]   = $sid;
                $segToFlight[$sid] = $fid;
            }
        }
        $segIdsOrdered = array_values(array_unique($segIdsOrdered));

        // Flatten segments for the trip and inject flightId
        $tripSegs = array_values(array_filter(array_map(function ($sid) use ($segments, $segToFlight) {
            if (!isset($segments[$sid])) return null;
            $seg = $segments[$sid];
            $seg['flightId'] = $segToFlight[$sid] ?? null;
            return $seg;
        }, $segIdsOrdered)));

        if (!$tripSegs) {
            return [
                'error' => 'No segments found in precise pricing payload.',
                'raw'   => $payload,
            ];
        }

        // Helpers for first/last/primary flight
        $firstSeg = $tripSegs[0];
        $lastSeg  = $tripSegs[count($tripSegs) - 1];
        $firstF   = isset($flightIds[0]) ? ($flights[$flightIds[0]] ?? null) : null;

        // Build 1-based index -> segmentId map (consistent with PKFare miniRules/baggage lists)
        $idxToSegId = [];
        foreach ($tripSegs as $i => $s) {
            $idxToSegId[$i + 1] = $s['segmentId'];
        }

        // ----- Baggage (ADT) -----
        $adtChecked = []; $adtCarry = [];
        foreach (($solution['baggageMap']['ADT'] ?? []) as $b) {
            foreach (($b['segmentIndexList'] ?? []) as $n) {
                $sid = $idxToSegId[$n] ?? null; if (!$sid) continue;
                $adtChecked[$sid] = [
                    'amount' => $b['baggageAmount'] ?? null,
                    'weight' => $b['baggageWeight'] ?? null,
                ];
                $adtCarry[$sid] = [
                    'amount' => $b['carryOnAmount'] ?? null,
                    'weight' => $b['carryOnWeight'] ?? null,
                    'size'   => $b['carryOnSize'] ?? null,
                ];
            }
        }

        // ----- Rules (ADT) -----
        $rulesADT = [];
        foreach (($solution['miniRuleMap']['ADT'] ?? []) as $block) {
            $ids = array_values(array_filter(array_map(
                fn($n) => $idxToSegId[$n] ?? null,
                $block['segmentIndex'] ?? []
            )));
            $mini = array_map(function ($r) {
                $label = match ($r['penaltyType'] ?? -1) {
                    0 => 'Refund',
                    1 => 'Change',
                    2 => 'No-show',
                    3 => 'Reissue / Reroute',
                    default => 'Penalty',
                };
                $r['label'] = $label;
                return $r;
            }, $block['miniRules'] ?? []);
            $rulesADT[] = ['segmentIds' => $ids, 'miniRules' => $mini];
        }

        // ----- Pricing breakdown (per PTC and totals) -----
        $currency = $solution['currency'] ?? 'USD';

        $ptc = [
            'ADT' => [
                'count' => (int)($solution['adults'] ?? 0),
                'fare'  => (float)($solution['adtFare'] ?? 0),
                'tax'   => (float)($solution['adtTax']  ?? 0),
            ],
            'CHD' => [
                'count' => (int)($solution['children'] ?? 0),
                'fare'  => (float)($solution['chdFare'] ?? 0),
                'tax'   => (float)($solution['chdTax']  ?? 0),
            ],
            // INF often comes only via totals or separate fields if provided by PKFare; default to 0
            'INF' => [
                'count' => (int)($solution['infants'] ?? 0),
                'fare'  => (float)($solution['infFare'] ?? 0),
                'tax'   => (float)($solution['infTax']  ?? 0),
            ],
        ];

        $fees = [
            'tktFee' => (float)($solution['tktFee'] ?? 0),
            'platformServiceFee' => (float)($solution['platformServiceFee'] ?? 0),
            'merchantFee' => (float)($solution['merchantFee'] ?? 0),
            // Some APIs also expose qCharge or other surcharges on precise pricing;
            // include if present for consistency with search normalizer.
            'qCharge' => (float)($solution['qCharge'] ?? 0),
        ];

        // Per-PTC totals and grand total
        $ptcTotals = [];
        $grand = 0.0;

        foreach ($ptc as $code => $row) {
            $perPax = max(0.0, ($row['fare'] + $row['tax']));
            $count  = max(0, (int)$row['count']);
            $sum    = $perPax * $count;
            $ptcTotals[$code] = [
                'count'  => $count,
                'perPax' => $perPax,
                'sum'    => $sum,
            ];
            $grand += $sum;
        }

        $feeTotal = array_sum($fees);
        $grand += $feeTotal;

        // Carriers
        $marketing = array_values(array_unique(array_map(fn($s) => $s['airline'] ?? null, $tripSegs)));
        $operating = array_map(fn($s) => $s['opFltAirline'] ?? null, $tripSegs);

        // Ancillary availability flags
        $ancillaries = $payload['ancillaryAvailability'] ?? [];
        $paidBag  = (bool)($ancillaries['paidBag']  ?? false);
        $paidSeat = (bool)($ancillaries['paidSeat'] ?? false);

        // Compose normalized offer
        $offer = [
            'id' => $firstSeg['segmentId'] ?? null,
            'type' => 'precise_pricing',

            'solutionKey' => $solution['solutionKey'] ?? null,
            'solutionId'  => $solution['solutionId'] ?? null,

            'fareType'       => $solution['fareType'] ?? null,
            'platingCarrier' => $solution['platingCarrier'] ?? null,
            'bookingWithoutCard' => (int)($solution['bookingWithoutCard'] ?? 0),

            'marketingCarriers' => array_values(array_filter($marketing)),
            'operatingCarriers' => $operating,

            'flightIds' => $flightIds,
            'segments'  => $tripSegs,

            'origin'        => $firstSeg['departure'] ?? null,
            'destination'   => $lastSeg['arrival'] ?? null,
            'departureTime' => self::dt($firstSeg['departureDate'] ?? null),
            'arrivalTime'   => self::dt($lastSeg['arrivalDate'] ?? null),

            'journeyTime'   => $firstF['journeyTime']   ?? null,
            'transferCount' => $firstF['transferCount'] ?? null,
            'stops'         => max(count($tripSegs) - 1, 0),
            'terminals'     => [
                'from' => $firstSeg['departureTerminal'] ?? null,
                'to'   => $lastSeg['arrivalTerminal'] ?? null,
            ],

            'equipment'      => $firstSeg['equipment']   ?? null,
            'cabin'          => $firstSeg['cabinClass']  ?? null,
            'bookingCode'    => $firstSeg['bookingCode'] ?? null,
            'availabilityCount' => $firstSeg['availabilityCount'] ?? 0,

            'baggage' => [
                'adt' => [
                    'checkedBySegment' => $adtChecked,
                    'carryOnBySegment' => $adtCarry,
                ],
                // raw “baggages” field from precise pricing (string PC/weight per leg index)
                'rawByIndex' => $solution['baggages'] ?? null,
            ],

            'rules' => ['adt' => $rulesADT],

            'priceBreakdown' => [
                'currency' => $currency,

                // Per PTC
                'ADT' => [
                    'count'  => $ptc['ADT']['count'],
                    'fare'   => $ptc['ADT']['fare'],
                    'taxes'  => $ptc['ADT']['tax'],
                    'perPax' => $ptcTotals['ADT']['perPax'],
                    'total'  => $ptcTotals['ADT']['sum'],
                ],
                'CHD' => [
                    'count'  => $ptc['CHD']['count'],
                    'fare'   => $ptc['CHD']['fare'],
                    'taxes'  => $ptc['CHD']['tax'],
                    'perPax' => $ptcTotals['CHD']['perPax'],
                    'total'  => $ptcTotals['CHD']['sum'],
                ],
                'INF' => [
                    'count'  => $ptc['INF']['count'],
                    'fare'   => $ptc['INF']['fare'],
                    'taxes'  => $ptc['INF']['tax'],
                    'perPax' => $ptcTotals['INF']['perPax'],
                    'total'  => $ptcTotals['INF']['sum'],
                ],

                // Fees (ticketing/platform/merchant/qCharge…)
                'fees' => $fees,
                'feesTotal' => $feeTotal,

                // Grand total for the entire booking party
                'grandTotal' => $grand,
            ],

            'ancillaryAvailability' => [
                'paidBag'  => $paidBag,
                'paidSeat' => $paidSeat,
            ],
        ];

        return $offer;
    }

    private static function dt($ms)
    {
        return $ms ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }
}
