<?php

namespace App\Support;

final class MapOffer
{
    /**
     * Normalize PKFare "data" payload to UI-ready offers.
     *
     * @param array $payload The PKFare "data" array (solutions, flights, segments, shoppingKey, etc.)
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $payload): array
    {
        // index segments by segmentId
        $segments = [];
        foreach (($payload['segments'] ?? []) as $s) {
            if (!isset($s['segmentId'])) continue;
            $segments[$s['segmentId']] = $s;
        }

        // index flights by flightId
        $flights = [];
        foreach (($payload['flights'] ?? []) as $f) {
            if (!isset($f['flightId'])) continue;
            $flights[$f['flightId']] = $f;
        }

        $solutions = $payload['solutions'] ?? [];
        $out = [];

        foreach ($solutions as $sol) {

            /* ---------------- Passengers ---------------- */
            $passengers = [
                'adults'   => (int)($sol['adults'] ?? 1),
                'children' => (int)($sol['children'] ?? 0),
                'infants'  => (int)($sol['infants'] ?? 0),
            ];
            $passengers['total'] = $passengers['adults'] + $passengers['children'] + $passengers['infants'];

            /* ---------------- Journeys (sorted numerically) ---------------- */
            $journeyKeysRaw = array_keys($sol['journeys'] ?? []);
            $journeyNums = [];
            foreach ($journeyKeysRaw as $k) {
                if (preg_match('/journey_(\d+)/', $k, $m)) {
                    $journeyNums[] = (int)$m[1];
                }
            }
            sort($journeyNums);
            $journeyKeys = array_map(static fn($n) => "journey_$n", $journeyNums);
            if (!$journeyKeys) continue;

            $allFlightIds = [];
            $tripSegs = [];     // global ordered segment objects (concatenated legs)
            $legData = [];      // per-journey leg summaries

            /* =========================================================
               Carve each journey into a separate "leg"
               ========================================================= */
            foreach ($journeyKeys as $jKey) {
                $jFlightIds = $sol['journeys'][$jKey] ?? [];
                if (!$jFlightIds) {
                    // If a journey key exists but is empty, skip only this journey.
                    continue;
                }

                $jSegIds = [];
                $jSegToFlight = [];

                foreach ($jFlightIds as $jfid) {
                    $allFlightIds[] = $jfid;

                    // NOTE: this key name is intentional per your upstream
                    foreach ($flights[$jfid]['segmengtIds'] ?? [] as $sid) {
                        $jSegIds[] = $sid;
                        $jSegToFlight[$sid] = $jfid;
                    }
                }

                // Inject flightId onto each segment; filter out any that aren't defined
                $legSegs = array_values(array_filter(array_map(
                    static function ($id) use ($segments, $jSegToFlight) {
                        if (!isset($segments[$id])) return null;
                        $seg = $segments[$id];
                        $seg['flightId'] = $jSegToFlight[$id] ?? null;
                        return $seg;
                    },
                    $jSegIds
                )));

                // Enforce chronological order within this leg
                usort($legSegs, static function ($a, $b) {
                    return (int)($a['departureDate'] ?? 0) <=> (int)($b['departureDate'] ?? 0);
                });

                if (empty($legSegs)) {
                    // nothing valid about this leg → skip it
                    continue;
                }

                // Append into global ordered trip segments
                $tripSegs = array_merge($tripSegs, $legSegs);

                // Build a leg summary (used by frontend multi/return rendering)
                $firstSegLeg = $legSegs[0];
                $lastSegLeg  = $legSegs[count($legSegs) - 1];

                // Try to pull timing/metrics from the first flight of this journey
                $firstFlightForLeg = isset($jFlightIds[0]) ? ($flights[$jFlightIds[0]] ?? null) : null;

                $legData[] = [
                    'flightIds'      => $jFlightIds,
                    'segments'       => array_values($legSegs),
                    'flightNumber'   => ($firstSegLeg['airline'] ?? '') . ($firstSegLeg['flightNum'] ?? ''),
                    'origin'         => $firstSegLeg['departure'] ?? null,
                    'destination'    => $lastSegLeg['arrival'] ?? null,
                    'departureTime'  => self::dt($firstSegLeg['departureDate'] ?? null),
                    'arrivalTime'    => self::dt($lastSegLeg['arrivalDate'] ?? null),
                    'journeyTime'    => $firstFlightForLeg['journeyTime'] ?? null,
                    'transferCount'  => $firstFlightForLeg['transferCount'] ?? null,
                    'stops'          => max(count($legSegs) - 1, 0),
                    'terminals'      => [
                        'from' => $firstSegLeg['departureTerminal'] ?? null,
                        'to'   => $lastSegLeg['arrivalTerminal'] ?? null,
                    ],
                    'equipment'      => $firstSegLeg['equipment'] ?? null,
                    'cabin'          => $firstSegLeg['cabinClass'] ?? null,
                    'bookingCode'    => $firstSegLeg['bookingCode'] ?? null,
                    'availabilityCount' => $firstSegLeg['availabilityCount'] ?? 0,
                ];
            }

            // If after carving there are no segments at all, skip this solution
            if (empty($tripSegs)) continue;

            /* ---------------- Baggage/Rules mapping need global index → segmentId ---------------- */
            $idxToSegId = [];
            foreach ($tripSegs as $i => $s) {
                if (!isset($s['segmentId'])) continue;
                $idxToSegId[$i + 1] = $s['segmentId']; // PKFare uses 1-based indices
            }

