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
            if (!empty($s['segmentId'])) {
                $segmentsById[$s['segmentId']] = $s;
            }
        }

        // Index flights by id
        $flightsById = [];
        foreach (($payload['flights'] ?? []) as $f) {
            if (!empty($f['flightId'])) {
                $flightsById[$f['flightId']] = $f;
            }
        }

        $solutions = $payload['solutions'] ?? [];
        if (!$solutions) {
            return [];
        }

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
            if (!$journeys || !is_array($journeys)) {
                continue;
            }
            uksort($journeys, static fn($a,$b) => strnatcmp((string)$a,(string)$b));

            $legs = [];
            $globalSegList = [];
            $globalIdxToSegId = []; // 1-based across all legs
            $marketingCarriersSet = [];
            $operatingCarriersSet = [];
            $flightIdsAll = [];
            $lastTktCandidates = [];

            foreach ($journeys as $jKey => $flightIdsOfJourney) {
                $legFlightIds = array_values(array_filter((array)$flightIdsOfJourney));
                if (!$legFlightIds) continue;

                $legSegments = [];

                foreach ($legFlightIds as $fid) {
                    $flight = $flightsById[$fid] ?? null;
                    if (!$flight) continue;

                    $flightIdsAll[] = $fid;

                    if (!empty($flight['lastTktTime'])) {
                        $iso = self::parseDateFlex($flight['lastTktTime']);
                        if ($iso) $lastTktCandidates[] = $iso;
                    }

                    // NOTE: supplier field is "segmengtIds" (typo preserved)
                    $segIds = $flight['segmengtIds'] ?? [];
                    foreach ((array)$segIds as $sid) {
                        if (!isset($segmentsById[$sid])) continue;
                        $seg = $segmentsById[$sid];

                        // Collect carriers
                        if (!empty($seg['airline'])) {
                            $marketingCarriersSet[$seg['airline']] = true;
                        }
                        $op = trim((string)($seg['opFltAirline'] ?? ''));
                        if ($op !== '') {
                            $operatingCarriersSet[$op] = true;
                        }

                        // Attach flightId & ISO helpers (preserve raw)
                        $legSegments[] = self::withIsoTimes($seg + ['flightId' => $fid]);

                        // Global 1-based index mapping
                        $globalIdxToSegId[count($globalSegList) + 1] = $sid;
                        $globalSegList[] = $sid;
                    }
                }

                if (!$legSegments) continue;

                // Defensively keep time order
                usort($legSegments, static function (array $a, array $b): int {
                    $ta = is_numeric($a['departureDate'] ?? null) ? (int)$a['departureDate'] : 0;
                    $tb = is_numeric($b['departureDate'] ?? null) ? (int)$b['departureDate'] : 0;
                    return $ta <=> $tb;
                });

                $firstSeg = $legSegments[0];
                $lastSeg  = $legSegments[count($legSegments) - 1];

                $firstFlight  = $flightsById[$legFlightIds[0]] ?? null;
                $journeyTime  = $firstFlight['journeyTime']   ?? null;
                $transferCnt  = $firstFlight['transferCount'] ?? max(count($legSegments) - 1, 0);
                $stops        = max(count($legSegments) - 1, 0);

                // Per-leg 1-based index map
                $legIdxToSegId = [];
                foreach ($legSegments as $i => $s) {
                    $legIdxToSegId[$i + 1] = $s['segmentId'];
                }

                $legs[] = [
                    'flightIds'     => $legFlightIds,
                    'segments'      => $legSegments, // full objects (with flightId & iso helpers)
                    'origin'        => $firstSeg['departure'] ?? null,
                    'destination'   => $lastSeg['arrival'] ?? null,
                    'departureTime' => self::dtMs($firstSeg['departureDate'] ?? null),
                    'arrivalTime'   => self::dtMs($lastSeg['arrivalDate'] ?? null),
                    'journeyTime'   => $journeyTime,
                    'transferCount' => $transferCnt,
                    'stops'         => $stops,
                    'terminals'     => [
                        'from' => $firstSeg['departureTerminal'] ?? null,
                        'to'   => $lastSeg['arrivalTerminal'] ?? null,
                    ],
                    'idxToSegId'    => $legIdxToSegId,
                ];
            }

            if (!$legs) continue;

            // Map baggage/rules using GLOBAL indices
            [$adtChecked, $adtCarry] = self::mapBaggageByGlobalIndex(
                $sol['baggageMap']['ADT'] ?? [],
                $globalIdxToSegId
            );

            $rulesADT = self::mapMiniRulesByGlobalIndex(
                $sol['miniRuleMap']['ADT'] ?? [],
                $globalIdxToSegId
            );

            // Pricing (multi-PTC)
            $currency   = $sol['currency'] ?? 'USD';
            $priceBreak = self::buildPriceBreakdown($sol, $passengers, $currency);

            $supplier    = $payload['supplier']    ?? ($sol['supplier'] ?? null);
            $solutionId  = $sol['solutionId']      ?? null;
            $solutionKey = $sol['solutionKey']     ?? null;
            $shoppingKey = $payload['shoppingKey'] ?? null;
            $plating     = $sol['platingCarrier']  ?? null;

            $coherenceKey = implode('|', [
                $solutionId ?: 'NA',
                $solutionKey ?: 'NAKEY',
                $supplier ?: 'SRC',
                $plating ?: 'PLATE',
                $currency ?: 'CUR',
                $shoppingKey ?: 'SHOP',
            ]);

            // Carriers (unique, compact)
            $marketing = array_values(array_keys($marketingCarriersSet));
            $operating = array_values(array_filter(array_keys($operatingCarriersSet)));

            // Safer top-level id
            $head = $legs[0];
            $firstSegForId = $head['segments'][0] ?? null;
            $offerId = implode('|', [
                $solutionId ?: ($firstSegForId['segmentId'] ?? 'OFF'),
                $head['origin'] ?? 'OOO',
                $head['destination'] ?? 'DDD',
                $head['departureTime'] ?? '',
            ]);

            // Last ticketing: earliest across all used flights
            $lastTktIso = null;
            if (!empty($lastTktCandidates)) {
                sort($lastTktCandidates);
                $lastTktIso = $lastTktCandidates[0];
            }
            $expired = $lastTktIso ? (strtotime($lastTktIso) < time()) : false;

            // ---- Stops convenience at offer root (keeps leg stops as-is) ----
            $stopsByLeg = array_map(static fn(array $l): int => max(0, (int)($l['stops'] ?? 0)), $legs);
            $totalStops = array_sum($stopsByLeg);
            $outboundStops = $stopsByLeg[0] ?? null;
            $returnStops = $stopsByLeg[1] ?? null;

            // For single-leg (oneway) offers, expose stops at root for UI convenience.
            // For multi-leg, leave root 'stops' null to avoid ambiguity; use totalStops/stopsByLeg instead.
            $rootStops = (count($legs) === 1) ? ($outboundStops ?? 0) : null;


            $out[] = [
                'id'             => $offerId,
                'solutionKey'    => $solutionKey,
                'solutionId'     => $solutionId,
                'shoppingKey'    => $shoppingKey,
                'supplier'       => $supplier,
                'coherenceKey'   => $coherenceKey,

                'platingCarrier'    => $plating,
                'marketingCarriers' => $marketing,
                'operatingCarriers' => $operating,

                'origin'        => $head['origin'],
                'destination'   => $head['destination'],

                'summary' => [
                    'legs' => array_map(static function (array $leg) use ($coherenceKey): array {
                        $leg['coherenceKey'] = $coherenceKey;
                        return $leg;
                    }, $legs),
                    'globalIdxToSegId' => $globalIdxToSegId,
                ],

                'passengers' => $passengers,

                'priceBreakdown' => $priceBreak,

                'baggage' => [
                    'adt' => [
                        'checkedBySegment' => $adtChecked,
                        'carryOnBySegment' => $adtCarry,
                    ],
                ],

                'rules' => ['adt' => $rulesADT],

                'flightIds' => array_values(array_unique($flightIdsAll)),

                // Full ordered segments across ALL legs (with flightId & iso helpers)
                'segments' => self::segmentsFromIds($globalSegList, $segmentsById, $flightsById),

                'lastTktTime' => $lastTktIso,
                'expired'     => $expired,
                'stops'        => $rootStops,      // only set for single-leg offers
                'totalStops'   => $totalStops,     // sum across all legs
                'stopsByLeg'   => $stopsByLeg,     // indexed in journey order
                'outboundStops'=> $outboundStops,  // leg 0 if exists
                'returnStops'  => $returnStops,    // leg 1 if exists
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Pricing helpers
    // ---------------------------------------------------------------------

    private static function buildPriceBreakdown(array $sol, array $pax, string $currency): array
    {
        // Per-PTC unit fares/taxes
        $unit = [
            'ADT' => [
                'fare' => self::num($sol['adtFare'] ?? 0),
                'tax'  => self::num($sol['adtTax']  ?? 0),
                'count'=> (int)($pax['adults'] ?? 0),
            ],
            'CHD' => [
                'fare' => self::num($sol['chdFare'] ?? 0),
                'tax'  => self::num($sol['chdTax']  ?? 0),
                'count'=> (int)($pax['children'] ?? 0),
            ],
            'INF' => [
                // be defensive about field naming across suppliers
                'fare' => self::num($sol['infFare'] ?? $sol['infantFare'] ?? 0),
                'tax'  => self::num($sol['infTax']  ?? $sol['infantTax']  ?? 0),
                'count'=> (int)($pax['infants'] ?? 0),
            ],
        ];

        // Extended subtotals
        $ptc = [];
        $sumBase = 0.0; $sumTax = 0.0;

        foreach ($unit as $code => $row) {
            $fare   = $row['fare'];
            $tax    = $row['tax'];
            $count  = max(0, (int)$row['count']);

            $totalPerPax = $fare + $tax;
            $baseSub     = $fare * $count;
            $taxSub      = $tax  * $count;
            $subTotal    = $baseSub + $taxSub;

            $sumBase += $baseSub;
            $sumTax  += $taxSub;

            $ptc[$code] = [
                'count'        => $count,
                'unit'         => [
                    'fare'  => self::rnd($fare),
                    'tax'   => self::rnd($tax),
                    'total' => self::rnd($totalPerPax),
                ],
                'subtotal'     => [
                    'base'  => self::rnd($baseSub),
                    'taxes' => self::rnd($taxSub),
                    'total' => self::rnd($subTotal),
                ],
            ];
        }

        // Solution-level fees (one-off for the whole cart/solution)
        $fees = [
            'qCharge'            => self::num($sol['qCharge']            ?? 0),
            'tktFee'             => self::num($sol['tktFee']             ?? 0),
            'platformServiceFee' => self::num($sol['platformServiceFee'] ?? 0),
            'merchantFee'        => self::num($sol['merchantFee']        ?? 0),
        ];
        $sumFees = array_sum($fees);

        // Grand totals
        $grandBase  = $sumBase;
        $grandTax   = $sumTax;
        $grandTotal = $grandBase + $grandTax + $sumFees;

        // If supplier exposes a rich 'prices' node in the future, pass it through raw for auditing.
        $pricesRaw = $sol['prices'] ?? null;

        return [
            'currency' => $currency,

            // Per PTC section (ADT / CHD / INF)
            'perPassenger' => $ptc,

            // Solution-level fees (not per-pax)
            'fees' => [
                'items' => array_map([self::class, 'rnd'], $fees),
                'total' => self::rnd($sumFees),
            ],

            // Totals (all passengers + fees)
            'totals' => [
                'base'   => self::rnd($grandBase),
                'taxes'  => self::rnd($grandTax),
                'fees'   => self::rnd($sumFees),
                'grand'  => self::rnd($grandTotal),
            ],

            // Keep raw source if present (null otherwise) for audit/debug
            'source' => [
                'pricesRaw' => $pricesRaw,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Other helpers
    // ---------------------------------------------------------------------

    /** Convert epoch ms to ISO-8601 if present. */
    private static function dtMs($ms): ?string
    {
        return is_numeric($ms) ? date(DATE_ATOM, ((int)$ms) / 1000) : null;
    }

    /**
     * Parse a value that might be epoch ms or "YYYY-mm-dd HH:ii:ss".
     * Returns ISO-8601 or null.
     */
    private static function parseDateFlex($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            return date(DATE_ATOM, ((int)$value) / 1000);
        }
        $ts = strtotime((string)$value);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }

    /** Cast numeric-like to float safely. */
    private static function num($v): float
    {
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /** Round for display (2 d.p.). */
    private static function rnd($v): float
    {
        return round((float)$v, 2);
    }

    /**
     * Inject ISO helpers into a PKFare segment, preserving raw fields.
     */
    private static function withIsoTimes(array $seg): array
    {
        $seg['departureIso'] = self::dtMs($seg['departureDate'] ?? null);
        $seg['arrivalIso']   = self::dtMs($seg['arrivalDate'] ?? null);
        return $seg;
    }

    /**
     * Map baggage to segmentIds via PKFare's 1-based global segment indexes.
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,array<string,mixed>>}
     */
    private static function mapBaggageByGlobalIndex(array $adtBlocks, array $globalIdxToSegId): array
    {
        $adtChecked = [];
        $adtCarry   = [];

        foreach ($adtBlocks as $b) {
            $indices = (array)($b['segmentIndexList'] ?? []);
            foreach ($indices as $n) {
                $sid = $globalIdxToSegId[$n] ?? null;
                if (!$sid) continue;

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

        return [$adtChecked, $adtCarry];
    }

    /**
     * Map mini rules to segmentIds via PKFare's 1-based global segment indexes.
     */
    private static function mapMiniRulesByGlobalIndex(array $adtBlocks, array $globalIdxToSegId): array
    {
        $rulesADT = [];
        foreach ($adtBlocks as $block) {
            $ids = [];
            foreach ((array)($block['segmentIndex'] ?? []) as $n) {
                $sid = $globalIdxToSegId[$n] ?? null;
                if ($sid) $ids[] = $sid;
            }
            $miniRules = array_map(static function ($r) {
                $label = match ($r['penaltyType'] ?? -1) {
                    0       => 'Refund',
                    1       => 'Change',
                    2       => 'No-show',
                    3       => 'Reissue / Reroute',
                    default => 'Penalty',
                };
                $r['label'] = $label;
                return $r;
            }, (array)($block['miniRules'] ?? []));

            $rulesADT[] = [
                'segmentIds' => $ids,
                'miniRules'  => $miniRules,
            ];
        }
        return $rulesADT;
    }

    /**
     * Materialize ordered segment IDs into full segment objects, injecting flightId and ISO helpers.
     */
    private static function segmentsFromIds(array $ids, array $segmentsById, array $flightsById): array
    {
        // Build segId -> flightId map from flights
        $segToFlight = [];
        foreach ($flightsById as $fid => $flight) {
            foreach ((array)($flight['segmengtIds'] ?? []) as $sid) {
                $segToFlight[$sid] = $fid;
            }
        }

        $out = [];
        foreach ($ids as $sid) {
            if (!isset($segmentsById[$sid])) continue;
            $seg = $segmentsById[$sid];
            $seg['flightId'] = $segToFlight[$sid] ?? null;
            $out[] = self::withIsoTimes($seg);
        }
        return $out;
    }
}
