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

            // Passengers summary
            $passengers = [
                'adults'   => (int)($sol['adults'] ?? 1),
                'children' => (int)($sol['children'] ?? 0),
                'infants'  => (int)($sol['infants'] ?? 0),
            ];
            $passengers['total'] = $passengers['adults'] + $passengers['children'] + $passengers['infants'];

            $journeyKeysRaw = array_keys($sol['journeys'] ?? []);
            $journeyNums = [];
            foreach ($journeyKeysRaw as $k) {
                if (preg_match('/journey_(\d+)/', $k, $m)) {
                    $journeyNums[] = (int)$m[1];
                }
            }
            sort($journeyNums);
            $journeyKeys = array_map(fn($n) => "journey_$n", $journeyNums);

            if (!$journeyKeys) continue;

            $allFlightIds = [];
            $tripSegs = []; // global ordered segments for indexing
            $legData = [];

            foreach ($journeyKeys as $jKey) {
                $jFlightIds = $sol['journeys'][$jKey] ?? [];
                $jSegIds = [];
                $jSegToFlight = [];
                foreach ($jFlightIds as $jfid) {
                    $allFlightIds[] = $jfid;
                    foreach ($flights[$jfid]['segmengtIds'] ?? [] as $sid) {
                        $jSegIds[] = $sid;
                        $jSegToFlight[$sid] = $jfid;
                    }
                }

                // Leg segments with injected flightId
                $legSegs = array_values(array_filter(array_map(function ($id) use ($segments, $jSegToFlight) {
                    if (!isset($segments[$id])) return null;
                    $seg = $segments[$id];
                    $seg['flightId'] = $jSegToFlight[$id] ?? null;
                    return $seg;
                }, $jSegIds)));

                $tripSegs = array_merge($tripSegs, $legSegs);

                if (empty($legSegs)) continue 2; // skip leg, but continue outer if all empty

                $firstSegLeg = $legSegs[0];
                $lastSegLeg  = end($legSegs);
                $firstFLeg   = $flights[$jFlightIds[0]] ?? null;

                $legData[] = [
                    'flightIds' => $jFlightIds,
                    'segments' => $legSegs,
                    'flightNumber' => ($firstSegLeg['airline'] ?? '') . ($firstSegLeg['flightNum'] ?? ''),
                    'origin' => $firstSegLeg['departure'] ?? null,
                    'destination' => $lastSegLeg['arrival'] ?? null,
                    'departureTime' => self::dt($firstSegLeg['departureDate'] ?? null),
                    'arrivalTime'   => self::dt($lastSegLeg['arrivalDate'] ?? null),
                    'journeyTime'   => $firstFLeg['journeyTime'] ?? null,
                    'transferCount' => $firstFLeg['transferCount'] ?? null,
                    'stops'         => max(count($legSegs) - 1, 0),
                    'terminals'     => [
                        'from' => $firstSegLeg['departureTerminal'] ?? null,
                        'to'   => $lastSegLeg['arrivalTerminal'] ?? null
                    ],
                    'equipment'      => $firstSegLeg['equipment'] ?? null,
                    'cabin'          => $firstSegLeg['cabinClass'] ?? null,
                    'bookingCode'    => $firstSegLeg['bookingCode'] ?? null,
                    'availabilityCount' => $firstSegLeg['availabilityCount'] ?? 0,
                ];
            }

            if (empty($tripSegs)) continue;

            // 1-based index -> segmentId (global)
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

            // Price breakdown (total for all passengers)
            $currency = $sol['currency'] ?? 'USD';
            $adtBase = (float)($sol['adtFare'] ?? 0);
            $chdBase = (float)($sol['chdFare'] ?? 0);
            $base = $adtBase * $passengers['adults'] + $chdBase * $passengers['children'];
            $adtTax = (float)($sol['adtTax'] ?? 0);
            $chdTax = (float)($sol['chdTax'] ?? 0);
            $tax = $adtTax * $passengers['adults'] + $chdTax * $passengers['children'];
            $q = (float)($sol['qCharge'] ?? 0);
            $tkt = (float)($sol['tktFee'] ?? 0);
            $plat = (float)($sol['platformServiceFee'] ?? 0);
            $merch = (float)($sol['merchantFee'] ?? 0);
            $total = $base + $tax + $q + $tkt + $plat + $merch;

            // Carriers
            $marketing = array_values(array_unique(array_filter(array_map(fn($s) => $s['airline'] ?? null, $tripSegs))));
            $operating = array_map(fn($s) => $s['opFltAirline'] ?? null, $tripSegs);

            // Last ticketing time (earliest across all flights)
            $lastTktTimes = [];
            foreach (array_unique($allFlightIds) as $fid) {
                $lt = $flights[$fid]['lastTktTime'] ?? null;
                if ($lt !== null) {
                    $lastTktTimes[] = (int)$lt / 1000;
                }
            }
            $lastTktIso = !empty($lastTktTimes) ? date(DATE_ATOM, min($lastTktTimes)) : null;

            // Overall origin/destination
            $numLegs = count($legData);
            $isRoundTrip = $numLegs === 2 &&
                !empty($legData[0]['origin']) &&
                !empty($legData[1]['origin']) &&
                $legData[0]['destination'] === $legData[1]['origin'] &&
                $legData[0]['origin'] === $legData[1]['destination'];
            $overallOrigin = $legData[0]['origin'] ?? null;
            $overallDestination = $isRoundTrip ? ($legData[0]['destination'] ?? null) : (end($legData)['destination'] ?? null);

            // First leg for legacy fields
            $firstLeg = $legData[0] ?? null;

            $out[] = [
                'id' => ($firstLeg['segments'][0]['segmentId'] ?? null),
                'solutionKey' => $sol['solutionKey'] ?? null,
                'solutionId'  => $sol['solutionId'] ?? null,
                'shoppingKey' => $payload['shoppingKey'] ?? null,

                'platingCarrier' => $sol['platingCarrier'] ?? null,
                'marketingCarriers' => $marketing,
                'operatingCarriers' => $operating,

                'flightNumber' => $firstLeg['flightNumber'] ?? null,
                'origin' => $overallOrigin,
                'destination' => $overallDestination,
                'departureTime' => $firstLeg['departureTime'] ?? null,
                'arrivalTime'   => $isRoundTrip ? ($legData[1]['arrivalTime'] ?? null) : ($firstLeg['arrivalTime'] ?? null),
                'journeyTime'   => $firstLeg['journeyTime'] ?? null,
                'transferCount' => $firstLeg['transferCount'] ?? null,
                'stops'         => $firstLeg['stops'] ?? 0,
                'terminals'     => $firstLeg['terminals'] ?? [],
                'equipment'      => $firstLeg['equipment'] ?? null,
                'cabin'          => $firstLeg['cabin'] ?? null,
                'bookingCode'    => $firstLeg['bookingCode'] ?? null,
                'availabilityCount' => $firstLeg['availabilityCount'] ?? 0,

                'isVI' => in_array('VI', $sol['category'] ?? [], true),

                'passengers' => $passengers,
                'legs' => $legData,

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

                'flightIds' => $allFlightIds,
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