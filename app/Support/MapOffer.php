<?php

namespace App\Support;

final class MapOffer
{
    /**
     * Normalize PKFare "data" payload to UI-ready offers.
     * @param array $payload
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $payload): array
    {
        // Index segments by id
        $segmentsById = [];
        foreach (($payload['segments'] ?? []) as $s) {
            $segmentsById[$s['segmentId']] = $s;
        }

        // Index flights by id
        $flightsById = [];
        foreach (($payload['flights'] ?? []) as $f) {
            $flightsById[$f['flightId']] = $f;
        }

        $solutions = $payload['solutions'] ?? [];
        $out = [];

        foreach ($solutions as $sol) {
            // Passengers
            $passengers = [
                'adults'   => (int)($sol['adults'] ?? 1),
                'children' => (int)($sol['children'] ?? 0),
                'infants'  => (int)($sol['infants'] ?? 0),
            ];
            $passengers['total'] = $passengers['adults'] + $passengers['children'] + $passengers['infants'];

            // Journeys -> legs (preserve order: journey_0, journey_1, ...)
            $journeys = $sol['journeys'] ?? [];
            if (!$journeys) continue;

            // Sort by natural key just in case
            uksort($journeys, static function ($a, $b) {
                return strnatcmp($a, $b);
            });

            $legs = [];
            $globalSegList = [];
            $globalIdxToSegId = []; // 1-based index across ALL legs
            $marketingCarriersSet = [];
            $operatingCarriersSet = [];
            $flightIdsAll = [];
            $lastTktCandidates = [];

            foreach ($journeys as $jKey => $flightIdsOfJourney) {
                $legFlightIds = array_values($flightIdsOfJourney ?? []);
                if (!$legFlightIds) continue;

                $legSegIds = [];
                $legSegments = [];

                // Build segments for this leg in order of flights then segmengtIds
                foreach ($legFlightIds as $fid) {
                    $flight = $flightsById[$fid] ?? null;
                    if (!$flight) continue;

                    $flightIdsAll[] = $fid;

                    // last ticketing (collect for later min)
                    if (!empty($flight['lastTktTime'])) {
                        $iso = self::parseDateFlex($flight['lastTktTime']);
                        if ($iso) $lastTktCandidates[] = $iso;
                    }

                    foreach (($flight['segmengtIds'] ?? []) as $sid) {
                        if (!isset($segmentsById[$sid])) continue;
                        $seg = $segmentsById[$sid];

                        // enrich carriers sets
                        if (!empty($seg['airline'])) {
                            $marketingCarriersSet[$seg['airline']] = true;
                        }
                        $op = $seg['opFltAirline'] ?? null;
                        if ($op) {
                            $operatingCarriersSet[$op] = true;
                        }

                        $legSegIds[] = $sid;
                        $legSegments[] = array_merge($seg, [
                            'flightId' => $fid,
                        ]);

                        // global running index (1-based)
                        $globalIdxToSegId[count($globalSegList) + 1] = $sid;
                        $globalSegList[] = $sid;
                    }
                }

                if (!$legSegments) continue;

                // Per-leg summary
                $firstSeg = $legSegments[0];
                $lastSeg  = $legSegments[count($legSegments) - 1];

                // Use first flight meta if available (PKFare journeyTime is per flight object)
                $firstFlight = $flightsById[$legFlightIds[0]] ?? null;

                // Per-leg 1-based index map (useful for UI)
                $legIdxToSegId = [];
                foreach ($legSegments as $i => $s) {
                    $legIdxToSegId[$i + 1] = $s['segmentId'];
                }

                $legs[] = [
                    'flightIds' => $legFlightIds,
                    'segments'  => $legSegments,
                    'origin'    => $firstSeg['departure'] ?? null,
                    'destination'=> $lastSeg['arrival'] ?? null,
                    'departureTime' => self::dtMs($firstSeg['departureDate'] ?? null),
                    'arrivalTime'   => self::dtMs($lastSeg['arrivalDate'] ?? null),
                    'journeyTime'   => $firstFlight['journeyTime'] ?? null,
                    'transferCount' => $firstFlight['transferCount'] ?? max(count($legSegments) - 1, 0),
                    'stops'         => max(count($legSegments) - 1, 0),
                    'terminals'     => [
                        'from' => $firstSeg['departureTerminal'] ?? null,
                        'to'   => $lastSeg['arrivalTerminal'] ?? null,
                    ],
                    'idxToSegId'    => $legIdxToSegId,
                ];
            }

            if (!$legs) continue;

            // Map baggage/rules using GLOBAL 1-based segment order across all legs
            $adtChecked = []; $adtCarry = [];
            foreach (($sol['baggageMap']['ADT'] ?? []) as $b) {
                foreach (($b['segmentIndexList'] ?? []) as $n) {
                    $sid = $globalIdxToSegId[$n] ?? null; if (!$sid) continue;
                    if (isset($b['baggageAmount']) || isset($b['baggageWeight'])) {
                        $adtChecked[$sid] = [
                            'amount' => $b['baggageAmount'] ?? null,
                            'weight' => $b['baggageWeight'] ?? null,
                        ];
                    }
                    if (isset($b['carryOnAmount']) || isset($b['carryOnWeight']) || isset($b['carryOnSize'])) {
                        $adtCarry[$sid] = [
                            'amount' => $b['carryOnAmount'] ?? null,
                            'weight' => $b['carryOnWeight'] ?? null,
                            'size'   => $b['carryOnSize'] ?? null,
                        ];
                    }
                }
            }

            $rulesADT = [];
            foreach (($sol['miniRuleMap']['ADT'] ?? []) as $block) {
                $ids = [];
                foreach (($block['segmentIndex'] ?? []) as $n) {
                    $sid = $globalIdxToSegId[$n] ?? null;
                    if ($sid) $ids[] = $sid;
                }
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

            // Pricing
            $currency = $sol['currency'] ?? 'USD';
            $base     = (float)($sol['adtFare'] ?? 0);
            $tax      = (float)($sol['adtTax'] ?? 0);
            $q        = (float)($sol['qCharge'] ?? 0);
            $tkt      = (float)($sol['tktFee'] ?? 0);
            $plat     = (float)($sol['platformServiceFee'] ?? 0);
            $merch    = (float)($sol['merchantFee'] ?? 0);
            $total    = $base + $tax + $q + $tkt + $plat + $merch;

            // Marketing/Operating carriers
            $marketing = array_values(array_keys(array_filter($marketingCarriersSet)));
            $operating = array_values(array_keys(array_filter($operatingCarriersSet)));

            // Headline info (use leg 0 for display)
            $head = $legs[0];
            $tail = $legs[count($legs) - 1];
            $firstSeg = $head['segments'][0];
            $idForCard = $firstSeg['segmentId'] ?? null;

            // Last ticketing: choose the earliest across used flights
            $lastTktIso = null;
            if ($lastTktCandidates) {
                sort($lastTktCandidates);
                $lastTktIso = $lastTktCandidates[0];
            }

            // Expiry flag
            $expired = $lastTktIso ? (strtotime($lastTktIso) < time()) : false;

            $out[] = [
                'id' => $idForCard,
                'solutionKey' => $sol['solutionKey'] ?? null,
                'solutionId'  => $sol['solutionId'] ?? null,
                'shoppingKey' => $payload['shoppingKey'] ?? null,

                'platingCarrier' => $sol['platingCarrier'] ?? null,
                'marketingCarriers' => $marketing,
                'operatingCarriers' => $operating,

                // Roundtrip-friendly headline (first leg’s origin & destination)
                'origin' => $head['origin'],
                'destination' => $head['destination'],

                'summary' => [
                    'legs' => $legs,
                    // convenience global mapping for supplier indices
                    'globalIdxToSegId' => $globalIdxToSegId,
                ],

                'passengers' => $passengers,

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

                'flightIds' => array_values(array_unique($flightIdsAll)),
                'segments'  => self::segmentsFromIds($globalSegList, $segmentsById, $flightsById),

                'lastTktTime' => $lastTktIso,
                'expired' => $expired,
            ];
        }

        return $out;
    }

    /** Convert epoch ms to ISO-8601 if present. */
    private static function dtMs($ms): ?string
    {
        return is_numeric($ms) ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }

    /**
     * Parse a value that might be:
     *  - epoch ms (int/num string)
     *  - "YYYY-mm-dd HH:ii:ss" string
     * Returns ISO-8601 or null.
     */
    private static function parseDateFlex($value): ?string
    {
        if ($value === null || $value === '') return null;

        // numeric epoch ms?
        if (is_numeric($value)) {
            return date(DATE_ATOM, ((int)$value) / 1000);
        }

        // try strtotime on string
        $ts = strtotime((string)$value);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }

    /** Helper to materialize global segments with attached flightId for convenience. */
    private static function segmentsFromIds(array $ids, array $segmentsById, array $flightsById): array
    {
        $out = [];
        // Build map segId -> flightId by scanning flights once
        $segToFlight = [];
        foreach ($flightsById as $fid => $flight) {
            foreach (($flight['segmengtIds'] ?? []) as $sid) {
                $segToFlight[$sid] = $fid;
            }
        }
        foreach ($ids as $sid) {
            if (!isset($segmentsById[$sid])) continue;
            $seg = $segmentsById[$sid];
            $seg['flightId'] = $segToFlight[$sid] ?? null;
            $out[] = $seg;
        }
        return $out;
    }
}