            // Baggage (ADT)
            $adtChecked = [];
            $adtCarry = [];
            foreach (($sol['baggageMap']['ADT'] ?? []) as $b) {
                foreach (($b['segmentIndexList'] ?? []) as $n) {
                    $sid = $idxToSegId[$n] ?? null;
                    if (!$sid) continue;
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
                    static fn($n) => $idxToSegId[$n] ?? null,
                    $block['segmentIndex'] ?? []
                )));
                $mini = array_map(static function ($r) {
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

            /* ---------------- Price breakdown (per pax * counts) ---------------- */
            $currency = $sol['currency'] ?? 'USD';

            $adtBase = (float)($sol['adtFare'] ?? 0);
            $chdBase = (float)($sol['chdFare'] ?? 0);
            $base = $adtBase * $passengers['adults'] + $chdBase * $passengers['children'];

            $adtTax = (float)($sol['adtTax'] ?? 0);
            $chdTax = (float)($sol['chdTax'] ?? 0);
            $tax = $adtTax * $passengers['adults'] + $chdTax * $passengers['children'];

            $q    = (float)($sol['qCharge'] ?? 0);
            $tkt  = (float)($sol['tktFee'] ?? 0);
            $plat = (float)($sol['platformServiceFee'] ?? 0);
            $merch= (float)($sol['merchantFee'] ?? 0);

            $total = $base + $tax + $q + $tkt + $plat + $merch;

            /* ---------------- Carriers & last ticketing time ---------------- */
            $marketing = array_values(array_unique(array_filter(array_map(
                static fn($s) => $s['airline'] ?? null,
                $tripSegs
            ))));
            $operating = array_values(array_filter(array_map(
                static fn($s) => $s['opFltAirline'] ?? null,
                $tripSegs
            )));

            $lastTktTimes = [];
            foreach (array_unique($allFlightIds) as $fid) {
                if (!empty($flights[$fid]['lastTktTime'])) {
                    $lastTktTimes[] = (int)$flights[$fid]['lastTktTime'] / 1000;
                }
            }
            $lastTktIso = !empty($lastTktTimes) ? date(DATE_ATOM, min($lastTktTimes)) : null;

            /* ---------------- Overall origin/destination & round-trip check ---------------- */
            $numLegs = count($legData);
            $overallOrigin = $legData[0]['origin'] ?? ($tripSegs[0]['departure'] ?? null);
            $overallFinalDestination = $legData[$numLegs - 1]['destination'] ?? ($tripSegs[count($tripSegs) - 1]['arrival'] ?? null);

            // Heuristic: exactly two legs, and they reverse origin/destination → round trip
            $isRoundTrip = $numLegs === 2
                && !empty($legData[0]['origin']) && !empty($legData[1]['origin'])
                && ($legData[0]['destination'] === $legData[1]['origin'])
                && ($legData[0]['origin'] === $legData[1]['destination']);

            // Legacy “flattened” fields (first leg for headers)
            $firstLeg = $legData[0];

            $out[] = [
                'id'           => $tripSegs[0]['segmentId'] ?? null,
                'solutionKey'  => $sol['solutionKey'] ?? null,
                'solutionId'   => $sol['solutionId'] ?? null,
                'shoppingKey'  => $payload['shoppingKey'] ?? null,

                'platingCarrier'    => $sol['platingCarrier'] ?? null,
                'marketingCarriers' => $marketing,
                'operatingCarriers' => $operating,

                'flightNumber'  => ($firstLeg['flightNumber'] ?? (($tripSegs[0]['airline'] ?? '') . ($tripSegs[0]['flightNum'] ?? ''))),
                'origin'        => $overallOrigin,
                'destination'   => $isRoundTrip ? ($legData[0]['destination'] ?? null) : $overallFinalDestination,
                'departureTime' => $firstLeg['departureTime'] ?? self::dt($tripSegs[0]['departureDate'] ?? null),
                'arrivalTime'   => $isRoundTrip
                    ? ($legData[1]['arrivalTime'] ?? null)
                    : self::dt($tripSegs[count($tripSegs) - 1]['arrivalDate'] ?? null),

                // keep some metrics from the first leg for backward compatibility
                'journeyTime'   => $firstLeg['journeyTime'] ?? null,
                'transferCount' => $firstLeg['transferCount'] ?? null,
                'stops'         => $firstLeg['stops'] ?? max(count($tripSegs) - 1, 0),
                'terminals'     => $firstLeg['terminals'] ?? [],
                'equipment'     => $firstLeg['equipment'] ?? null,
                'cabin'         => $firstLeg['cabin'] ?? null,
                'bookingCode'   => $firstLeg['bookingCode'] ?? null,
                'availabilityCount' => $firstLeg['availabilityCount'] ?? 0,

                'isVI'         => in_array('VI', $sol['category'] ?? [], true),

                // expose legs for multi/return UI (prevents mixing)
                'legs'         => $legData,
                'passengers'   => $passengers,

                'priceBreakdown' => [
                    'currency'           => $currency,
                    'base'               => $base,
                    'taxes'              => $tax,
                    'qCharge'            => $q,
                    'tktFee'             => $tkt,
                    'platformServiceFee' => $plat,
                    'merchantFee'        => $merch,
                    'total'              => $total,
                ],

                'baggage' => [
                    'adt' => [
                        'checkedBySegment' => $adtChecked,
                        'carryOnBySegment' => $adtCarry,
                    ],
                ],

                'rules'     => ['adt' => $rulesADT],
                'flightIds' => $allFlightIds,
                'segments'  => $tripSegs,

                'lastTktTime' => $lastTktIso,
                'expired'     => $lastTktIso ? (strtotime($lastTktIso) < time()) : false,
            ];
        }

        return $out;
    }

    private static function dt($ms)
    {
        return $ms ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }
}
