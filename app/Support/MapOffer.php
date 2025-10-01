<?php

namespace App\Support;

final class MapOffer
{
    /**
     *
     * Normalize PKFare "data" payload to UI-ready offers.
     * @param array $payload The PKFare "data" array (solutions, flights, segments, shoppingKey, etc.)
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $payload): array
    {
        $segments = [];
        foreach (($payload['segments'] ?? []) as $s) {
            $segments[$s['segmentId']] = $s;
        }

        $flights = [];
        foreach (($payload['flights'] ?? []) as $f) {
            $flights[$f['flightId']] = $f;
        }

        $solutions = $payload['solutions'] ?? [];
        $out = [];

        foreach ($solutions as $sol) {
            $journeyKeys = array_keys($sol['journeys'] ?? []);
            if (!$journeyKeys) continue;

            $flightIds = [];
            foreach ($journeyKeys as $k) {
                $flightIds = array_merge($flightIds, $sol['journeys'][$k] ?? []);
            }
            if (!$flightIds) continue;

            // Flatten segment IDs preserving order (note provider key "segmengtIds")
            $segIds = [];
            foreach ($flightIds as $fid) {
                $segIds = array_merge($segIds, $flights[$fid]['segmengtIds'] ?? []);
            }

            $tripSegs = array_values(array_filter(array_map(fn($id) => $segments[$id] ?? null, $segIds)));
            if (!$tripSegs) continue;

            $firstSeg = $tripSegs[0];
            $lastSeg  = $tripSegs[count($tripSegs) - 1];
            $firstF   = $flights[$flightIds[0]] ?? null;

            // 1-based index -> segmentId
            $idxToSegId = [];
            foreach ($tripSegs as $i => $s) {
                $idxToSegId[$i + 1] = $s['segmentId'];
            }

            // Baggage (ADT)
            $adtChecked = []; $adtCarry = [];
            foreach (($sol['baggageMap']['ADT'] ?? []) as $b) {
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

            // Rules (ADT)
            $rulesADT = [];
            foreach (($sol['miniRuleMap']['ADT'] ?? []) as $block) {
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

            // Price breakdown
            $currency = $sol['currency'] ?? 'USD';
            $base     = (float)($sol['adtFare'] ?? 0);
            $tax      = (float)($sol['adtTax'] ?? 0);
            $q        = (float)($sol['qCharge'] ?? 0);
            $tkt      = (float)($sol['tktFee'] ?? 0);
            $plat     = (float)($sol['platformServiceFee'] ?? 0);
            $merch    = (float)($sol['merchantFee'] ?? 0);
            $total    = $base + $tax + $q + $tkt + $plat + $merch;

            // Carriers, last ticketing
            $marketing = array_values(array_unique(array_map(fn($s) => $s['airline'] ?? null, $tripSegs)));
            $operating = array_map(fn($s) => $s['opFltAirline'] ?? null, $tripSegs);

            $lastTktIso = null;
            if ($firstF && !empty($firstF['lastTktTime'])) {
                $lastTktIso = date(DATE_ATOM, ((int)$firstF['lastTktTime']) / 1000);
            }

            $out[] = [
                'id' => $firstSeg['segmentId'] ?? null,
                'solutionKey' => $sol['solutionKey'] ?? null,
                'solutionId'  => $sol['solutionId'] ?? null,
                'shoppingKey' => $payload['shoppingKey'] ?? null,

                'platingCarrier' => $sol['platingCarrier'] ?? null,
                'marketingCarriers' => array_values(array_filter($marketing)),
                'operatingCarriers' => $operating,

                'flightNumber' => ($firstSeg['airline'] ?? '') . ($firstSeg['flightNum'] ?? ''),
                'origin' => $firstSeg['departure'] ?? null,
                'destination' => $lastSeg['arrival'] ?? null,
                'departureTime' => self::dt($firstSeg['departureDate'] ?? null),
                'arrivalTime'   => self::dt($lastSeg['arrivalDate'] ?? null),
                'journeyTime'   => $firstF['journeyTime'] ?? null,
                'transferCount' => $firstF['transferCount'] ?? null,
                'stops'         => max(count($tripSegs) - 1, 0),
                'terminals'     => [
                    'from' => $firstSeg['departureTerminal'] ?? null,
                    'to'   => $lastSeg['arrivalTerminal'] ?? null
                ],
                'equipment'      => $firstSeg['equipment'] ?? null,
                'cabin'          => $firstSeg['cabinClass'] ?? null,
                'bookingCode'    => $firstSeg['bookingCode'] ?? null,
                'availabilityCount' => $firstSeg['availabilityCount'] ?? 0,

                'isVI' => in_array('VI', $sol['category'] ?? [], true),

                'priceBreakdown' => [
                    'currency' => $currency,
                    'base' => $base,
                    'taxes' => $tax,
                    'qCharge' => $q,
                    'tktFee' => $tkt,
                    'platformServiceFee' => $plat,
                    'merchantFee' => $merch,
                    'total' => $total,
                ],

                'baggage' => [
                    'adt' => [
                        'checkedBySegment' => $adtChecked,
                        'carryOnBySegment' => $adtCarry,
                    ],
                ],

                'rules' => ['adt' => $rulesADT],

                'flightIds' => $flightIds,
                'segments'  => $tripSegs,

                'lastTktTime' => $lastTktIso,
                'expired' => $lastTktIso ? (strtotime($lastTktIso) < time()) : false,
            ];
        }

        return $out;
    }

    private static function dt($ms)
    {
        return $ms ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }
}
